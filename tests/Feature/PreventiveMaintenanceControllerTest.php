<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 76 — Preventive Maintenance: Investigation and Regression Coverage.
 *
 * No test file existed for this module prior to this task (baseline: zero
 * PM tests). The read-only investigation (controller, service, models,
 * migration, config, routes, and the full frontend page) found the module
 * already correctly implemented and internally consistent — no documented
 * business rules exist in BUSINESS_RULES.md to cross-check against (same
 * "no doc, verify against code" situation as Dispatch/Repair Requests), no
 * model factory exists (PreventiveMaintenanceTask/-History rows are created
 * directly via Eloquent below, not via a factory), and the SearchableSelect
 * department_id-vs-user_id bug class fixed for Dispatch in TASK 75 does NOT
 * recur here (preventive-maintenance.php's personnel selects already carry
 * the correct onSelect override). This suite is therefore verification-only:
 * it pins down actual current behavior end-to-end through the real HTTP
 * surface, it does not fix a defect.
 *
 *   GET   /api/preventive-maintenance                    index()
 *   GET   /api/preventive-maintenance/summary             summary()
 *   GET   /api/preventive-maintenance/support/options     options()
 *   POST  /api/preventive-maintenance                     store()
 *   GET   /api/preventive-maintenance/{task}               show()
 *   PATCH /api/preventive-maintenance/{task}               update()
 *   POST  /api/preventive-maintenance/{task}/archive        archive()
 *   POST  /api/preventive-maintenance/{task}/activate       activate()
 *   POST  /api/preventive-maintenance/{task}/complete       complete()
 *   GET   /api/preventive-maintenance/{task}/history        history()
 */
class PreventiveMaintenanceControllerTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('preventive_maintenance_controller_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // ---------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------

    public function test_unauthenticated_request_cannot_list_tasks(): void
    {
        $this->getJson('/api/preventive-maintenance')->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_create_a_task(): void
    {
        $this->postJson('/api/preventive-maintenance', [])->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // store() — RBAC + validation + business logic
    // ---------------------------------------------------------------

    public function test_store_rejects_maintenance_staff_role(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Computers',
                'title' => 'Lab PCs',
                'frequency' => 'monthly',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('preventive_maintenance_tasks')->count());
    }

    public function test_store_rejects_missing_required_fields(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance', [])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('preventive_maintenance_tasks')->count());
    }

    public function test_administrator_is_view_only_and_cannot_create_a_task(): void
    {
        // 2026-09-27: Preventive Maintenance is performed by Head Maintenance
        // and Staff; the Administrator only views/monitors it.
        $adminId = $this->seedUser(['role' => 'super_admin']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Computers',
                'title' => 'Lab PCs Quarterly Check',
                'frequency' => 'quarterly',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('preventive_maintenance_tasks')->count());
    }

    public function test_head_maintenance_can_create_a_task(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Computers',
                'title' => 'Lab PCs Quarterly Check',
                'frequency' => 'quarterly',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $row = DB::table('preventive_maintenance_tasks')->first();
        $this->assertSame('Computers', $row->category);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame($headId, (int) $row->created_by);
    }

    public function test_store_derives_next_due_date_from_frequency_when_only_last_completed_date_given(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Generator',
                'title' => 'Genset Service',
                'frequency' => 'monthly',
                'last_completed_date' => '2026-01-31',
            ]);

        $response->assertStatus(201);
        // addMonthsNoOverflow(1) from Jan 31 lands on Feb 28 (2026 is not a
        // leap year), not an overflowed March date.
        $this->assertSame('2026-02-28', DB::table('preventive_maintenance_tasks')->value('next_due_date'));
    }

    public function test_store_with_explicit_next_due_date_does_not_recompute_it(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Boiler',
                'title' => 'Boiler Inspection',
                'frequency' => 'annually',
                'last_completed_date' => '2026-01-01',
                'next_due_date' => '2026-06-15',
            ])
            ->assertStatus(201);

        $this->assertSame('2026-06-15', DB::table('preventive_maintenance_tasks')->value('next_due_date'));
    }

    public function test_store_rejects_an_invalid_frequency(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Computers',
                'title' => 'Bad Frequency',
                'frequency' => 'weekly',
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('preventive_maintenance_tasks')->count());
    }

    public function test_store_with_an_assignee_notifies_that_user(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Computers',
                'title' => 'Assigned Task',
                'frequency' => 'monthly',
                'assigned_user_id' => $staffId,
            ])
            ->assertStatus(201);

        $this->assertSame(
            1,
            DB::table('notifications')
                ->where('user_id', $staffId)
                ->where('title', 'Preventive Maintenance Task Assigned')
                ->count()
        );
    }

    public function test_store_assigning_to_self_does_not_notify_self(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance', [
                'category' => 'Computers',
                'title' => 'Self Assigned Task',
                'frequency' => 'monthly',
                'assigned_user_id' => $adminId,
            ])
            ->assertStatus(201);

        $this->assertSame(0, DB::table('notifications')->where('user_id', $adminId)->count());
    }

    // ---------------------------------------------------------------
    // show()
    // ---------------------------------------------------------------

    public function test_show_returns_task_with_relationships(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $buildingId = $this->seedBuilding(['name' => 'Main Building']);
        $taskId = $this->seedTask(['building_id' => $buildingId, 'created_by' => $adminId]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson("/api/preventive-maintenance/{$taskId}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.task.id', $taskId);
        $response->assertJsonPath('data.task.building_name', 'Main Building');
    }

    public function test_maintenance_staff_can_view_any_task_not_just_own(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $otherStaffId]);

        // "View all, edit only own" — index()/show() are deliberately not
        // scoped by assignee (see PreventiveMaintenanceController::index()
        // doc comment).
        $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->getJson("/api/preventive-maintenance/{$taskId}")
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // update() — canManageTask() RBAC boundary
    // ---------------------------------------------------------------

    public function test_update_rejects_a_role_outside_the_allowed_roles(): void
    {
        $userId = $this->seedUser(['role' => 'user']);
        $taskId = $this->seedTask();

        $this
            ->actingAsSessionUserWithFlatKeys($userId, 'user')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['title' => 'Hacked'])
            ->assertStatus(403);
    }

    public function test_maintenance_staff_can_update_their_own_assigned_task(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId, 'title' => 'Original']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['title' => 'Updated By Owner']);

        $response->assertStatus(200);
        $this->assertSame('Updated By Owner', DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('title'));
    }

    public function test_maintenance_staff_cannot_update_a_task_assigned_to_someone_else(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $otherStaffId, 'title' => 'Original']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['title' => 'Should Be Rejected']);

        $response->assertStatus(403);
        $this->assertSame('Original', DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('title'));
    }

    public function test_maintenance_staff_cannot_update_an_unassigned_task(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => null]);

        $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['title' => 'Should Be Rejected'])
            ->assertStatus(403);
    }

    public function test_maintenance_admin_can_update_any_task_regardless_of_assignee(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $otherStaffId, 'title' => 'Original']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['title' => 'Updated By Admin'])
            ->assertStatus(200);

        $this->assertSame('Updated By Admin', DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('title'));
    }

    public function test_update_can_clear_a_nullable_field_by_explicit_null(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId]);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['assigned_user_id' => null])
            ->assertStatus(200);

        $this->assertNull(DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('assigned_user_id'));
    }

    public function test_update_reassignment_notifies_the_new_assignee(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $oldAssignee = $this->seedUser(['role' => 'maintenance_staff']);
        $newAssignee = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $oldAssignee]);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['assigned_user_id' => $newAssignee])
            ->assertStatus(200);

        $this->assertSame(
            1,
            DB::table('notifications')
                ->where('user_id', $newAssignee)
                ->where('title', 'Preventive Maintenance Task Assigned')
                ->count()
        );
        $this->assertSame(0, DB::table('notifications')->where('user_id', $oldAssignee)->count());
    }

    // ---------------------------------------------------------------
    // archive() / activate() — soft-hide, admin-only
    // ---------------------------------------------------------------

    public function test_archive_rejects_maintenance_staff_role(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId]);

        $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/archive")
            ->assertStatus(403);

        $this->assertSame(1, (int) DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('is_active'));
    }

    public function test_admin_can_archive_and_reactivate_a_task_without_deleting_it(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask();

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/archive")
            ->assertStatus(200);

        $this->assertSame(0, (int) DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('is_active'));
        $this->assertSame(1, DB::table('preventive_maintenance_tasks')->count());

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/activate")
            ->assertStatus(200);

        $this->assertSame(1, (int) DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('is_active'));
    }

    // ---------------------------------------------------------------
    // complete() — canCompleteTask() RBAC + completion workflow
    // ---------------------------------------------------------------

    // 2026-09-30 (by request): completing a task is opened up to ANY
    // Maintenance Staff, not just whoever it's assigned to — plans are
    // visible team-wide and assignment was often left blank, silently
    // hiding the Complete action from everyone. Editing a task's own
    // details stays assigned-only (see canManageTask() tests above).
    public function test_a_maintenance_staff_not_assigned_to_the_task_can_still_complete_it(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $otherStaffId]);

        $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-08-15',
            ])
            ->assertStatus(200);

        $this->assertSame(1, DB::table('preventive_maintenance_history')->count());
    }

    public function test_assigned_maintenance_staff_can_complete_their_own_task(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask([
            'assigned_user_id' => $staffId,
            'frequency' => 'monthly',
            'next_due_date' => '2026-08-01',
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-08-15',
                'notes' => 'Completed monthly check.',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, DB::table('preventive_maintenance_history')->count());

        $historyRow = DB::table('preventive_maintenance_history')->first();
        $this->assertSame('2026-08-15', $historyRow->completed_date);
        $this->assertSame('2026-09-15', $historyRow->next_due_date_snapshot);

        $taskRow = DB::table('preventive_maintenance_tasks')->where('id', $taskId)->first();
        $this->assertSame('2026-08-15', $taskRow->last_completed_date);
        $this->assertSame('2026-09-15', $taskRow->next_due_date);
    }

    public function test_complete_rolls_forward_a_month_end_date_without_overflowing(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask(['frequency' => 'monthly']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-01-31',
            ])
            ->assertStatus(200);

        // addMonthsNoOverflow(1) from Jan 31 -> Feb 28 (2026 non-leap), not
        // an overflowed March 3.
        $this->assertSame('2026-02-28', DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('next_due_date'));
    }

    public function test_complete_notifies_the_task_creator_when_someone_else_completes_it(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId, 'created_by' => $adminId]);

        $this
            ->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-08-15',
            ])
            ->assertStatus(200);

        $this->assertSame(
            1,
            DB::table('notifications')
                ->where('user_id', $adminId)
                ->where('title', 'Preventive Maintenance Completed')
                ->count()
        );
    }

    public function test_complete_does_not_notify_when_the_actor_is_also_the_creator(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask(['created_by' => $adminId]);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-08-15',
            ])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('notifications')->where('user_id', $adminId)->count());
    }

    public function test_complete_rejects_a_missing_completed_date(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask();

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('preventive_maintenance_history')->count());
    }

    public function test_complete_can_be_recorded_multiple_times_appending_history(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask(['frequency' => 'monthly']);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", ['completed_date' => '2026-06-01'])
            ->assertStatus(200);

        $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", ['completed_date' => '2026-07-01'])
            ->assertStatus(200);

        // Append-only — both history rows must still exist, never overwritten.
        $this->assertSame(2, DB::table('preventive_maintenance_history')->count());
    }

    // ---------------------------------------------------------------
    // history()
    // ---------------------------------------------------------------

    public function test_history_returns_rows_ordered_newest_first(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask();

        DB::table('preventive_maintenance_history')->insert([
            'preventive_maintenance_task_id' => $taskId,
            'completed_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('preventive_maintenance_history')->insert([
            'preventive_maintenance_task_id' => $taskId,
            'completed_date' => '2026-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson("/api/preventive-maintenance/{$taskId}/history");

        $response->assertStatus(200);
        $rows = $response->json('data.history.data');
        $this->assertCount(2, $rows);
        // history() relation is orderByDesc('completed_date').
        $this->assertSame('2026-06-01', $rows[0]['completed_date']);
    }

    public function test_history_empty_state_returns_empty_array(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask();

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson("/api/preventive-maintenance/{$taskId}/history");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.history.data'));
    }

    // ---------------------------------------------------------------
    // index() — default scope, filters, search, computed status filter
    // ---------------------------------------------------------------

    public function test_index_empty_state_returns_empty_list(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.data'));
    }

    public function test_index_defaults_to_active_only_hiding_archived_tasks(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->seedTask(['is_active' => true, 'title' => 'Active Task']);
        $this->seedTask(['is_active' => false, 'title' => 'Archived Task']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance');

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('Active Task', $rows[0]['title']);
    }

    public function test_index_is_active_all_includes_archived_tasks(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->seedTask(['is_active' => true]);
        $this->seedTask(['is_active' => false]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance?is_active=all');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_index_filters_by_frequency(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->seedTask(['frequency' => 'monthly']);
        $this->seedTask(['frequency' => 'annually']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance?frequency=annually');

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('annually', $rows[0]['frequency']);
    }

    public function test_index_filters_by_assigned_user_id(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffA = $this->seedUser(['role' => 'maintenance_staff']);
        $staffB = $this->seedUser(['role' => 'maintenance_staff']);
        $this->seedTask(['assigned_user_id' => $staffA]);
        $this->seedTask(['assigned_user_id' => $staffB]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson("/api/preventive-maintenance?assigned_user_id={$staffA}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_search_matches_title_or_category(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->seedTask(['title' => 'Findme Special Task', 'category' => 'Computers']);
        $this->seedTask(['title' => 'Other Task', 'category' => 'Boiler']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance?search=findme');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_status_filter_overdue_matches_a_past_due_date(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->seedTask(['title' => 'Overdue Task', 'next_due_date' => now()->subDays(5)->toDateString()]);
        $this->seedTask(['title' => 'Upcoming Task', 'next_due_date' => now()->addDays(60)->toDateString()]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance?status=overdue');

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('Overdue Task', $rows[0]['title']);
    }

    public function test_index_status_filter_unscheduled_matches_a_null_due_date(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->seedTask(['title' => 'No Due Date', 'next_due_date' => null]);
        $this->seedTask(['title' => 'Has Due Date', 'next_due_date' => now()->addDays(5)->toDateString()]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance?status=unscheduled');

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('No Due Date', $rows[0]['title']);
    }

    public function test_index_status_filter_due_soon_respects_configured_threshold(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        // Default due_soon_days = 14.
        $this->seedTask(['title' => 'Due Soon', 'next_due_date' => now()->addDays(10)->toDateString()]);
        $this->seedTask(['title' => 'Far Upcoming', 'next_due_date' => now()->addDays(30)->toDateString()]);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance?status=due_soon');

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('Due Soon', $rows[0]['title']);
    }

    public function test_index_status_filter_paginates_the_computed_result(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        for ($i = 0; $i < 3; $i++) {
            $this->seedTask(['title' => "Overdue {$i}", 'next_due_date' => now()->subDays(1)->toDateString()]);
        }

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance?status=overdue&per_page=2&page=1');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
        $this->assertSame(3, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.last_page'));
    }

    // ---------------------------------------------------------------
    // summary() and options()
    // ---------------------------------------------------------------

    public function test_summary_counts_active_tasks_by_computed_status(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);
        $this->seedTask(['next_due_date' => now()->subDays(1)->toDateString()]); // overdue
        $this->seedTask(['next_due_date' => now()->addDays(60)->toDateString()]); // upcoming
        $this->seedTask(['is_active' => false, 'next_due_date' => now()->subDays(1)->toDateString()]); // archived, excluded

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance/summary');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.active_plans'));
        $this->assertSame(1, $response->json('data.overdue'));
        $this->assertSame(1, $response->json('data.upcoming'));
    }

    public function test_options_returns_configured_categories_and_frequencies(): void
    {
        $adminId = $this->seedUser(['role' => 'maintenance_admin']);

        $response = $this
            ->actingAsSessionUserWithFlatKeys($adminId, 'maintenance_admin')
            ->getJson('/api/preventive-maintenance/support/options');

        $response->assertStatus(200);
        $this->assertContains('COMPUTERS', $response->json('data.categories'));
        $this->assertArrayHasKey('monthly', $response->json('data.frequencies'));
        $this->assertSame(14, $response->json('data.due_soon_days'));
    }

    // ---------------------------------------------------------------
    // Schema builder + seed helpers
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // 2026-09-27 — Administrator view-only, Head assigns, inspection result
    // ---------------------------------------------------------------

    public function test_administrator_cannot_edit_complete_or_archive_a_task(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $taskId = $this->seedTask();
        $session = fn () => $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin');

        $session()->patchJson("/api/preventive-maintenance/{$taskId}", ['title' => 'Changed'])->assertStatus(403);
        $session()->postJson("/api/preventive-maintenance/{$taskId}/complete", ['completed_date' => '2026-08-15'])->assertStatus(403);
        $session()->postJson("/api/preventive-maintenance/{$taskId}/archive")->assertStatus(403);

        $this->assertSame(0, DB::table('preventive_maintenance_history')->count());
        $this->assertSame(1, (int) DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('is_active'));
    }

    public function test_administrator_can_still_view_the_schedule_and_history(): void
    {
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $taskId = $this->seedTask();

        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->getJson('/api/preventive-maintenance/schedule-grid')->assertStatus(200);
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->getJson("/api/preventive-maintenance/{$taskId}/history")->assertStatus(200);
    }

    public function test_maintenance_staff_can_complete_an_unassigned_task(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => null]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", ['completed_date' => '2026-08-15'])
            ->assertStatus(200);

        $this->assertSame(1, DB::table('preventive_maintenance_history')->count());
    }

    public function test_maintenance_staff_cannot_reassign_their_own_task(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->patchJson("/api/preventive-maintenance/{$taskId}", ['assigned_user_id' => $otherStaffId])
            ->assertStatus(403);

        $this->assertSame($staffId, (int) DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('assigned_user_id'));
    }

    public function test_head_can_bulk_assign_tasks_to_a_staff_member_who_is_notified(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskA = $this->seedTask();
        $taskB = $this->seedTask();

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance/assign', ['task_ids' => [$taskA, $taskB], 'assigned_user_id' => $staffId])
            ->assertStatus(200)
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(2, DB::table('preventive_maintenance_tasks')->where('assigned_user_id', $staffId)->count());
        $this->assertSame(2, DB::table('notifications')->where('user_id', $staffId)->where('title', 'Preventive Maintenance Task Assigned')->count());
    }

    public function test_bulk_assign_is_head_only_and_only_accepts_active_staff(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $otherHeadId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $inactiveStaffId = $this->seedUser(['role' => 'maintenance_staff', 'status' => 'inactive']);
        $adminId = $this->seedUser(['role' => 'super_admin']);
        $taskId = $this->seedTask();
        $payload = ['task_ids' => [$taskId], 'assigned_user_id' => $staffId];

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson('/api/preventive-maintenance/assign', $payload)->assertStatus(403);
        $this->actingAsSessionUserWithFlatKeys($adminId, 'super_admin')
            ->postJson('/api/preventive-maintenance/assign', $payload)->assertStatus(403);
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance/assign', ['task_ids' => [$taskId], 'assigned_user_id' => $otherHeadId])
            ->assertStatus(422);
        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson('/api/preventive-maintenance/assign', ['task_ids' => [$taskId], 'assigned_user_id' => $inactiveStaffId])
            ->assertStatus(422);

        $this->assertNull(DB::table('preventive_maintenance_tasks')->where('id', $taskId)->value('assigned_user_id'));
    }

    public function test_complete_records_a_working_result_without_raising_a_report(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-09-10',
                'condition_result' => 'working',
                'create_repair_report' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.repair_report', null);

        $this->assertSame('working', DB::table('preventive_maintenance_history')->value('condition_result'));
        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    public function test_needs_repair_requires_findings(): void
    {
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-09-10',
                'condition_result' => 'needs_repair',
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('preventive_maintenance_history')->count());
    }

    public function test_needs_repair_raises_a_linked_submitted_report(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'full_name' => 'Staff One']);
        $taskId = $this->seedTask([
            'category' => 'AIR CONDITIONING UNIT (ACU)',
            'title' => 'AIR CONDITIONING UNIT (ACU)',
            'location_name' => 'Offices',
            'assigned_user_id' => $staffId,
        ]);

        $response = $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-10-05',
                'condition_result' => 'needs_repair',
                'findings' => 'Unit in Registrar office not cooling; compressor noisy.',
                'create_repair_report' => true,
                'report_priority' => 'high',
            ]);

        $response->assertStatus(200);
        $reportId = (int) $response->json('data.repair_report.report_id');
        $this->assertGreaterThan(0, $reportId);

        $report = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertSame('submitted', $report->status);
        $this->assertNull($report->assigned_to);
        $this->assertSame('high', $report->priority);
        $this->assertSame('HVAC / Aircon', $report->problem_type);
        $this->assertSame('Offices', $report->location);
        $this->assertSame($staffId, (int) $report->created_by);
        $this->assertStringContainsString('not cooling', $report->description);

        $history = DB::table('preventive_maintenance_history')->first();
        $this->assertSame('needs_repair', $history->condition_result);
        $this->assertSame($reportId, (int) $history->maintenance_report_id);

        // Head is told about the new report through the normal report path.
        $this->assertSame(1, DB::table('notifications')->where('user_id', $headId)->where('entity_type', 'report')->where('entity_id', $reportId)->count());
    }

    public function test_repair_report_can_be_raised_later_but_only_once(): void
    {
        $headId = $this->seedUser(['role' => 'maintenance_admin']);
        $taskId = $this->seedTask(['category' => 'COMPUTERS', 'location_name' => 'Printers']);

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", [
                'completed_date' => '2026-09-10',
                'condition_result' => 'needs_repair',
                'findings' => 'Printer 2 jams on every page.',
                'create_repair_report' => false,
            ])
            ->assertStatus(200);

        $historyId = (int) DB::table('preventive_maintenance_history')->value('id');
        $this->assertSame(0, DB::table('maintenance_reports')->count());

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/history/{$historyId}/repair-report", ['priority' => 'medium'])
            ->assertStatus(201);

        $report = DB::table('maintenance_reports')->first();
        // No close match for COMPUTERS -> "Other" with the equipment name.
        $this->assertSame('Other', $report->problem_type);
        $this->assertSame('COMPUTERS', $report->problem_type_other);

        $this->actingAsSessionUserWithFlatKeys($headId, 'maintenance_admin')
            ->postJson("/api/preventive-maintenance/history/{$historyId}/repair-report")
            ->assertStatus(422);
        $this->assertSame(1, DB::table('maintenance_reports')->count());
    }

    public function test_repair_report_is_refused_for_a_working_inspection_even_from_other_staff(): void
    {
        // 2026-09-30: repair-report shares canCompleteTask()'s permission,
        // which now allows ANY maintenance_staff — so $otherStaffId is no
        // longer blocked at the 403 permission gate. Both actors instead hit
        // the same 422 validation refusal because the recorded inspection was
        // "working", not "needs_repair".
        $staffId = $this->seedUser(['role' => 'maintenance_staff']);
        $otherStaffId = $this->seedUser(['role' => 'maintenance_staff']);
        $taskId = $this->seedTask(['assigned_user_id' => $staffId]);

        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/{$taskId}/complete", ['completed_date' => '2026-09-10', 'condition_result' => 'working'])
            ->assertStatus(200);
        $historyId = (int) DB::table('preventive_maintenance_history')->value('id');

        $this->actingAsSessionUserWithFlatKeys($otherStaffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/history/{$historyId}/repair-report")
            ->assertStatus(422);
        $this->actingAsSessionUserWithFlatKeys($staffId, 'maintenance_staff')
            ->postJson("/api/preventive-maintenance/history/{$historyId}/repair-report")
            ->assertStatus(422);

        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('preventive_maintenance_history');
        Schema::dropIfExists('preventive_maintenance_tasks');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createBuildingsTable();
        $this->createFloorsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createPreventiveMaintenanceTasksTable();
        $this->createPreventiveMaintenanceHistoryTable();
        $this->createMaintenanceReportsTable();

        Schema::enableForeignKeyConstraints();
    }

    private function createBuildingsTable(): void
    {
        Schema::create('buildings', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

    private function createFloorsTable(): void
    {
        Schema::create('floors', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    private function createRoomsTable(): void
    {
        Schema::create('rooms', function ($table): void {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
    }

    private function createNotificationsTable(): void
    {
        Schema::create('notifications', function ($table): void {
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
    }

    private function createPreventiveMaintenanceTasksTable(): void
    {
        Schema::create('preventive_maintenance_tasks', function ($table): void {
            $table->increments('id');
            $table->string('category', 100);
            $table->string('title', 255);
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->string('location_name', 255)->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('assigned_user_id')->nullable();
            $table->string('frequency', 20);
            $table->json('scheduled_months')->nullable();
            $table->date('last_completed_date')->nullable();
            $table->date('next_due_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createPreventiveMaintenanceHistoryTable(): void
    {
        Schema::create('preventive_maintenance_history', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('preventive_maintenance_task_id');
            $table->date('completed_date');
            $table->unsignedInteger('performed_by')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->text('notes')->nullable();
            $table->text('findings')->nullable();
            $table->text('action_taken')->nullable();
            // 2026_09_27_000100_add_inspection_result_to_preventive_maintenance_history
            $table->string('condition_result', 20)->nullable();
            $table->unsignedInteger('maintenance_report_id')->nullable();
            $table->string('completion_proof_path', 500)->nullable();
            $table->date('next_due_date_snapshot')->nullable();
            $table->timestamps();
        });
    }

    private function seedBuilding(array $overrides = []): int
    {
        return DB::table('buildings')->insertGetId(array_merge([
            'name' => 'Building ' . uniqid('', true),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedTask(array $overrides = []): int
    {
        return DB::table('preventive_maintenance_tasks')->insertGetId(array_merge([
            'category' => 'Computers',
            'title' => 'Test PM Task ' . uniqid('', true),
            'item_id' => null,
            'building_id' => null,
            'floor_id' => null,
            'room_id' => null,
            'department_id' => null,
            'assigned_user_id' => null,
            'frequency' => 'monthly',
            'last_completed_date' => null,
            'next_due_date' => null,
            'is_active' => true,
            'notes' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
