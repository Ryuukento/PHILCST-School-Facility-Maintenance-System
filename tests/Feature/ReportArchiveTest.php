<?php

namespace Tests\Feature;

use App\Models\MaintenanceReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * Report Archive — past-term reports by academic session.
 *
 * "Today" is pinned to 2026-09-26, inside First Semester 2026–2027
 * (2026-07-01 .. 2027-02-28), so the archive cutoff is 2026-07-01:
 *   - a finished report filed before it is view-only (locked);
 *   - an unfinished one is "carried over" and stays actionable;
 *   - the Administrator (only) can reopen a locked one, and it locks again
 *     when finished again or via "lock again".
 */
class ReportArchiveTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    private int $adminId;
    private int $headId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-26 10:00:00');

        $this->useInMemoryDatabase('report_archive_testing');
        $this->createSchema();
        $this->forceLocalTestUrl();

        $this->adminId = $this->seedUser(['role' => 'super_admin', 'full_name' => 'Ryan Mondido']);
        $this->headId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => null]);

        $this->seedSession('2025-2026', '2025-07-01', '2026-02-28', '2026-03-01', '2026-06-30');
        $this->seedSession('2026-2027', '2026-07-01', '2027-02-28', '2027-03-01', '2027-07-31');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── View-only enforcement ─────────────────────────────────────────────

    public function test_a_finished_report_from_a_past_term_cannot_be_edited_by_anyone(): void
    {
        $reportId = $this->seedReport(['status' => 'completed', 'created_at' => '2026-05-10 09:00:00']);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['priority' => 'high'])
            ->assertStatus(423);

        $this->assertSame('medium', DB::table('maintenance_reports')->where('report_id', $reportId)->value('priority'));
    }

    public function test_a_finished_report_from_a_past_term_cannot_be_deleted(): void
    {
        $reportId = $this->seedReport(['status' => 'closed', 'created_at' => '2026-05-10 09:00:00']);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->deleteJson("/api/reports/{$reportId}")
            ->assertStatus(423);

        $this->assertTrue(DB::table('maintenance_reports')->where('report_id', $reportId)->exists());
    }

    public function test_an_unfinished_report_from_a_past_term_is_carried_over_and_stays_editable(): void
    {
        $reportId = $this->seedReport(['status' => 'submitted', 'created_at' => '2026-05-10 09:00:00']);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['priority' => 'high'])
            ->assertOk();

        $this->assertSame('high', DB::table('maintenance_reports')->where('report_id', $reportId)->value('priority'));
    }

    public function test_a_finished_report_from_the_current_term_is_not_archived(): void
    {
        $reportId = $this->seedReport(['status' => 'completed', 'created_at' => '2026-08-01 09:00:00']);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['priority' => 'high'])
            ->assertOk();
    }

    public function test_without_any_recorded_academic_session_nothing_is_archived(): void
    {
        DB::table('academic_sessions')->delete();
        $reportId = $this->seedReport(['status' => 'completed', 'created_at' => '2024-01-10 09:00:00']);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['priority' => 'high'])
            ->assertOk();
    }

    // ── Administrator reopen / lock again ─────────────────────────────────

    public function test_only_the_administrator_can_reopen_an_archived_report(): void
    {
        $reportId = $this->seedReport(['status' => 'completed', 'created_at' => '2026-05-10 09:00:00']);

        $this->actingAsSessionUser($this->headId, 'maintenance_admin')
            ->postJson("/api/reports/{$reportId}/archive-reopen")
            ->assertStatus(403);

        $this->assertNull(DB::table('maintenance_reports')->where('report_id', $reportId)->value('archive_reopened_at'));
    }

    public function test_reopening_unlocks_the_report_and_is_logged(): void
    {
        $reportId = $this->seedReport(['status' => 'completed', 'created_at' => '2026-05-10 09:00:00']);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->postJson("/api/reports/{$reportId}/archive-reopen")
            ->assertOk()
            ->assertJsonPath('data.archive.is_locked', false)
            ->assertJsonPath('data.archive.is_reopened', true);

        $this->assertSame($this->adminId, (int) DB::table('maintenance_reports')->where('report_id', $reportId)->value('archive_reopened_by'));
        $this->assertTrue(DB::table('activity_logs')->where('action', 'REOPEN_ARCHIVED_REPORT')->where('entity_id', $reportId)->exists());

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['priority' => 'high'])
            ->assertOk();
    }

    public function test_lock_again_makes_a_reopened_report_view_only_and_is_logged(): void
    {
        $reportId = $this->seedReport([
            'status' => 'completed',
            'created_at' => '2026-05-10 09:00:00',
            'archive_reopened_at' => '2026-09-25 09:00:00',
            'archive_reopened_by' => $this->adminId,
        ]);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->postJson("/api/reports/{$reportId}/archive-lock")
            ->assertOk()
            ->assertJsonPath('data.archive.is_locked', true);

        $this->assertTrue(DB::table('activity_logs')->where('action', 'LOCK_ARCHIVED_REPORT')->where('entity_id', $reportId)->exists());

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->patchJson("/api/reports/{$reportId}", ['priority' => 'high'])
            ->assertStatus(423);
    }

    public function test_a_reopened_report_locks_again_when_it_is_finished_again(): void
    {
        $reportId = $this->seedReport([
            'status' => 'in_progress',
            'created_at' => '2026-05-10 09:00:00',
            'archive_reopened_at' => '2026-09-25 09:00:00',
            'archive_reopened_by' => $this->adminId,
        ]);

        MaintenanceReport::query()->findOrFail($reportId)->update(['status' => 'completed']);

        $row = DB::table('maintenance_reports')->where('report_id', $reportId)->first();
        $this->assertNull($row->archive_reopened_at);
        $this->assertNull($row->archive_reopened_by);
    }

    // ── Archive state in the API ──────────────────────────────────────────

    public function test_the_report_list_and_detail_carry_the_archive_state_and_term(): void
    {
        $lockedId = $this->seedReport(['status' => 'completed', 'created_at' => '2026-05-10 09:00:00']);
        $carriedId = $this->seedReport(['status' => 'in_progress', 'created_at' => '2025-09-10 09:00:00']);

        $list = $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->getJson('/api/reports?per_page=50')
            ->assertOk()
            ->json('data.reports');

        $byId = collect($list)->keyBy('report_id');
        $this->assertTrue($byId[$lockedId]['archive']['is_locked']);
        $this->assertSame('Second Semester 2025–2026', $byId[$lockedId]['archive']['term_label']);
        $this->assertTrue($byId[$carriedId]['archive']['is_carried_over']);
        $this->assertFalse($byId[$carriedId]['archive']['is_locked']);
        $this->assertSame('First Semester 2025–2026', $byId[$carriedId]['archive']['term_label']);

        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->getJson("/api/reports/{$lockedId}")
            ->assertOk()
            ->assertJsonPath('data.report.archive.is_locked', true);
    }

    // ── Academic session history (Manage Academic Session) ────────────────

    public function test_saving_manage_academic_session_records_the_school_year_history(): void
    {
        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->putJson('/api/school-settings', [
                'school_year' => '2027-2028',
                'first_sem_start' => '2027-08-01',
                'first_sem_end' => '2027-12-20',
                'second_sem_start' => '2028-01-05',
                'second_sem_end' => '2028-05-30',
            ])
            ->assertOk();

        $this->assertSame(3, DB::table('academic_sessions')->count());
        $this->assertTrue(DB::table('academic_sessions')->where('school_year', '2026-2027')->exists(), 'Earlier school years stay in the history.');
    }

    public function test_a_school_year_label_typo_fix_renames_the_history_row_instead_of_duplicating_it(): void
    {
        $this->actingAsSessionUser($this->adminId, 'super_admin')
            ->putJson('/api/school-settings', [
                'school_year' => '2026-2027 SY',
                'first_sem_start' => '2026-07-01',
                'first_sem_end' => '2027-02-28',
                'second_sem_start' => '2027-03-01',
                'second_sem_end' => '2027-07-31',
            ])
            ->assertOk();

        $this->assertSame(2, DB::table('academic_sessions')->count());
        $this->assertTrue(DB::table('academic_sessions')->where('school_year', '2026-2027 SY')->exists());
    }

    public function test_the_academic_sessions_endpoint_lists_school_years_newest_first(): void
    {
        $this->seedReport(['created_at' => '2024-12-01 09:00:00']);

        $this->actingAsSessionUser($this->headId, 'maintenance_admin')
            ->getJson('/api/academic-sessions')
            ->assertOk()
            ->assertJsonPath('data.archive_cutoff', '2026-07-01')
            ->assertJsonPath('data.school_years.0.school_year', '2026-2027')
            ->assertJsonPath('data.school_years.1.label', 'SY 2025–2026')
            ->assertJsonPath('data.school_years.1.semesters.1.semester', 'Second Semester')
            ->assertJsonPath('data.has_earlier_reports', true);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function createSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['notifications', 'inventory_transactions', 'activity_logs', 'maintenance_reports', 'items',
                  'departments', 'users', 'academic_sessions', 'school_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->timestamp('archive_reopened_at')->nullable();
            $table->unsignedInteger('archive_reopened_by')->nullable();
        });

        Schema::create('academic_sessions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('school_year', 20)->unique();
            $table->date('first_sem_start');
            $table->date('first_sem_end');
            $table->date('second_sem_start');
            $table->date('second_sem_end');
            $table->timestamps();
        });

        Schema::create('school_settings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('school_year', 20)->default('2026-2027');
            $table->string('current_semester', 30)->nullable();
            $table->date('first_sem_start')->nullable();
            $table->date('first_sem_end')->nullable();
            $table->date('second_sem_start')->nullable();
            $table->date('second_sem_end')->nullable();
            $table->timestamp('semester_started_at')->nullable();
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

        Schema::enableForeignKeyConstraints();
    }

    private function seedSession(string $schoolYear, string $s1, string $e1, string $s2, string $e2): void
    {
        DB::table('academic_sessions')->insert([
            'school_year' => $schoolYear,
            'first_sem_start' => $s1,
            'first_sem_end' => $e1,
            'second_sem_start' => $s2,
            'second_sem_end' => $e2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReport(array $overrides = []): int
    {
        return DB::table('maintenance_reports')->insertGetId(array_merge([
            'title' => 'Broken Chair',
            'description' => 'Chair leg is broken.',
            'location' => 'Room 101',
            'priority' => 'medium',
            'status' => 'submitted',
            'created_by' => $this->headId ?? $this->seedUser(),
            'assigned_to' => null,
            'department_id' => null,
            'need_change_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'report_id');
    }
}
