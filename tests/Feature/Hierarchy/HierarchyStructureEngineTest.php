<?php

namespace Tests\Feature\Hierarchy;

use App\Exceptions\InvalidHierarchyException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\HierarchyDefinition;
use App\Models\HierarchyLevelDefinition;
use App\Models\ProgramStructureVersion;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the hierarchy engine carries no fixed-depth and no fixed-terminology
 * assumption — the central claim of docs/compliance-hierarchy-audit.md
 * findings C2, C4, C5, H4, H5 and H7.
 *
 * The depth tests below are deliberately written as a loop over an arbitrary
 * N rather than as three hand-written fixtures: if any code path anywhere
 * assumed a level count, a generated N-level structure would fail where a
 * hand-tuned one might accidentally pass.
 */
class HierarchyStructureEngineTest extends TestCase
{
    use RefreshDatabase;

    private ComplianceProgram $program;

    private User $actor;

    private HierarchyDefinitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->program = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();
        $this->actor = $this->makeUser('super-admin');
        $this->service = app(HierarchyDefinitionService::class);
    }

    /** Builds and activates an N-level structure; returns the active definition. */
    private function buildStructure(int $depth, ?ComplianceProgram $program = null): HierarchyDefinition
    {
        $program ??= $this->program;
        $draft = $this->service->openDraft($program, $this->actor);

        for ($i = 1; $i <= $depth; $i++) {
            $isLeaf = $i === $depth;
            $this->service->addLevel($draft, [
                'key' => "level_{$i}",
                'name_ar' => "المستوى {$i}",
                'name_en' => "Level {$i}",
                'plural_name_ar' => "المستويات {$i}",
                'plural_name_en' => "Level {$i}s",
                'is_active' => true,
                'is_assessable' => $isLeaf,
                'is_assignable' => $isLeaf,
                'accepts_evidence' => $isLeaf,
                'appears_in_dashboard' => ! $isLeaf,
                'appears_in_reports' => true,
                'appears_in_filters' => ! $isLeaf,
                'appears_in_breadcrumb' => true,
            ], $this->actor);
        }

        return $this->service->activate($draft->fresh(), $this->actor);
    }

    // ─── Depth independence ──────────────────────────────────────────────────

    /**
     * The brief's three mandated fixtures. A single data provider covers all
     * of them so no depth can be special-cased.
     */
    #[DataProvider('depthProvider')]
    public function test_a_program_can_define_a_hierarchy_of_arbitrary_depth(int $depth): void
    {
        $definition = $this->buildStructure($depth);

        $this->assertSame('active', $definition->status);
        $this->assertCount($depth, $definition->levels()->get());
        $this->assertSame($depth, $definition->depth());

        // Order is contiguous and the parent chain is linear.
        $levels = $definition->levels()->get();
        $this->assertSame(range(1, $depth), $levels->pluck('level_order')->all());
        $this->assertNull($levels->first()->parent_level_id);
        for ($i = 1; $i < $depth; $i++) {
            $this->assertSame(
                $levels[$i - 1]->id,
                $levels[$i]->parent_level_id,
                "Level {$i} must be parented to the level above it.",
            );
        }

        // The structure passes its own integrity validation at every depth.
        $this->assertSame([], $this->service->validateDraft($definition));
    }

    public static function depthProvider(): array
    {
        return [
            '3 levels' => [3],
            '5 levels' => [5],
            '7 levels' => [7],
            '8 levels (brief acceptance criterion)' => [8],
            'maximum supported depth' => [HierarchyDefinitionService::MAX_LEVELS],
        ];
    }

    public function test_a_node_chain_can_be_built_to_the_full_configured_depth(): void
    {
        $depth = 7;
        $definition = $this->buildStructure($depth);
        $levels = $definition->levels()->get();
        $version = $this->service->currentStructureVersion($this->program);

        $parent = null;
        $created = [];
        foreach ($levels as $index => $level) {
            $parent = ComplianceNode::create([
                'compliance_program_id' => $this->program->id,
                'hierarchy_level_id' => $level->id,
                'structure_version_id' => $version->id,
                'parent_id' => $parent?->id,
                'node_type' => $level->key,
                'level' => $index,
                'code' => "N{$index}",
                'name_ar' => "عقدة {$index}",
                'name_en' => "Node {$index}",
            ]);
            $created[] = $parent;
        }

        $deepest = end($created);
        $this->assertSame($depth - 1, $deepest->level);

        // Ancestor traversal returns the whole chain, not a truncated pair —
        // the defect behind audit finding C2.
        $ancestors = $deepest->ancestors();
        $this->assertCount($depth - 1, $ancestors);
        $this->assertSame('N0', $ancestors[0]->code);
    }

    public function test_depth_beyond_the_platform_ceiling_is_rejected(): void
    {
        $draft = $this->service->openDraft($this->program, $this->actor);
        for ($i = 1; $i <= HierarchyDefinitionService::MAX_LEVELS; $i++) {
            $this->service->addLevel($draft, ['key' => "l{$i}", 'name_ar' => "م{$i}", 'name_en' => "L{$i}", 'is_assessable' => true], $this->actor);
        }

        $this->expectException(InvalidHierarchyException::class);
        $this->service->addLevel($draft, ['key' => 'one_too_many', 'name_ar' => 'ز', 'name_en' => 'Extra'], $this->actor);
    }

    // ─── Terminology ─────────────────────────────────────────────────────────

    public function test_level_names_are_configurable_and_resolve_per_locale(): void
    {
        $this->buildStructure(3);

        // Terminology is data: re-open a draft, rename, activate.
        $draft = $this->service->openDraft($this->program, $this->actor);
        $first = $draft->levels()->get()->first();
        $this->service->updateLevel($first, ['name_ar' => 'المنظور', 'name_en' => 'Perspective'], $this->actor);
        $this->service->activate($draft->fresh(), $this->actor);

        $level = HierarchyLevelDefinition::find($first->id);

        app()->setLocale('ar');
        $this->assertSame('المنظور', $level->fresh()->name);

        app()->setLocale('en');
        $this->assertSame('Perspective', $level->fresh()->name);
    }

    public function test_display_name_falls_back_rather_than_rendering_empty(): void
    {
        $definition = $this->buildStructure(3);
        $level = $definition->levels()->get()->first();
        $level->forceFill(['name_en' => ''])->save();

        app()->setLocale('en');
        $this->assertSame($level->name_ar, $level->fresh()->name);
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_a_structure_with_no_assessable_level_cannot_be_activated(): void
    {
        $draft = $this->service->openDraft($this->program, $this->actor);
        $this->service->addLevel($draft, ['key' => 'only', 'name_ar' => 'أ', 'name_en' => 'Only', 'is_assessable' => false], $this->actor);

        $errors = $this->service->validateDraft($draft->fresh());
        $this->assertNotEmpty($errors);

        $this->expectException(InvalidHierarchyException::class);
        $this->service->activate($draft->fresh(), $this->actor);
    }

    public function test_an_evidence_level_that_is_not_assessable_is_rejected(): void
    {
        $draft = $this->service->openDraft($this->program, $this->actor);
        $this->service->addLevel($draft, ['key' => 'group', 'name_ar' => 'أ', 'name_en' => 'Group', 'is_assessable' => true], $this->actor);
        $this->service->addLevel($draft, ['key' => 'leaf', 'name_ar' => 'ب', 'name_en' => 'Leaf', 'is_assessable' => false, 'accepts_evidence' => true], $this->actor);

        $errors = $this->service->validateDraft($draft->fresh());
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'never reach a reviewer')));
    }

    // ─── Versioning and history protection (finding C5) ──────────────────────

    public function test_activation_freezes_an_immutable_structure_snapshot(): void
    {
        $definition = $this->buildStructure(4);
        $version = ProgramStructureVersion::forProgram($this->program)->active()->firstOrFail();

        $this->assertSame($definition->version, $version->version);
        $this->assertCount(4, $version->levels());
        $this->assertSame('Level 1', $version->levels()[0]['name_en']);
    }

    public function test_renaming_a_level_later_does_not_rewrite_the_frozen_snapshot(): void
    {
        $this->buildStructure(3);
        $frozen = ProgramStructureVersion::forProgram($this->program)->active()->firstOrFail();

        // A manager opens a new draft, renames level 1, and activates it.
        $draft = $this->service->openDraft($this->program, $this->actor);
        $first = $draft->levels()->get()->first();
        $this->service->updateLevel($first, ['name_en' => 'Completely Renamed'], $this->actor);
        $this->service->activate($draft->fresh(), $this->actor);

        // The OLD snapshot still says what it always said.
        $this->assertSame('Level 1', $frozen->fresh()->levels()[0]['name_en']);
        $this->assertSame('superseded', $frozen->fresh()->status);

        // And the new one reflects the change.
        $current = ProgramStructureVersion::forProgram($this->program)->active()->firstOrFail();
        $this->assertSame('Completely Renamed', $current->levels()[0]['name_en']);
        $this->assertSame(2, $current->version);
    }

    public function test_only_one_definition_and_one_snapshot_stay_active(): void
    {
        $this->buildStructure(3);
        $draft = $this->service->openDraft($this->program, $this->actor);
        $this->service->activate($draft->fresh(), $this->actor);

        $this->assertSame(1, HierarchyDefinition::forProgram($this->program)->active()->count());
        $this->assertSame(1, ProgramStructureVersion::forProgram($this->program)->active()->count());
    }

    public function test_an_active_structure_cannot_be_edited_directly(): void
    {
        $definition = $this->buildStructure(3);
        $level = $definition->levels()->get()->first();

        $this->expectException(InvalidHierarchyException::class);
        $this->service->updateLevel($level, ['name_en' => 'Sneaky'], $this->actor);
    }

    // ─── Reordering ──────────────────────────────────────────────────────────

    public function test_moving_a_level_rewrites_the_parent_chain_consistently(): void
    {
        $this->buildStructure(4);
        $draft = $this->service->openDraft($this->program, $this->actor);
        $levels = $draft->levels()->get();

        $this->service->moveLevel($levels[2], 'up', $this->actor);

        $after = $draft->fresh()->levels()->get();
        $this->assertSame(['level_1', 'level_3', 'level_2', 'level_4'], $after->pluck('key')->all());
        $this->assertSame(range(1, 4), $after->pluck('level_order')->all());
        $this->assertNull($after[0]->parent_level_id);
        $this->assertSame($after[0]->id, $after[1]->parent_level_id);
        $this->assertSame($after[1]->id, $after[2]->parent_level_id);
        $this->assertSame([], $this->service->validateDraft($draft->fresh()));
    }

    // ─── Impact preview and active-cycle protection ──────────────────────────

    public function test_structural_change_is_safe_when_the_program_has_no_content(): void
    {
        $this->buildStructure(3);
        $draft = $this->service->openDraft($this->program, $this->actor);
        $this->service->addLevel($draft, ['key' => 'extra', 'name_ar' => 'إ', 'name_en' => 'Extra', 'is_assessable' => true], $this->actor);

        $impact = $this->service->previewImpact($draft->fresh());

        $this->assertSame('safe', $impact['classification']);
        $this->assertFalse($impact['blocking']);
        $this->assertSame(['extra'], $impact['changes']['levels_added']);
    }

    public function test_inserting_a_level_during_an_active_cycle_requires_acknowledgement(): void
    {
        $definition = $this->buildStructure(3);
        $this->seedContent($definition);

        $draft = $this->service->openDraft($this->program, $this->actor);
        $this->service->addLevel($draft, ['key' => 'extra', 'name_ar' => 'إ', 'name_en' => 'Extra', 'is_assessable' => true], $this->actor);

        $impact = $this->service->previewImpact($draft->fresh());
        $this->assertSame('requires_migration', $impact['classification']);
        $this->assertGreaterThan(0, $impact['affected']['nodes']);
        $this->assertSame(1, $impact['affected']['active_cycles']);

        // Refused without acknowledgement...
        try {
            $this->service->activate($draft->fresh(), $this->actor);
            $this->fail('Expected activation to be refused without acknowledgement.');
        } catch (InvalidHierarchyException $e) {
            $this->assertStringContainsString('explicitly acknowledged', $e->getMessage());
        }

        // ...and permitted with it.
        $activated = $this->service->activate($draft->fresh(), $this->actor, acknowledgeMigration: true);
        $this->assertSame('active', $activated->status);
    }

    public function test_removing_a_populated_level_during_an_active_cycle_is_blocked(): void
    {
        $definition = $this->buildStructure(3);
        $this->seedContent($definition);

        $draft = $this->service->openDraft($this->program, $this->actor);
        // Drop the deepest level from the draft entirely.
        $draft->levels()->get()->last()->delete();

        $impact = $this->service->previewImpact($draft->fresh());
        $this->assertSame('not_allowed', $impact['classification']);
        $this->assertTrue($impact['blocking']);

        $this->expectException(InvalidHierarchyException::class);
        $this->service->activate($draft->fresh(), $this->actor, acknowledgeMigration: true);
    }

    /** Creates an active cycle plus one node per level, so impact counts are real. */
    private function seedContent(HierarchyDefinition $definition): void
    {
        $cycle = AssessmentCycle::create([
            'compliance_program_id' => $this->program->id,
            'name' => 'دورة اختبار', 'year' => 2026,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
            'status' => 'active', 'is_current' => true,
            'created_by' => $this->actor->id,
        ]);

        $parent = null;
        foreach ($definition->levels()->get() as $index => $level) {
            $parent = ComplianceNode::create([
                'compliance_program_id' => $this->program->id,
                'program_cycle_id' => $cycle->id,
                'hierarchy_level_id' => $level->id,
                'parent_id' => $parent?->id,
                'node_type' => $level->key,
                'level' => $index,
                'code' => "C{$index}",
                'name_ar' => "عقدة {$index}",
            ]);
        }
    }

    // ─── Node semantics (finding H7) ─────────────────────────────────────────

    public function test_node_behaviour_resolves_from_its_level_definition(): void
    {
        $definition = $this->buildStructure(3);
        $levels = $definition->levels()->get();

        $grouping = ComplianceNode::create([
            'compliance_program_id' => $this->program->id,
            'hierarchy_level_id' => $levels[0]->id,
            'node_type' => $levels[0]->key, 'level' => 0, 'code' => 'G1', 'name_ar' => 'مجموعة',
        ]);
        $leaf = ComplianceNode::create([
            'compliance_program_id' => $this->program->id,
            'hierarchy_level_id' => $levels[2]->id,
            'parent_id' => $grouping->id,
            'node_type' => $levels[2]->key, 'level' => 2, 'code' => 'L1', 'name_ar' => 'ورقة',
        ]);

        $this->assertFalse($grouping->isAssignable());
        $this->assertFalse($grouping->isAssessable());
        $this->assertFalse($grouping->acceptsEvidence());

        $this->assertTrue($leaf->isAssignable());
        $this->assertTrue($leaf->isAssessable());
        $this->assertTrue($leaf->acceptsEvidence());
    }

    public function test_a_node_override_takes_precedence_over_its_level(): void
    {
        $definition = $this->buildStructure(3);
        $levels = $definition->levels()->get();

        $leaf = ComplianceNode::create([
            'compliance_program_id' => $this->program->id,
            'hierarchy_level_id' => $levels[2]->id,
            'node_type' => $levels[2]->key, 'level' => 2, 'code' => 'L1', 'name_ar' => 'ورقة',
            'is_assignable_override' => false,
        ]);

        $this->assertFalse($leaf->isAssignable(), 'An explicit false override must win over the level default.');
        $this->assertTrue($leaf->isAssessable(), 'Unset overrides must still inherit from the level.');
    }

    // ─── Isolation ───────────────────────────────────────────────────────────

    public function test_each_program_carries_its_own_independent_structure(): void
    {
        $other = ComplianceProgram::create([
            'code' => 'OTHER', 'name_ar' => 'آخر', 'name_en' => 'Other',
            'status' => 'active', 'is_active' => true,
        ]);

        $this->buildStructure(3);
        $this->buildStructure(6, $other);

        $this->assertCount(3, $this->service->levels($this->program));
        $this->assertCount(6, $this->service->levels($other));

        // No level of one program is ever visible to the other.
        $mine = collect($this->service->levels($this->program))->pluck('compliance_program_id')->unique();
        $this->assertSame([$this->program->id], $mine->all());
    }
}
