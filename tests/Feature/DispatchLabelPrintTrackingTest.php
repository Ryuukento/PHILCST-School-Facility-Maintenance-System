<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Dispatch label print tracking — the "Labels: Not yet printed / Printed"
 * and From/To date filters on the Dispatches page, and
 * POST /api/dispatches/labels-printed, which records a confirmed print.
 */
class DispatchLabelPrintTrackingTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $adminId;
    private int $staffId;
    private int $otherStaffId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('dispatch_label_print_tracking_testing');
        Schema::disableForeignKeyConstraints();
        foreach (['dispatch_items', 'dispatches', 'activity_logs', 'maintenance_reports', 'items', 'rooms', 'departments', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createActivityLogsTable();
        $this->createMaintenanceReportsTable();
        $this->createDispatchesTable();
        $this->createDispatchItemsTable();
        Schema::enableForeignKeyConstraints();
        $this->forceLocalTestUrl();

        $this->adminId = $this->seedUser(['role' => 'super_admin']);
        $this->staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $this->otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
    }

    public function test_label_status_filter_separates_printed_and_not_printed(): void
    {
        $printed = $this->seedDispatch(['labels_printed_at' => now()->subDay()]);
        $notPrinted = $this->seedDispatch();

        $this->assertSame([$notPrinted], $this->listIds('label_status=not_printed'));
        $this->assertSame([$printed], $this->listIds('label_status=printed'));
        $this->assertEqualsCanonicalizing([$printed, $notPrinted], $this->listIds(''));
        // Unknown values are ignored rather than narrowing the list.
        $this->assertEqualsCanonicalizing([$printed, $notPrinted], $this->listIds('label_status=bogus'));
    }

    public function test_date_filter_uses_the_dispatch_date_and_combines_with_label_status(): void
    {
        $old = $this->seedDispatch(['created_at' => '2026-09-10 09:00:00']);
        $inRange = $this->seedDispatch(['created_at' => '2026-10-02 09:00:00']);
        $inRangePrinted = $this->seedDispatch(['created_at' => '2026-10-03 09:00:00', 'labels_printed_at' => now()]);

        $this->assertEqualsCanonicalizing([$inRange, $inRangePrinted], $this->listIds('date_from=2026-10-01&date_to=2026-10-06'));
        $this->assertSame([$inRange], $this->listIds('date_from=2026-10-01&date_to=2026-10-06&label_status=not_printed'));
        $this->assertSame([$old], $this->listIds('date_to=2026-09-30'));
        // A malformed date is ignored, not sent to the database.
        $this->assertCount(3, $this->listIds('date_from=not-a-date'));
    }

    public function test_marking_records_who_and_when_and_skips_unprintable_dispatches(): void
    {
        $released = $this->seedDispatch(['status' => 'released']);
        $approved = $this->seedDispatch(['status' => 'approved']);
        $pending = $this->seedDispatch(['status' => 'pending']);

        $response = $this->actingAsSessionUserWithFlatKeys($this->adminId, 'super_admin')
            ->postJson('/api/dispatches/labels-printed', ['dispatch_ids' => [$released, $approved, $pending, 999999]])
            ->assertOk();

        $this->assertSame(2, $response->json('data.marked_count'));
        $this->assertEqualsCanonicalizing([$released, $approved], $response->json('data.dispatch_ids'));
        $this->assertNotNull(DB::table('dispatches')->where('id', $released)->value('labels_printed_at'));
        $this->assertSame($this->adminId, (int) DB::table('dispatches')->where('id', $released)->value('labels_printed_by'));
        $this->assertNull(DB::table('dispatches')->where('id', $pending)->value('labels_printed_at'));
        $this->assertSame([$pending], $this->listIds('label_status=not_printed'));
    }

    public function test_maintenance_staff_can_only_mark_their_own_dispatches(): void
    {
        $own = $this->seedDispatch(['release_assigned_to' => $this->staffId]);
        $someoneElses = $this->seedDispatch(['release_assigned_to' => $this->otherStaffId]);

        $this->actingAsSessionUserWithFlatKeys($this->staffId, 'maintenance_staff')
            ->postJson('/api/dispatches/labels-printed', ['dispatch_ids' => [$own, $someoneElses]])
            ->assertOk()
            ->assertJsonPath('data.marked_count', 1);

        $this->assertNotNull(DB::table('dispatches')->where('id', $own)->value('labels_printed_at'));
        $this->assertNull(DB::table('dispatches')->where('id', $someoneElses)->value('labels_printed_at'));
    }

    public function test_marking_requires_a_list_of_ids(): void
    {
        $this->actingAsSessionUserWithFlatKeys($this->adminId, 'super_admin')
            ->postJson('/api/dispatches/labels-printed', ['dispatch_ids' => []])
            ->assertStatus(422);

        $this->actingAsSessionUserWithFlatKeys($this->adminId, 'super_admin')
            ->postJson('/api/dispatches/labels-printed', ['dispatch_ids' => ['abc']])
            ->assertStatus(422);
    }

    private function listIds(string $query): array
    {
        return array_map('intval', array_column(
            $this->actingAsSessionUserWithFlatKeys($this->adminId, 'super_admin')
                ->getJson('/api/dispatches?per_page=100' . ($query !== '' ? '&' . $query : ''))
                ->assertOk()
                ->json('data.data'),
            'id'
        ));
    }

    private function seedDispatch(array $overrides = []): int
    {
        return DB::table('dispatches')->insertGetId(array_merge([
            'dispatch_code' => 'DSP-' . uniqid(),
            'status' => 'released',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
