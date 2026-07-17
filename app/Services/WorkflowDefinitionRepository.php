<?php

namespace App\Services;

use App\Models\ComplianceProgram;
use App\Models\WorkflowDefinition;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only lookup over a program's workflow_definitions /
 * workflow_stage_definitions / workflow_transition_definitions rows —
 * the data WorkflowService consults instead of the old hardcoded
 * NEXT_STAGE/STATUS_FOR_STAGE PHP constants. See docs/workflow-engine.md.
 *
 * Definitions rarely change (an admin editing a workflow is a rare,
 * deliberate action, not a per-request occurrence), so the resolved
 * stage/transition data is cached per program. Deliberately cached as a
 * plain array, NOT the Eloquent models directly: caching Eloquent models
 * (with loaded relations) through a serializing cache store (the
 * `database` driver, used outside automated tests, which run with the
 * non-serializing `array` driver and never exercised this path) can fail
 * unserialization with `__PHP_Incomplete_Class` — confirmed while seeding
 * the Phase 4 E2E environment. Plain arrays have no such risk.
 */
class WorkflowDefinitionRepository
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return array{stages: array<string, array>, transitions: array<int, array{from: string, action: string, to: string}>}|null
     */
    public function definition(ComplianceProgram $program, string $key = 'requirement_review'): ?array
    {
        return Cache::remember(
            "workflow_definition.{$program->id}.{$key}",
            self::CACHE_TTL_SECONDS,
            function () use ($program, $key) {
                $definition = WorkflowDefinition::where('compliance_program_id', $program->id)
                    ->where('key', $key)
                    ->where('is_active', true)
                    ->with('stages', 'transitions')
                    ->first();

                if (! $definition) {
                    return null;
                }

                return [
                    'stages' => $definition->stages->keyBy('stage_key')->map(fn ($s) => [
                        'stage_key' => $s->stage_key,
                        'responsible_role_key' => $s->responsible_role_key,
                        'requires_comment' => $s->requires_comment,
                        'requires_rejection_reason' => $s->requires_rejection_reason,
                        'sla_applies' => $s->sla_applies,
                        'notifications_enabled' => $s->notifications_enabled,
                        'is_final' => $s->is_final,
                    ])->all(),
                    'transitions' => $definition->transitions->map(fn ($t) => [
                        'from' => $t->from_stage_key, 'action' => $t->action, 'to' => $t->to_stage_key,
                    ])->all(),
                ];
            }
        );
    }

    /** The stage definition (as an array) for one stage key, or null if the workflow has no such stage. */
    public function stage(ComplianceProgram $program, string $stageKey, string $workflowKey = 'requirement_review'): ?array
    {
        return $this->definition($program, $workflowKey)['stages'][$stageKey] ?? null;
    }

    /**
     * The stage a (fromStage, action) transition leads to, or null if that
     * transition is not defined — meaning it is invalid and the caller
     * (WorkflowService) must reject it, exactly as the old hardcoded map
     * implicitly rejected anything not listed in it.
     */
    public function nextStage(ComplianceProgram $program, string $fromStage, string $action, string $workflowKey = 'requirement_review'): ?string
    {
        $transitions = $this->definition($program, $workflowKey)['transitions'] ?? [];

        foreach ($transitions as $transition) {
            if ($transition['from'] === $fromStage && $transition['action'] === $action) {
                return $transition['to'];
            }
        }

        return null;
    }

    public function forgetCache(ComplianceProgram $program, string $workflowKey = 'requirement_review'): void
    {
        Cache::forget("workflow_definition.{$program->id}.{$workflowKey}");
    }
}
