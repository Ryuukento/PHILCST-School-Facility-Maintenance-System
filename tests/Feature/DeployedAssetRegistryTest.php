<?php

namespace Tests\Feature;

use App\Models\DeployedAsset;
use App\Services\AssetRegistryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Deployed Asset registry — end-to-end coverage of
 * Dispatch release -> Track as Assets -> deployed_assets ->
 * Report Against a Specific Asset -> duplicate detection, plus the
 * backward-compatibility guarantees (untracked release, legacy room_asset
 * reports, no double-counting in Buildings Overview).
 */
class DeployedAssetRegistryTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $roomId;
    private int $otherRoomId;
    private int $staffId;
    private int $deptId;
    private string $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('deployed_asset_registry_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();

        $buildingId = DB::table('buildings')->insertGetId(['name' => 'Main Building', 'created_at' => now(), 'updated_at' => now()]);
        $floorId = DB::table('floors')->insertGetId(['building_id' => $buildingId, 'name' => '2nd Floor', 'created_at' => now(), 'updated_at' => now()]);
        $this->roomId = $this->seedRoom(['name' => 'Computer Laboratory 1', 'building_id' => $buildingId, 'floor_id' => $floorId]);
        $this->otherRoomId = $this->seedRoom(['name' => 'Room 101', 'building_id' => $buildingId, 'floor_id' => $floorId]);
        $this->deptId = DB::table('departments')->insertGetId(['name' => 'Maintenance', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()], 'department_id');
        $this->staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $this->deptId, 'full_name' => 'Sara Staff']);
        $this->year = now()->format('Y');
    }

    // ------------------------------------------------------------------
    // Dispatch release
    // ------------------------------------------------------------------

    public function test_release_without_assets_keeps_existing_behaviour(): void
    {
        $itemId = $this->seedItem(['name' => 'Whiteboard Marker', 'quantity' => 20]);
        [$dispatchId] = $this->seedApprovedDispatch([[$itemId, 2]]);

        $this->release($dispatchId)->assertOk();

        $this->assertSame(18, $this->quantity($itemId));
        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(0, DB::table('deployed_assets')->count());
        $this->assertSame(0, (int) DB::table('asset_code_sequences')->sum('last_value'));
    }

    public function test_blank_codes_generate_sequential_sfms_codes(): void
    {
        $itemId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 3]]);

        $this->release($dispatchId, [$lines[0] => ['', '', '']])->assertOk();

        $assets = DB::table('deployed_assets')->orderBy('id')->get();
        $this->assertSame(
            ["SFMS-{$this->year}-000001", "SFMS-{$this->year}-000002", "SFMS-{$this->year}-000003"],
            $assets->pluck('asset_code')->all()
        );
        foreach ($assets as $asset) {
            $this->assertSame('generated', $asset->code_source);
            $this->assertSame($itemId, (int) $asset->item_id);
            $this->assertSame($dispatchId, (int) $asset->dispatch_id);
            $this->assertSame($lines[0], (int) $asset->dispatch_item_id);
            $this->assertSame($this->roomId, (int) $asset->room_id);
            $this->assertSame('active', $asset->status);
            $this->assertSame($this->staffId, (int) $asset->created_by);
        }
        // Stock is deducted exactly once, by the line's deploy transaction.
        $this->assertSame(7, $this->quantity($itemId));
        $this->assertSame(1, DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->count());
        $this->assertSame(3, (int) DB::table('asset_code_sequences')->where('year', $this->year)->value('last_value'));
    }

    public function test_existing_official_codes_are_preserved_and_normalized(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 2]]);

        $this->release($dispatchId, [$lines[0] => ['  pc-2025-001 ', 'PC-2025-002']])->assertOk();

        $assets = DB::table('deployed_assets')->orderBy('id')->get();
        $this->assertSame(['PC-2025-001', 'PC-2025-002'], $assets->pluck('asset_code')->all());
        $this->assertSame(['existing', 'existing'], $assets->pluck('code_source')->all());
        // No SFMS code was consumed for units that already had one.
        $this->assertSame(0, (int) DB::table('asset_code_sequences')->sum('last_value'));
    }

    public function test_mixed_existing_and_blank_codes(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 3]]);

        $this->release($dispatchId, [$lines[0] => ['OFFICIAL-PC-001', '', 'OFFICIAL-PC-003']])->assertOk();

        $assets = DB::table('deployed_assets')->orderBy('id')->get();
        $this->assertSame(['OFFICIAL-PC-001', "SFMS-{$this->year}-000001", 'OFFICIAL-PC-003'], $assets->pluck('asset_code')->all());
        $this->assertSame(['existing', 'generated', 'existing'], $assets->pluck('code_source')->all());
    }

    public function test_only_tracked_lines_get_assets(): void
    {
        $chairId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        $bulbId = $this->seedItem(['name' => 'LED Bulb', 'quantity' => 50]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$chairId, 2], [$bulbId, 10]]);

        $this->release($dispatchId, [$lines[0] => ['', '']])->assertOk();

        $this->assertSame(2, DB::table('deployed_assets')->count());
        $this->assertSame(0, DB::table('deployed_assets')->where('item_id', $bulbId)->count());
        $this->assertSame(8, $this->quantity($chairId));
        $this->assertSame(40, $this->quantity($bulbId));
    }

    public function test_code_already_assigned_to_another_asset_rejects_the_whole_release(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 10]);
        [$firstId, $firstLines] = $this->seedApprovedDispatch([[$itemId, 1]]);
        $this->release($firstId, [$firstLines[0] => ['PC-2025-001']])->assertOk();

        [$secondId, $secondLines] = $this->seedApprovedDispatch([[$itemId, 2]]);
        $response = $this->release($secondId, [$secondLines[0] => ['', 'pc-2025-001']]);

        $response->assertStatus(400);
        $this->assertStringContainsString('PC-2025-001', $response->json('message'));
        $this->assertStringContainsString('already assigned', $response->json('message'));
        $this->assertSame('approved', DB::table('dispatches')->where('id', $secondId)->value('status'));
        $this->assertSame(9, $this->quantity($itemId));
        $this->assertSame(0, DB::table('inventory_transactions')->where('dispatch_id', $secondId)->count());
        $this->assertSame(1, DB::table('deployed_assets')->count());
        // The blank unit's code was never issued (validation precedes generation).
        $this->assertSame(0, (int) DB::table('asset_code_sequences')->sum('last_value'));
    }

    public function test_duplicate_code_within_the_same_request_is_rejected(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 2]]);

        $response = $this->release($dispatchId, [$lines[0] => ['PC-7', 'pc-7']]);

        $response->assertStatus(400);
        $this->assertStringContainsString('more than once', $response->json('message'));
        $this->assertSame(0, DB::table('deployed_assets')->count());
        $this->assertSame(10, $this->quantity($itemId));
    }

    public function test_legacy_room_asset_code_cannot_be_reused(): void
    {
        $this->seedItem(['name' => 'Aircon', 'item_type' => 'room_asset', 'room_id' => $this->roomId, 'quantity' => 1, 'asset_code' => 'LAB-AC-01']);
        $itemId = $this->seedItem(['name' => 'Aircon', 'quantity' => 3]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 1]]);

        $response = $this->release($dispatchId, [$lines[0] => ['lab-ac-01']]);

        $response->assertStatus(400);
        $this->assertStringContainsString('already assigned', $response->json('message'));
        $this->assertSame(0, DB::table('deployed_assets')->count());
    }

    #[DataProvider('invalidManualCodeProvider')]
    public function test_invalid_manual_codes_are_rejected(string $code, string $expectedFragment): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 1]]);

        $response = $this->release($dispatchId, [$lines[0] => [$code]]);

        $response->assertStatus(400);
        $this->assertStringContainsString($expectedFragment, $response->json('message'));
        $this->assertSame(0, DB::table('deployed_assets')->count());
        $this->assertSame('approved', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
    }

    public static function invalidManualCodeProvider(): array
    {
        return [
            'imitates generated format' => ['SFMS-2026-000001', 'reserved'],
            'reserved prefix any shape' => ['sfms-lab-01', 'reserved'],
            'unsafe characters' => ['<script>', 'may only contain'],
            'quote characters' => ["PC'001", 'may only contain'],
            'too short' => ['A1', 'between'],
            'too long' => [str_repeat('A', 51), 'between'],
        ];
    }

    public function test_asset_count_must_match_the_line_quantity(): void
    {
        $itemId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 3]]);

        $response = $this->release($dispatchId, [$lines[0] => ['', '']]);

        $response->assertStatus(400);
        $this->assertStringContainsString('3 units', $response->json('message'));
        $this->assertSame(0, DB::table('deployed_assets')->count());
        $this->assertSame(10, $this->quantity($itemId));
    }

    public function test_dispatch_item_from_another_dispatch_is_rejected(): void
    {
        $itemId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        [, $otherLines] = $this->seedApprovedDispatch([[$itemId, 1]]);
        [$dispatchId] = $this->seedApprovedDispatch([[$itemId, 1]]);

        $response = $this->release($dispatchId, [$otherLines[0] => ['']]);

        $response->assertStatus(400);
        $this->assertStringContainsString('not part of this dispatch', $response->json('message'));
        $this->assertSame(0, DB::table('deployed_assets')->count());
    }

    public function test_only_the_assigned_release_personnel_can_register_assets(): void
    {
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 1]]);

        $this->actingAsSessionUserWithFlatKeys($otherStaffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", ['assets' => [$lines[0] => ['']]])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('deployed_assets')->count());
    }

    public function test_head_maintenance_assigned_as_release_personnel_can_release_with_assets(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin', 'full_name' => 'Kristine Head']);
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 2]]);
        DB::table('dispatches')->where('id', $dispatchId)->update(['release_assigned_to' => $headId]);

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/release", ['assets' => [$lines[0] => ['HEAD-PC-01', '']]])
            ->assertOk();

        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(3, $this->quantity($itemId));
        $this->assertSame(['HEAD-PC-01', "SFMS-{$this->year}-000001"], DB::table('deployed_assets')->orderBy('id')->pluck('asset_code')->all());
        $this->assertSame([$headId, $headId], DB::table('deployed_assets')->pluck('created_by')->map(fn ($v) => (int) $v)->all());
    }

    public function test_head_maintenance_who_is_not_the_assignee_cannot_release(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId] = $this->seedApprovedDispatch([[$itemId, 1]]);

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/dispatches/{$dispatchId}/release", [])
            ->assertStatus(403);

        $this->assertSame('approved', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(5, $this->quantity($itemId));
    }

    public function test_generated_codes_never_repeat_across_releases(): void
    {
        // Different quantities: InventoryTransactionObserver's pre-existing
        // 5-second duplicate-deployment guard refuses two deploys with the
        // same item/room/quantity/releaser, which is unrelated to asset codes.
        $itemId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 20]);
        [$firstId, $firstLines] = $this->seedApprovedDispatch([[$itemId, 2]]);
        [$secondId, $secondLines] = $this->seedApprovedDispatch([[$itemId, 3]]);

        $this->release($firstId, [$firstLines[0] => ['', '']])->assertOk();
        $this->release($secondId, [$secondLines[0] => ['', '', '']])->assertOk();

        $codes = DB::table('deployed_assets')->orderBy('id')->pluck('asset_code')->all();
        $this->assertSame([
            "SFMS-{$this->year}-000001", "SFMS-{$this->year}-000002",
            "SFMS-{$this->year}-000003", "SFMS-{$this->year}-000004", "SFMS-{$this->year}-000005",
        ], $codes);
        $this->assertCount(5, array_unique($codes));
    }

    public function test_generation_skips_a_value_already_used_by_legacy_data(): void
    {
        $this->seedItem(['name' => 'Old Projector', 'item_type' => 'room_asset', 'room_id' => $this->roomId, 'asset_code' => "SFMS-{$this->year}-000001"]);

        $code = app(AssetRegistryService::class)->generate();

        $this->assertSame("SFMS-{$this->year}-000002", $code);
    }

    public function test_cancelled_and_rejected_dispatches_create_no_assets(): void
    {
        $itemId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        [$cancelledId, $cancelledLines] = $this->seedApprovedDispatch([[$itemId, 1]], 'cancelled');
        [$pendingId, $pendingLines] = $this->seedApprovedDispatch([[$itemId, 1]], 'pending');

        $adminId = $this->seedUser(['role' => 'super_admin']);
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson("/api/dispatches/{$pendingId}/reject", ['reason' => 'Not needed this term'])
            ->assertOk();

        $this->release($cancelledId, [$cancelledLines[0] => ['']])->assertStatus(400);
        $this->release($pendingId, [$pendingLines[0] => ['']])->assertStatus(400);

        $this->assertSame(0, DB::table('deployed_assets')->count());
        $this->assertSame(10, $this->quantity($itemId));
    }

    public function test_asset_codes_are_immutable(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 1]]);
        $this->release($dispatchId, [$lines[0] => ['PC-2025-001']])->assertOk();

        $asset = DeployedAsset::query()->firstOrFail();

        // Lifecycle fields may change...
        $asset->update(['status' => 'returned']);
        $this->assertSame('returned', $asset->fresh()->status);

        // ...the code and its provenance may not.
        $this->expectException(LogicException::class);
        $asset->update(['asset_code' => 'PC-2025-999']);
    }

    // ------------------------------------------------------------------
    // Auto-complete release path (Phase 6)
    // ------------------------------------------------------------------

    public function test_dispatching_the_final_item_releases_through_the_real_release_path(): void
    {
        $chairId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        $pcId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$chairId, 2], [$pcId, 2]]);

        $this->dispatchLine($dispatchId, $lines[0])->assertOk();
        // Not complete yet: nothing released, no stock moved.
        $this->assertSame('approved', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(10, $this->quantity($chairId));

        $this->dispatchLine($dispatchId, $lines[1], [$lines[1] => ['PC-2025-010', '']])->assertOk();

        $this->assertSame('released', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        // Previously this path flipped the status without any deploy
        // transaction, so stock was never deducted. Now both lines are
        // deducted exactly once.
        $this->assertSame(8, $this->quantity($chairId));
        $this->assertSame(3, $this->quantity($pcId));
        $this->assertSame(2, DB::table('inventory_transactions')->where('dispatch_id', $dispatchId)->where('transaction_type', 'deploy')->count());
        $this->assertSame(['PC-2025-010', "SFMS-{$this->year}-000001"], DB::table('deployed_assets')->orderBy('id')->pluck('asset_code')->all());
    }

    public function test_assets_sent_before_the_final_item_are_refused_and_nothing_is_marked(): void
    {
        $chairId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        $pcId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$chairId, 1], [$pcId, 1]]);

        $this->dispatchLine($dispatchId, $lines[0], [$lines[0] => ['']])->assertStatus(400);

        $this->assertNull(DB::table('dispatch_items')->where('id', $lines[0])->value('dispatched_by'));
        $this->assertSame(0, DB::table('deployed_assets')->count());
    }

    public function test_a_failed_final_release_does_not_leave_the_item_marked_dispatched(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 5]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 1]]);

        $this->dispatchLine($dispatchId, $lines[0], [$lines[0] => ['SFMS-2026-000123']])->assertStatus(400);

        $this->assertNull(DB::table('dispatch_items')->where('id', $lines[0])->value('dispatched_by'));
        $this->assertSame('approved', DB::table('dispatches')->where('id', $dispatchId)->value('status'));
        $this->assertSame(5, $this->quantity($itemId));
    }

    // ------------------------------------------------------------------
    // Report Against a Specific Asset
    // ------------------------------------------------------------------

    public function test_report_against_a_deployed_asset_stores_the_asset_and_resolved_links(): void
    {
        [$assetId, $itemId, $dispatchId] = $this->releaseTrackedAsset();

        $response = $this->createAssetReport(['deployed_asset_id' => $assetId]);

        $response->assertStatus(201);
        $damage = DB::table('damage_reports')->first();
        $this->assertSame($assetId, (int) $damage->deployed_asset_id);
        $this->assertSame($itemId, (int) $damage->item_id);
        $this->assertSame($this->roomId, (int) $damage->room_id);
        $this->assertSame($dispatchId, (int) $damage->source_dispatch_id);

        $report = DB::table('maintenance_reports')->where('report_id', $damage->report_id)->first();
        $this->assertSame($assetId, (int) $report->deployed_asset_id);
        $this->assertSame($itemId, (int) $report->item_id);
        $this->assertSame($dispatchId, (int) $report->source_dispatch_id);
        $this->assertSame('repair_replacement', $report->report_category);
    }

    public function test_report_rejects_an_asset_paired_with_a_different_room(): void
    {
        [$assetId] = $this->releaseTrackedAsset();

        $this->createAssetReport(['deployed_asset_id' => $assetId, 'room_id' => $this->otherRoomId])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    public function test_report_rejects_an_inactive_asset(): void
    {
        [$assetId] = $this->releaseTrackedAsset();
        DB::table('deployed_assets')->where('id', $assetId)->update(['status' => 'disposed']);

        $this->createAssetReport(['deployed_asset_id' => $assetId])->assertStatus(422);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    public function test_second_active_report_on_the_same_asset_is_a_duplicate(): void
    {
        [$assetId] = $this->releaseTrackedAsset();
        $this->createAssetReport(['deployed_asset_id' => $assetId])->assertStatus(201);

        $response = $this->createAssetReport([
            'deployed_asset_id' => $assetId,
            'description' => 'Completely different wording about the same unit.',
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('damage_reports')->count());

        // "Different Issue" still lets the reporter proceed.
        $this->createAssetReport(['deployed_asset_id' => $assetId, 'override_duplicate' => true])->assertStatus(201);
    }

    public function test_a_different_asset_in_the_same_room_is_not_a_duplicate(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 2]]);
        $this->release($dispatchId, [$lines[0] => ['', '']])->assertOk();
        [$first, $second] = DB::table('deployed_assets')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->createAssetReport(['deployed_asset_id' => $first])->assertStatus(201);
        // Identical item, room, department AND description — still a
        // different physical unit, so it must not be blocked.
        $this->createAssetReport(['deployed_asset_id' => $second])->assertStatus(201);

        $this->assertSame(2, DB::table('damage_reports')->count());
    }

    public function test_check_duplicate_endpoint_matches_by_asset(): void
    {
        [$assetId] = $this->releaseTrackedAsset();
        $this->createAssetReport(['deployed_asset_id' => $assetId])->assertStatus(201);

        $this->actingAsSessionUser($this->staffId, 'maintenance_staff')
            ->postJson('/api/damage-reports/check-duplicate', ['deployed_asset_id' => $assetId])
            ->assertOk()
            ->assertJsonPath('data.has_duplicate', true);
    }

    public function test_legacy_room_asset_report_and_duplicate_rule_still_work(): void
    {
        $legacyId = $this->seedItem(['name' => 'Aircon', 'item_type' => 'room_asset', 'room_id' => $this->roomId, 'quantity' => 1, 'asset_code' => 'LAB-AC-01']);

        $this->createAssetReport(['item_id' => $legacyId, 'room_id' => $this->roomId])->assertStatus(201);
        $this->assertNull(DB::table('damage_reports')->value('deployed_asset_id'));

        // Same item/room/department/description -> still the legacy duplicate.
        $this->createAssetReport(['item_id' => $legacyId, 'room_id' => $this->roomId])->assertStatus(409);
    }

    public function test_general_report_is_unaffected(): void
    {
        $this->actingAsSessionUser($this->staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Furniture',
                'title' => 'Broken Chair',
                'description' => 'Chair leg is broken.',
                'location' => 'Room 101',
                'priority' => 'medium',
                'department_id' => $this->deptId,
            ])
            ->assertStatus(201);

        $this->assertNull(DB::table('maintenance_reports')->value('deployed_asset_id'));
        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // ------------------------------------------------------------------
    // GET /api/deployed-assets
    // ------------------------------------------------------------------

    public function test_deployed_assets_endpoint_filters_and_searches(): void
    {
        $pcId = $this->seedItem(['name' => 'Dell OptiPlex 7090', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$pcId, 2]]);
        $this->release($dispatchId, [$lines[0] => ['', 'PC-2025-001']])->assertOk();
        $this->seedItem(['name' => 'Aircon', 'item_type' => 'room_asset', 'room_id' => $this->roomId, 'quantity' => 1, 'asset_code' => 'LAB-AC-01']);

        $byCode = $this->getAssets("q=SFMS-{$this->year}-000001");
        $this->assertCount(1, $byCode);
        $this->assertSame("SFMS-{$this->year}-000001", $byCode[0]['asset_code']);
        $this->assertSame('Dell OptiPlex 7090', $byCode[0]['item_name']);
        $this->assertSame('Computer Laboratory 1', $byCode[0]['room_name']);
        $this->assertSame('Main Building', $byCode[0]['building_name']);
        $this->assertSame('deployed', $byCode[0]['source']);
        $this->assertNotNull($byCode[0]['dispatch_code']);

        $this->assertCount(2, $this->getAssets('q=optiplex'));
        $this->assertCount(2, $this->getAssets('q=Main Building'));
        $this->assertCount(2, $this->getAssets('room_id=' . $this->roomId));
        $this->assertCount(0, $this->getAssets('room_id=' . $this->otherRoomId));
        $this->assertCount(2, $this->getAssets('dispatch_id=' . $dispatchId));

        $withLegacy = $this->getAssets('include_legacy=1&room_id=' . $this->roomId);
        $this->assertCount(3, $withLegacy);
        $this->assertSame(['deployed', 'deployed', 'legacy'], array_column($withLegacy, 'source'));
    }

    public function test_per_dispatch_listing_follows_unit_order_for_labels(): void
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 3]]);
        $this->release($dispatchId, [$lines[0] => ['ZZ-PC-001', '', 'AA-PC-003']])->assertOk();

        // Unit 1, 2, 3 as entered at release — not alphabetical — so the
        // printed "Unit n of N" sticker carries the right unit's code.
        $this->assertSame(
            ['ZZ-PC-001', "SFMS-{$this->year}-000001", 'AA-PC-003'],
            array_column($this->getAssets("dispatch_id={$dispatchId}&status=all&per_page=500"), 'asset_code')
        );
        // Outside a dispatch the list stays sorted by code.
        $this->assertSame(
            ['AA-PC-003', "SFMS-{$this->year}-000001", 'ZZ-PC-001'],
            array_column($this->getAssets('q=PC'), 'asset_code')
        );
    }

    public function test_deployed_assets_endpoint_requires_a_known_role(): void
    {
        $guestId = $this->seedUser(['role' => 'guest']);

        $this->actingAsSessionUser($guestId, 'guest')
            ->getJson('/api/deployed-assets')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // No double-counting
    // ------------------------------------------------------------------

    public function test_buildings_deployed_items_do_not_double_count_tracked_units(): void
    {
        $itemId = $this->seedItem(['name' => 'Monoblock Chair', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 3]]);
        $this->release($dispatchId, [$lines[0] => ['', '', '']])->assertOk();

        $rows = $this->actingAsSessionUser($this->staffId, 'maintenance_staff')
            ->getJson('/api/buildings/deployed-items?room_id=' . $this->roomId)
            ->assertOk()
            ->json('data.items');

        // One released dispatch line of 3 units — not one line plus three
        // asset rows.
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['quantity']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function release(int $dispatchId, array $assets = [])
    {
        $payload = $assets === [] ? [] : ['assets' => $assets];

        return $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/release", $payload);
    }

    private function dispatchLine(int $dispatchId, int $dispatchItemId, array $assets = [])
    {
        $payload = ['dispatched_by' => $this->staffId] + ($assets === [] ? [] : ['assets' => $assets]);

        return $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')
            ->postJson("/api/dispatches/{$dispatchId}/items/{$dispatchItemId}/dispatch", $payload);
    }

    /** @return array{0:int,1:int,2:int} [deployed_asset_id, item_id, dispatch_id] */
    private function releaseTrackedAsset(): array
    {
        $itemId = $this->seedItem(['name' => 'Desktop PC', 'quantity' => 10]);
        [$dispatchId, $lines] = $this->seedApprovedDispatch([[$itemId, 1]]);
        $this->release($dispatchId, [$lines[0] => ['']])->assertOk();

        return [(int) DB::table('deployed_assets')->value('id'), $itemId, $dispatchId];
    }

    private function createAssetReport(array $overrides)
    {
        return $this->actingAsSessionUser($this->staffId, 'maintenance_staff')
            ->postJson('/api/reports', array_merge([
                'problem_type' => 'Electrical',
                'title' => 'PC will not power on',
                'description' => 'The unit does not turn on when the power button is pressed.',
                'priority' => 'high',
                'department_id' => $this->deptId,
                'severity_level' => 'high',
            ], $overrides));
    }

    private function getAssets(string $query): array
    {
        return $this->actingAsSessionUser($this->staffId, 'maintenance_staff')
            ->getJson('/api/deployed-assets?' . $query)
            ->assertOk()
            ->json('data.items');
    }

    private function quantity(int $itemId): int
    {
        return (int) DB::table('items')->where('id', $itemId)->value('quantity');
    }

    /**
     * @param  list<array{0:int,1:int}>  $lines  [item_id, quantity] pairs
     * @return array{0:int,1:list<int>} [dispatch_id, dispatch_item_ids]
     */
    private function seedApprovedDispatch(array $lines, string $status = 'approved'): array
    {
        $dispatchId = DB::table('dispatches')->insertGetId([
            'dispatch_code' => 'DSP-' . uniqid(),
            'status' => $status,
            'room_id' => $this->roomId,
            'release_assigned_to' => $this->staffId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineIds = [];
        foreach ($lines as [$itemId, $quantity]) {
            $lineIds[] = DB::table('dispatch_items')->insertGetId([
                'dispatch_id' => $dispatchId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$dispatchId, $lineIds];
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'asset_code_sequences', 'deployed_assets', 'dispatch_personnel_assignments', 'dispatch_items', 'dispatches',
            'damage_report_histories', 'damage_reports', 'notifications', 'inventory_transactions',
            'inventory_categories', 'activity_logs', 'maintenance_reports', 'items', 'rooms',
            'floors', 'buildings', 'departments', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createMaintenanceReportsTable();
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();
        $this->createDeployedAssetsTable();
        $this->createAssetCodeSequencesTable();

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('dispatch_id')->nullable();
        });
        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->string('report_category', 30)->default('general');
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
        });

        // Multi-personnel dispatch (2026_10_04_000100): eager-loaded by
        // completeDispatchIfAllItemsDispatched()'s return value.
        Schema::create('dispatch_personnel_assignments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('dispatch_id');
            $table->unsignedInteger('personnel_user_id');
            $table->unsignedInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
        });
        Schema::create('buildings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('floors', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
            $table->unsignedBigInteger('deployed_asset_id')->nullable();
            $table->text('damage_description')->nullable();
            $table->string('severity_level')->default('medium');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('pending');
            $table->string('image_path')->nullable();
            $table->text('repair_notes')->nullable();
            $table->unsignedInteger('replacement_item_id')->nullable();
            $table->integer('replacement_quantity')->nullable();
            $table->unsignedInteger('replacement_transaction_id')->nullable();
            $table->unsignedInteger('replaced_by')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('damage_report_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('damage_report_id');
            $table->string('action_type')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('meta_json')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::enableForeignKeyConstraints();
    }
}
