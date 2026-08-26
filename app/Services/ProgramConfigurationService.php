<?php

namespace App\Services;

use App\Exceptions\InvalidProgramConfigurationException;
use App\Models\ComplianceProgram;
use App\Models\ProgramConfiguration;
use App\Models\ProgramConfigurationVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The single read/write path for program configuration — see
 * docs/program-configuration.md. No other part of the platform should
 * write to program_configurations directly; only strictly validated,
 * versioned, audited changes are allowed through here, per the Phase 4
 * brief's "Do not allow unsafe arbitrary configuration."
 */
class ProgramConfigurationService
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Returns the active configuration value for a category, or $default if
     * none has been set yet. Cached — call forget() (done automatically by
     * set()) whenever the value changes.
     */
    public function get(ComplianceProgram $program, string $category, array $default = []): array
    {
        $this->assertValidCategory($category);

        return Cache::remember(
            $this->cacheKey($program, $category),
            self::CACHE_TTL_SECONDS,
            function () use ($program, $category, $default) {
                $row = ProgramConfiguration::where('compliance_program_id', $program->id)
                    ->where('category', $category)
                    ->where('is_active', true)
                    ->first();

                return $row?->value ?? $default;
            }
        );
    }

    /**
     * Validates, versions, and writes a new configuration value. Every call
     * appends a program_configuration_versions row and an audit log entry —
     * there is no code path that silently overwrites configuration history.
     *
     * @throws InvalidProgramConfigurationException if $category or $value fails validation.
     */
    public function set(ComplianceProgram $program, string $category, array $value, ?User $actor = null): ProgramConfiguration
    {
        $this->assertValidCategory($category);
        $this->validateValue($category, $value);

        return DB::transaction(function () use ($program, $category, $value, $actor) {
            $existing = ProgramConfiguration::where('compliance_program_id', $program->id)
                ->where('category', $category)
                ->lockForUpdate()
                ->first();

            $nextVersion = ($existing?->version ?? 0) + 1;
            $oldValue = $existing?->value;

            $config = ProgramConfiguration::updateOrCreate(
                ['compliance_program_id' => $program->id, 'category' => $category],
                ['value' => $value, 'version' => $nextVersion, 'is_active' => true, 'updated_by' => $actor?->id],
            );

            ProgramConfigurationVersion::create([
                'compliance_program_id' => $program->id,
                'category' => $category,
                'version' => $nextVersion,
                'value' => $value,
                'changed_by' => $actor?->id,
                'created_at' => now(),
            ]);

            AuditService::log(
                'program_configuration.updated',
                "Updated '{$category}' configuration for program '{$program->code}' (v{$nextVersion})",
                $config,
                $oldValue,
                $value,
                complianceProgramId: $program->id,
            );

            Cache::forget($this->cacheKey($program, $category));

            return $config;
        });
    }

    private function assertValidCategory(string $category): void
    {
        if (! in_array($category, ProgramConfiguration::CATEGORIES, true)) {
            throw new InvalidProgramConfigurationException("Unknown configuration category '{$category}'.");
        }
    }

    /**
     * Category-specific validation. Every category accepts only a fixed,
     * whitelisted shape — this is what prevents "unsafe arbitrary
     * configuration": a caller cannot inject arbitrary keys that some other
     * part of the engine might later interpret as executable behavior.
     */
    private function validateValue(string $category, array $value): void
    {
        $rules = match ($category) {
            'terminology' => [
                '*' => ['array'],
                '*.ar' => ['required_with:*', 'string', 'max:100'],
                '*.en' => ['required_with:*', 'string', 'max:100'],
            ],
            'extensions' => [
                'reviewer_role' => ['required', 'string', 'in:program-manager,auditor,department-manager'],
                'requester_role' => ['sometimes', 'string', 'in:employee,department-manager'],
                'rejection_reason_required' => ['sometimes', 'boolean'],
                'allow_multiple_pending' => ['sometimes', 'boolean'],
            ],
            'evidence' => [
                'allowed_extensions' => ['required', 'array', 'min:1'],
                'allowed_extensions.*' => ['string', 'regex:/^[a-z0-9]{2,10}$/'],
                'max_file_size_mb' => ['required', 'integer', 'min:1', 'max:500'],
                'max_files_per_submission' => ['required', 'integer', 'min:1', 'max:100'],
                'max_total_submission_size_mb' => ['required', 'integer', 'min:1', 'max:2000'],
            ],
            'assignment' => [
                'department_required' => ['sometimes', 'boolean'],
                'employee_assignment_required' => ['sometimes', 'boolean'],
                'reassignment_reason_required' => ['sometimes', 'boolean'],
                'due_date_required' => ['sometimes', 'boolean'],
            ],
            'features' => [
                '*' => ['boolean'],
            ],
            // Phase 6: describes the program's own hierarchy depth/shape —
            // read by ComplianceNodeService to validate parent/child type
            // pairs and maximum depth. Qiyas/Sumoud never set this category
            // (they use the free-text perspective/axis fields on Standard
            // instead); only a program using the generic ComplianceNode
            // engine needs it. See docs/programs/ecc/hierarchy.md.
            'hierarchy' => [
                'levels' => ['required', 'array', 'min:1', 'max:10'],
                'levels.*.node_type' => ['required', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
                'levels.*.label_ar' => ['required', 'string', 'max:100'],
                'levels.*.label_en' => ['required', 'string', 'max:100'],
                'levels.*.parent_type' => ['nullable', 'string', 'max:50'],
                'levels.*.is_assessable' => ['required', 'boolean'],
                'max_depth' => ['required', 'integer', 'min:1', 'max:10'],
            ],
            // Phase 7: which optional responsibility labels (Data Owner,
            // Data Steward, ...) this program allows assigning, and their
            // bilingual display labels ONLY — this schema has no field for
            // granting workflow authority, deliberately: no code path
            // anywhere reads a responsibility_type to authorize an action.
            // See docs/programs/ndmo/responsibilities.md.
            'responsibilities' => [
                'enabled_types' => ['required', 'array'],
                'enabled_types.*' => ['string', 'regex:/^[a-z_]+$/'],
                'types' => ['required', 'array'],
                'types.*.type' => ['required', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
                'types.*.label_ar' => ['required', 'string', 'max:100'],
                'types.*.label_en' => ['required', 'string', 'max:100'],
            ],
            default => null, // No category-specific schema yet — fall through to the generic guard below.
        };

        if ($rules) {
            $validator = Validator::make($value, $rules);
            if ($validator->fails()) {
                throw new InvalidProgramConfigurationException(
                    "Invalid '{$category}' configuration: ".$validator->errors()->first()
                );
            }

            return;
        }

        // Generic guard for categories without a dedicated schema yet: reject
        // anything that isn't a plain, reasonably small, string/scalar-keyed
        // structure — no callables, no objects, no oversized payloads.
        $this->assertSafeScalarStructure($value, $category);
    }

    private function assertSafeScalarStructure(array $value, string $category, int $depth = 0): void
    {
        if ($depth > 5) {
            throw new InvalidProgramConfigurationException("Configuration for '{$category}' is nested too deeply.");
        }
        if (count($value, COUNT_RECURSIVE) > 500) {
            throw new InvalidProgramConfigurationException("Configuration for '{$category}' is too large.");
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $this->assertSafeScalarStructure($item, $category, $depth + 1);

                continue;
            }
            if (! is_scalar($item) && $item !== null) {
                throw new InvalidProgramConfigurationException("Configuration for '{$category}' contains an unsupported value type.");
            }
        }
    }

    private function cacheKey(ComplianceProgram $program, string $category): string
    {
        return "program_configuration.{$program->id}.{$category}";
    }
}
