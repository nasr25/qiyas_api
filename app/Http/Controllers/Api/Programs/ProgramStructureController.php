<?php

namespace App\Http\Controllers\Api\Programs;

use App\Exceptions\InvalidHierarchyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\HierarchyLevelResource;
use App\Models\ComplianceProgram;
use App\Models\HierarchyDefinition;
use App\Models\HierarchyLevelDefinition;
use App\Models\ProgramStructureVersion;
use App\Models\User;
use App\Policies\HierarchyStructurePolicy;
use App\Services\AuditService;
use App\Services\HierarchyDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program Structure Settings — the Program Manager's control over their own
 * program's hierarchy shape and terminology.
 *
 * Replaces the seeder-only `hierarchy` configuration blob audited as
 * finding C4 in docs/compliance-hierarchy-audit.md. Every write goes
 * through HierarchyDefinitionService, so the validation, impact
 * classification and active-cycle protections cannot be bypassed by
 * calling the API directly rather than using the UI.
 *
 * Authorization is enforced on every write via HierarchyStructurePolicy:
 * a Program Manager may manage only programs they are assigned to.
 */
class ProgramStructureController extends Controller
{
    public function __construct(private readonly HierarchyDefinitionService $structures) {}

    /** GET /programs/{program}/structure — the live, active structure. */
    public function show(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeView($request->user(), $program);

        $definition = $this->structures->activeDefinition($program);
        $version = $this->structures->currentStructureVersion($program);

        return response()->json([
            'success' => true,
            'data' => [
                'program' => ['code' => $program->code, 'name' => $program->name],
                'definition' => $definition ? $this->definitionPayload($definition) : null,
                'structure_version' => $version?->version,
                'has_draft' => (bool) $this->structures->draftDefinition($program),
                'can_manage' => app(HierarchyStructurePolicy::class)->manage($request->user(), $program),
                'max_levels' => HierarchyDefinitionService::MAX_LEVELS,
            ],
        ]);
    }

    /** GET /programs/{program}/structure/draft */
    public function showDraft(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $draft = $this->structures->draftDefinition($program);

        return response()->json([
            'success' => true,
            'data' => $draft ? [
                ...$this->definitionPayload($draft),
                'validation_errors' => $this->structures->validateDraft($draft),
            ] : null,
        ]);
    }

    /** POST /programs/{program}/structure/draft — open (or return) a draft. */
    public function openDraft(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $draft = $this->structures->openDraft($program, $request->user());

        return response()->json(['success' => true, 'data' => $this->definitionPayload($draft)], 201);
    }

    /** DELETE /programs/{program}/structure/draft — discard without activating. */
    public function discardDraft(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);
        $draft = $this->requireDraft($program);

        $this->structures->discardDraft($draft, $request->user());

        return response()->json(['success' => true, 'data' => null]);
    }

    /** POST /programs/{program}/structure/draft/levels */
    public function addLevel(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);
        $draft = $this->requireDraft($program);

        $data = $request->validate($this->levelRules(required: true));

        return $this->guard(function () use ($draft, $data, $request) {
            $level = $this->structures->addLevel($draft, $data, $request->user());

            return response()->json(['success' => true, 'data' => new HierarchyLevelResource($level)], 201);
        });
    }

    /** PUT /programs/{program}/structure/draft/levels/{level} */
    public function updateLevel(Request $request, string $program, int $level): JsonResponse
    {
        $resolved = $this->program($request);
        $this->authorizeManage($request->user(), $resolved);
        $model = $this->findScopedLevel($resolved, $level);

        $data = $request->validate($this->levelRules(required: false));

        return $this->guard(function () use ($model, $data, $request) {
            $updated = $this->structures->updateLevel($model, $data, $request->user());

            return response()->json(['success' => true, 'data' => new HierarchyLevelResource($updated)]);
        });
    }

    /** POST /programs/{program}/structure/draft/levels/{level}/move */
    public function moveLevel(Request $request, string $program, int $level): JsonResponse
    {
        $resolved = $this->program($request);
        $this->authorizeManage($request->user(), $resolved);
        $model = $this->findScopedLevel($resolved, $level);

        $data = $request->validate(['direction' => ['required', 'string', 'in:up,down']]);

        return $this->guard(function () use ($model, $data, $request) {
            $definition = $this->structures->moveLevel($model, $data['direction'], $request->user());

            return response()->json(['success' => true, 'data' => $this->definitionPayload($definition)]);
        });
    }

    /** DELETE /programs/{program}/structure/draft/levels/{level} — remove from the draft. */
    public function removeLevel(Request $request, string $program, int $level): JsonResponse
    {
        $resolved = $this->program($request);
        $this->authorizeManage($request->user(), $resolved);
        $model = $this->findScopedLevel($resolved, $level);

        if (! $model->definition->isEditable()) {
            return response()->json(['success' => false, 'message' => 'Only a draft structure can be edited.'], 422);
        }

        $definition = $model->definition;
        $key = $model->key;
        $model->delete();

        // Deleting from the middle would leave a gap and a dangling parent
        // link, so the chain is always rebuilt afterwards.
        $this->structures->normalise($definition->fresh(), $request->user());

        AuditService::log(
            'hierarchy_structure.level_removed',
            "Removed level '{$key}' from draft v{$definition->version}",
            $definition,
            complianceProgramId: $resolved->id,
        );

        return response()->json(['success' => true, 'data' => $this->definitionPayload($definition->fresh())]);
    }

    /** GET /programs/{program}/structure/draft/impact — what activation would touch. */
    public function impact(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);
        $draft = $this->requireDraft($program);

        return response()->json(['success' => true, 'data' => $this->structures->previewImpact($draft)]);
    }

    /** POST /programs/{program}/structure/draft/activate */
    public function activate(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);
        $draft = $this->requireDraft($program);

        $data = $request->validate([
            'acknowledge_migration' => ['sometimes', 'boolean'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! empty($data['change_summary'])) {
            $draft->update(['change_summary' => $data['change_summary']]);
        }

        return $this->guard(function () use ($draft, $request, $data) {
            $activated = $this->structures->activate(
                $draft->fresh(),
                $request->user(),
                (bool) ($data['acknowledge_migration'] ?? false),
            );

            return response()->json(['success' => true, 'data' => $this->definitionPayload($activated)]);
        });
    }

    /** GET /programs/{program}/structure/versions — frozen history. */
    public function versions(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeView($request->user(), $program);

        $versions = ProgramStructureVersion::forProgram($program)
            ->orderByDesc('version')
            ->get()
            ->map(fn (ProgramStructureVersion $v) => [
                'id' => $v->id,
                'version' => $v->version,
                'status' => $v->status,
                'activated_at' => $v->activated_at?->toIso8601String(),
                'change_summary' => $v->change_summary,
                'level_count' => count($v->levels()),
                'levels' => collect($v->levels())->map(fn ($l) => [
                    'key' => $l['key'],
                    'name_ar' => $l['name_ar'],
                    'name_en' => $l['name_en'],
                    'level_order' => $l['level_order'],
                ])->values(),
            ]);

        return response()->json(['success' => true, 'data' => $versions]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Field rules mirror hierarchy_level_definitions exactly. `key` is a
     * stable machine identifier and is deliberately immutable after
     * creation — display names are what a manager renames.
     */
    private function levelRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        $rules = [
            'name_ar' => [$presence, 'string', 'max:100'],
            'name_en' => [$presence, 'string', 'max:100'],
            'plural_name_ar' => ['nullable', 'string', 'max:100'],
            'plural_name_en' => ['nullable', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:50'],
        ];

        if ($required) {
            $rules['key'] = ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'];
        }

        foreach (HierarchyLevelDefinition::BEHAVIOUR_FLAGS as $flag) {
            $rules[$flag] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    private function definitionPayload(HierarchyDefinition $definition): array
    {
        return [
            'id' => $definition->id,
            'version' => $definition->version,
            'status' => $definition->status,
            'name_ar' => $definition->name_ar,
            'name_en' => $definition->name_en,
            'change_summary' => $definition->change_summary,
            'activated_at' => $definition->activated_at?->toIso8601String(),
            'depth' => $definition->levels()->count(),
            'levels' => HierarchyLevelResource::collection($definition->levels()->get())->resolve(),
        ];
    }

    private function requireDraft(ComplianceProgram $program): HierarchyDefinition
    {
        $draft = $this->structures->draftDefinition($program);
        abort_unless($draft, 404, 'No structure draft is open for this program.');

        return $draft;
    }

    private function findScopedLevel(ComplianceProgram $program, int $levelId): HierarchyLevelDefinition
    {
        // Scoped by program, so a level id belonging to another program's
        // structure can never be reached by guessing an id.
        $level = HierarchyLevelDefinition::where('id', $levelId)
            ->where('compliance_program_id', $program->id)
            ->first();
        abort_unless($level, 404, 'Hierarchy level not found.');

        return $level;
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorizeView(User $user, ComplianceProgram $program): void
    {
        abort_unless(app(HierarchyStructurePolicy::class)->view($user, $program), 403, 'You may not view this program structure.');
    }

    private function authorizeManage(User $user, ComplianceProgram $program): void
    {
        abort_unless(
            app(HierarchyStructurePolicy::class)->manage($user, $program),
            403,
            'Only the Program Manager of this program may change its structure.',
        );
    }

    /** Converts an engine rule violation into a 422 rather than a 500. */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (InvalidHierarchyException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
