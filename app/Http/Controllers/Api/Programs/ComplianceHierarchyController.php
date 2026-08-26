<?php

namespace App\Http\Controllers\Api\Programs;

use App\Exceptions\InvalidHierarchyException;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ComplianceNodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic, arbitrary-depth hierarchy authoring — the ONLY way compliance
 * content is created in the platform. Every program uses these endpoints at
 * whatever depth its structure defines; there is no per-program variant and
 * no legacy Standard path any more.
 *
 * See docs/dynamic-compliance-structure.md.
 */
class ComplianceHierarchyController extends Controller
{
    public function __construct(private readonly ComplianceNodeService $nodes) {}

    /** GET /api/v1/programs/{program}/hierarchy-levels */
    public function levels(Request $request): JsonResponse
    {
        $program = $this->program($request);

        return response()->json(['success' => true, 'data' => $this->nodes->levelDefinitions($program)]);
    }

    /** GET /api/v1/programs/{program}/hierarchy?parent_id=&cycle_id= */
    public function index(Request $request): JsonResponse
    {
        $program = $this->program($request);

        $query = ComplianceNode::forProgram($program)->with('hierarchyLevel')
            ->where('status', '!=', 'archived');
        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } else {
            $query->whereNull('parent_id');
        }
        if ($request->filled('cycle_id')) {
            $query->where('program_cycle_id', $request->cycle_id);
        }

        $children = $query->orderBy('sort_order')->orderBy('code')
            ->withCount('children')->get();

        return response()->json([
            'success' => true,
            'data' => $children->map(fn (ComplianceNode $n) => $this->summarize($n))->values(),
        ]);
    }

    /** GET /api/v1/programs/{program}/hierarchy/{node} */
    public function show(Request $request, string $program, int|string $node): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $nodeModel = $this->findScoped($resolvedProgram, $node);

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->summarize($nodeModel->loadCount('children')),
                'ancestors' => collect($nodeModel->ancestors())->map(fn (ComplianceNode $a) => $this->summarize($a))->values(),
            ],
        ]);
    }

    /** POST /api/v1/programs/{program}/hierarchy */
    public function store(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $data = $request->validate([
            'node_type' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:100'],
            'name_ar' => ['required', 'string', 'max:500'],
            'name_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'guidance_ar' => ['nullable', 'string'],
            'guidance_en' => ['nullable', 'string'],
            'evidence_requirements_ar' => ['nullable', 'string'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'due_date' => ['nullable', 'date'],
            'parent_id' => ['nullable', 'integer'],
            'cycle_id' => ['required', 'integer'],
        ]);

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = $this->findScoped($program, $data['parent_id']);
        }

        $cycle = AssessmentCycle::where('id', $data['cycle_id'])->where('compliance_program_id', $program->id)->first();
        if (! $cycle) {
            return response()->json(['success' => false, 'message' => 'Cycle not found.'], 404);
        }
        $contentVersion = $cycle->content_version_id ? ComplianceContentVersion::find($cycle->content_version_id) : null;

        $levelDefinition = collect($this->nodes->levelDefinitions($program))->firstWhere('node_type', $data['node_type']);
        if (! $levelDefinition) {
            return response()->json(['success' => false, 'message' => "Node type '{$data['node_type']}' is not defined in this program's hierarchy configuration."], 422);
        }

        try {
            if ($levelDefinition['is_assessable'] ?? false) {
                if (! $parent) {
                    return response()->json(['success' => false, 'message' => 'An assessable node requires a parent.'], 422);
                }
                $node = $this->nodes->createAssessableNode($program, $data['node_type'], $data['code'], $data['name_ar'], $parent, $cycle, $contentVersion, $request->user(), $data);
            } else {
                $node = $this->nodes->createNode($program, $data['node_type'], $data['code'], $data['name_ar'], $parent, $cycle, $contentVersion, $request->user(), $data);
            }
        } catch (InvalidHierarchyException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($node)], 201);
    }

    /** PUT /api/v1/programs/{program}/hierarchy/{node} */
    public function update(Request $request, string $program, int|string $node): JsonResponse
    {
        $resolved = $this->program($request);
        $this->authorizeManage($request->user(), $resolved);
        $model = $this->findScoped($resolved, $node);

        // Only fields the node's LEVEL enables may be written — the same
        // rule the dynamic form renders from, enforced on the backend.
        $level = $model->hierarchyLevel;
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:500'],
            'name_en' => ['nullable', 'string', 'max:500'],
            'code' => ['sometimes', 'string', 'max:100'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'objective_ar' => ['nullable', 'string'],
            'objective_en' => ['nullable', 'string'],
            'guidance_ar' => ['nullable', 'string'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_due_date' => ['nullable', 'date'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        foreach ([
            'description_ar' => 'description_enabled', 'description_en' => 'description_enabled',
            'objective_ar' => 'objective_enabled', 'objective_en' => 'objective_enabled',
            'guidance_ar' => 'instructions_enabled',
            'weight' => 'weight_enabled', 'default_due_date' => 'due_date_enabled',
        ] as $field => $flag) {
            if (array_key_exists($field, $data) && $level && ! $level->{$flag}) {
                unset($data[$field]);
            }
        }

        $old = $model->only(array_keys($data));
        $model->update([...$data, 'updated_by' => $request->user()->id]);

        AuditService::log(
            'compliance_node.updated',
            "Hierarchy node '{$model->code}' updated",
            $model, $old, $model->fresh()->only(array_keys($data)), $resolved->id,
        );

        return response()->json(['success' => true, 'data' => $this->summarize($model->fresh())]);
    }

    /**
     * POST /api/v1/programs/{program}/hierarchy/{node}/archive
     *
     * Archiving rather than deleting: a node may already carry assignments
     * and evidence, and destroying that history to tidy a tree would be the
     * wrong trade. Archived nodes drop out of authoring lists but remain
     * resolvable from existing records.
     */
    public function archive(Request $request, string $program, int|string $node): JsonResponse
    {
        $resolved = $this->program($request);
        $this->authorizeManage($request->user(), $resolved);
        $model = $this->findScoped($resolved, $node);

        if ($model->children()->where('status', '!=', 'archived')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Archive or move this node\'s children first.',
            ], 422);
        }

        $model->update(['status' => 'archived', 'archived_at' => now(), 'updated_by' => $request->user()->id]);

        AuditService::log(
            'compliance_node.archived',
            "Hierarchy node '{$model->code}' archived",
            $model, complianceProgramId: $resolved->id,
        );

        return response()->json(['success' => true, 'data' => $this->summarize($model->fresh())]);
    }

    /** GET /api/v1/programs/{program}/hierarchy/search?q=&cycle_id= */
    public function search(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json(['success' => true, 'data' => []]);
        }

        $nodes = ComplianceNode::forProgram($program)
            ->when($request->filled('cycle_id'), fn ($q) => $q->where('program_cycle_id', $request->query('cycle_id')))
            ->where(fn ($q) => $q->where('code', 'like', "%{$term}%")
                ->orWhere('name_ar', 'like', "%{$term}%")
                ->orWhere('name_en', 'like', "%{$term}%"))
            ->with('hierarchyLevel')
            ->orderBy('level')->orderBy('code')
            ->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => $nodes->map(fn (ComplianceNode $n) => [
                ...$this->summarize($n),
                'path' => $n->breadcrumb(),
            ])->values(),
        ]);
    }

    /** GET /api/v1/programs/{program}/content-versions */
    public function contentVersions(Request $request): JsonResponse
    {
        $program = $this->program($request);

        $versions = ComplianceContentVersion::forProgram($program)->latest('id')->get();

        return response()->json(['success' => true, 'data' => $versions]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function summarize(ComplianceNode $node): array
    {
        return [
            'id' => $node->id,
            'parent_id' => $node->parent_id,
            'node_type' => $node->node_type,
            'level' => $node->level,
            'code' => $node->code,
            'name_ar' => $node->name_ar,
            'name_en' => $node->name_en,
            'name' => $node->name,
            'level_key' => $node->hierarchyLevel?->key,
            'level_name' => $node->hierarchyLevel?->name,
            'description_ar' => $node->description_ar,
            'objective_ar' => $node->objective_ar,
            'guidance_ar' => $node->guidance_ar,
            'weight' => $node->weight,
            'default_due_date' => $node->default_due_date?->toDateString(),
            'is_assessable' => $node->isAssessable(),
            'is_assignable' => $node->isAssignable(),
            'accepts_evidence' => $node->acceptsEvidence(),
            'status' => $node->status,
            'children_count' => $node->children_count ?? null,
        ];
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorizeManage(User $user, ComplianceProgram $program): void
    {
        abort_unless($user->isPlatformSuperAdmin() || $user->hasProgramRole($program, 'program-manager'), 403, 'Only the Program Manager can manage the hierarchy.');
    }

    private function findScoped(ComplianceProgram $program, int|string $id): ComplianceNode
    {
        $node = ComplianceNode::where('id', $id)->where('compliance_program_id', $program->id)->first();
        abort_unless($node, 404, 'Hierarchy node not found.');

        return $node;
    }
}
