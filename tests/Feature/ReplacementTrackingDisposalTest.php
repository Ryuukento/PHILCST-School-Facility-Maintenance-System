<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * DISPOSAL ARCHIVE — covers ReplacementTrackingController::dispose(), the
 * fix for the Capstone Panel IT Expert's finding that "For Disposal" was
 * only ever a computed label, never a real recorded fact. See
 * 2026_09_30_000300_add_disposal_fields_to_maintenance_reports_table.
 */
class ReplacementTrackingDisposalTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('replacement_tracking_disposal_testing');
        $this->createTestSchema();
        $this->forceLocalTestUrl();
    }

    public function test_maintenance_staff_cannot_confirm_disposal(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_staff']);
        $itemId = $this->seedItem(['name' => 'Broken Chair Frame', 'quantity' => 5]);
        $reportId = $this->seedReport([
            'status' => 'completed',
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_deducted_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_staff')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", []);

        $response->assertStatus(403);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertNull($report->need_change_disposed_at);
        $this->assertNull($report->need_change_disposed_by);
    }

    public function test_head_maintenance_can_confirm_disposal_of_an_eligible_report(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Old Projector', 'quantity' => 3]);
        $reportId = $this->seedReport([
            'status' => 'completed',
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_deducted_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", [
                'notes' => 'Turned over to Property Custodian',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame($userId, (int) $report->need_change_disposed_by);
        $this->assertNotNull($report->need_change_disposed_at);
        $this->assertSame('Turned over to Property Custodian', $report->need_change_disposal_notes);

        $log = DB::table('activity_logs')->where('entity_id', $reportId)->where('action', 'DISPOSE_REPLACED_ITEM')->first();
        $this->assertNotNull($log, 'Expected an activity log entry for DISPOSE_REPLACED_ITEM.');
    }

    public function test_administrator_can_confirm_disposal_too(): void
    {
        $userId = $this->seedUser(['role' => 'super_admin']);
        $itemId = $this->seedItem(['name' => 'Old Fan', 'quantity' => 3]);
        $reportId = $this->seedReport([
            'status' => 'closed',
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_deducted_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'super_admin')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", []);

        $response->assertOk();

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame($userId, (int) $report->need_change_disposed_by);
        $this->assertNotNull($report->need_change_disposed_at);
        $this->assertNull($report->need_change_disposal_notes);
    }

    public function test_disposing_the_same_report_twice_is_blocked(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Old Sink', 'quantity' => 3]);
        $reportId = $this->seedReport([
            'status' => 'completed',
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_deducted_at' => now(),
        ]);

        $first = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", []);
        $first->assertOk();

        $afterFirst = DB::table('maintenance_reports')->where('report_id', $reportId)->first();

        $second = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", []);
        $second->assertStatus(422);

        $afterSecond = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame($afterFirst->need_change_disposed_at, $afterSecond->need_change_disposed_at);

        $this->assertSame(
            1,
            DB::table('activity_logs')->where('entity_id', $reportId)->where('action', 'DISPOSE_REPLACED_ITEM')->count()
        );
    }

    public function test_a_report_not_yet_deducted_and_completed_cannot_be_disposed(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Pending Item', 'quantity' => 3]);
        $reportId = $this->seedReport([
            'status' => 'in_progress',
            'need_change_item_id' => $itemId,
            'need_change_status' => 'pending',
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", []);

        $response->assertStatus(422);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertNull($report->need_change_disposed_at);
    }

    public function test_a_deducted_but_not_yet_completed_report_cannot_be_disposed(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'In Progress Item', 'quantity' => 3]);
        $reportId = $this->seedReport([
            'status' => 'in_progress',
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_deducted_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", []);

        $response->assertStatus(422);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertNull($report->need_change_disposed_at);
    }

    public function test_disposing_a_nonexistent_report_returns_404(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson('/api/replacement-tracking/999999/dispose', []);

        $response->assertStatus(404);
    }

    public function test_disposal_reflects_in_the_replacement_tracking_index_listing(): void
    {
        $userId = $this->seedUser(['role' => 'maintenance_admin']);
        $itemId = $this->seedItem(['name' => 'Archived Pump', 'quantity' => 3]);
        $reportId = $this->seedReport([
            'status' => 'completed',
            'need_change_item_id' => $itemId,
            'need_change_status' => 'deducted',
            'need_change_deducted_at' => now(),
        ]);

        $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->postJson("/api/replacement-tracking/{$reportId}/dispose", ['notes' => 'Junked, no salvage value'])
            ->assertOk();

        $index = $this
            ->actingAsSessionUser($userId, 'maintenance_admin')
            ->getJson('/api/replacement-tracking');

        $index->assertOk();
        $record = collect($index->json('data.records'))->firstWhere('report_id', $reportId);

        $this->assertNotNull($record);
        $this->assertSame('disposed', $record['tracking_status']);
        $this->assertSame('Junked, no salvage value', $record['disposal_notes']);
        $this->assertNotNull($record['disposed_at']);
        $this->assertSame(1, $index->json('data.summary.disposed'));
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('inventory_categories');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();

        if (!Schema::hasTable('inventory_categories')) {
            Schema::create('inventory_categories', function ($table): void {
                $table->increments('id');
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('buildings')) {
            Schema::create('buildings', function ($table): void {
                $table->increments('id');
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('floors')) {
            Schema::create('floors', function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('building_id')->nullable();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('rooms')) {
            Schema::create('rooms', function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('building_id')->nullable();
                $table->unsignedInteger('floor_id')->nullable();
                $table->string('name');
                $table->timestamps();
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    private function seedReport(array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $this->seedUser(),
            'assigned_to' => null,
            'department_id' => null,
            'due_date' => null,
            'completed_date' => null,
            'need_change_item_id' => null,
            'need_change_quantity' => 1,
            'need_change_status' => 'pending',
            'need_change_approved_by' => null,
            'need_change_approved_at' => null,
            'need_change_deducted_at' => null,
            'need_change_disposed_by' => null,
            'need_change_disposed_at' => null,
            'need_change_disposal_notes' => null,
            'completion_proof_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }
}
