<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSharedTestSchema;
use Tests\Support\ConfiguresIsolatedSqliteConnection;
use Tests\Support\InteractsWithLegacySession;
use Tests\TestCase;

/**
 * TASK 33 PHASE 7 — Unified Create Report Capability Completion.
 *
 * Additive coverage for the unified Create Report page's asset sub-form
 * ("Report Against a Specific Asset") now sending severity_level and
 * damage_image, same as damage-report-create.php already did. Both fields
 * were already accepted end-to-end by ReportController::store() ->
 * storeWithAssetDetails() -> DamageReportService::createReport() before this
 * phase (see ReportController.php validation and Sprint 5's doc comment on
 * storeWithAssetDetails()) — this suite exercises that existing branch
 * through the exact POST /api/reports contract the frontend now uses, since
 * no prior test file posted item_id+room_id+severity_level (or an image)
 * to /api/reports specifically (DamageReportControllerTest only covers the
 * separate /api/damage-reports endpoint).
 *
 * No production behavior changed by this phase — these tests prove existing
 * behavior, they do not pin down new behavior.
 */
class ReportCreateAssetSeverityImageTest extends TestCase
{
    use BuildsSharedTestSchema;
    use ConfiguresIsolatedSqliteConnection;
    use InteractsWithLegacySession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase('report_create_asset_severity_image_testing');
        $this->createTestSchema();

        $this->forceLocalTestUrl();
    }

    // ---------------------------------------------------------------
    // 1. Create Report still works (general, non-asset path unaffected)
    // ---------------------------------------------------------------

    public function test_general_report_creation_without_asset_fields_still_works(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'Furniture',
                'title' => 'Broken Chair',
                'description' => 'Chair leg is broken.',
                'location' => 'Room 101',
                'priority' => 'medium',
                'department_id' => $deptId,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, DB::table('maintenance_reports')->count());
        // The general path never touches damage_reports at all.
        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // 2. Asset report accepts valid severity
    // ---------------------------------------------------------------

    public function test_asset_linked_report_accepts_a_valid_severity_level(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'critical',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.damage_report_id', fn ($v) => $v !== null);

        $this->assertSame(1, DB::table('damage_reports')->count());
        $damageRow = DB::table('damage_reports')->first();
        $this->assertSame('critical', $damageRow->severity_level);

        // The linked maintenance_reports row keeps the caller's own title,
        // per storeWithAssetDetails()'s doc comment.
        $this->assertSame(1, DB::table('maintenance_reports')->count());
        $reportRow = DB::table('maintenance_reports')->first();
        $this->assertSame('Aircon not cooling', $reportRow->title);
    }

    // ---------------------------------------------------------------
    // 3. Invalid severity is rejected
    // ---------------------------------------------------------------

    public function test_asset_linked_report_rejects_an_invalid_severity_level(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'catastrophic', // not one of low/medium/high/critical
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    // ---------------------------------------------------------------
    // 4. Image upload works (backend already supports it)
    // ---------------------------------------------------------------

    public function test_asset_linked_report_accepts_a_valid_image_and_persists_its_path(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->post('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
                'damage_image' => $this->makeRealJpegUploadedFile(),
            ]);

        $response->assertStatus(201);
        $damageRow = DB::table('damage_reports')->first();
        $this->assertNotNull($damageRow->image_path);
        $this->assertStringStartsWith('/frontend/uploads/damage-reports/', $damageRow->image_path);

        $absolutePath = public_path(ltrim($damageRow->image_path, '/'));
        $this->assertFileExists($absolutePath);
        // Test-only cleanup — DamageReportService::storeImage() hardcodes
        // public_path(), same as DamageReportControllerTest's own image test.
        @unlink($absolutePath);
    }

    public function test_asset_linked_report_rejects_a_disallowed_file_extension_for_the_image(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->post('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
                'damage_image' => \Illuminate\Http\UploadedFile::fake()->create('malware.exe', 100),
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // TASK 33 PHASE 9 — Repair Notes, the last page-level capability gap
    // identified by Phase 8's equivalence verification against
    // damage-report-create.php. repair_notes was already accepted end-to-end
    // by ReportController::store() -> storeWithAssetDetails() ->
    // DamageReportService::createReport() before this phase (the same
    // pre-existing 'repair_notes' => ['nullable', 'string'] validation rule
    // used for severity_level/damage_image in Phase 7's tests above) — only
    // the frontend field was missing. These tests prove the existing backend
    // behavior through the same /api/reports contract the frontend now uses.
    // ---------------------------------------------------------------

    public function test_asset_linked_report_accepts_repair_notes_and_persists_it(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
                'repair_notes' => 'Compressor may need replacing; checked filter first.',
            ]);

        $response->assertStatus(201);
        $this->assertSame(1, DB::table('damage_reports')->count());
        $damageRow = DB::table('damage_reports')->first();
        $this->assertSame('Compressor may need replacing; checked filter first.', $damageRow->repair_notes);
        // Severity Level, sent in the same request, must still work unaffected.
        $this->assertSame('high', $damageRow->severity_level);
    }

    public function test_asset_linked_report_without_repair_notes_stores_null_matching_legacy_optional_behavior(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        // No repair_notes key at all — mirrors damage-report-create.php's
        // own submit handler, which only appends repair_notes to its
        // FormData when the trimmed value is non-empty (see
        // damage-report-create.php: `if (repairNotes) formData.append(...)`).
        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'medium',
            ]);

        $response->assertStatus(201);
        $damageRow = DB::table('damage_reports')->first();
        $this->assertNull($damageRow->repair_notes);
    }

    // ---------------------------------------------------------------
    // 5. Existing report categories are unaffected — need_change path
    //    (a different optional sub-form on the same page) still ignores
    //    severity_level/damage_image entirely.
    // ---------------------------------------------------------------

    public function test_need_change_report_creation_is_unaffected_by_the_new_fields(): void
    {
        $deptId = $this->seedDepartment();
        $staffId = $this->seedUser(['role' => 'maintenance_staff', 'department_id' => $deptId]);
        $stockItemId = $this->seedItem(['item_type' => 'inventory_stock', 'quantity' => 5]);

        $response = $this
            ->actingAsSessionUser($staffId, 'maintenance_staff')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Need replacement filter',
                'description' => 'Filter needs replacing.',
                'location' => 'Room 305',
                'priority' => 'medium',
                'department_id' => $deptId,
                'need_change_item_id' => $stockItemId,
            ]);

        $response->assertStatus(201);
        $this->assertSame(1, DB::table('maintenance_reports')->count());
        // The general (non-asset) path never touches damage_reports — this
        // confirms the new severity_level/damage_image handling added to
        // storeWithAssetDetails() has no bearing on this branch at all.
        $this->assertSame(0, DB::table('damage_reports')->count());

        // NOTE: ReportController::store()'s general branch does not persist
        // need_change_item_id itself (that field is only validated/applied
        // by update(), not store() — pre-existing behavior, unrelated to
        // and unchanged by this phase). This test only asserts that
        // supplying it alongside the new fields doesn't error or leak into
        // damage_reports, not that it's stored at creation time.
        $reportRow = DB::table('maintenance_reports')->first();
        $this->assertNull($reportRow->need_change_item_id);
    }

    // ---------------------------------------------------------------
    // 6. Existing RBAC remains unchanged — the asset-linked branch is
    //    gated by the exact same EnsureRole middleware as the general path.
    // ---------------------------------------------------------------

    public function test_super_admin_cannot_create_an_asset_linked_report(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'super_admin', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($adminId, 'super_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'high',
            ])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('damage_reports')->count());
        $this->assertSame(0, DB::table('maintenance_reports')->count());
    }

    public function test_maintenance_admin_can_create_an_asset_linked_report(): void
    {
        $deptId = $this->seedDepartment();
        $adminId = $this->seedUser(['role' => 'maintenance_admin', 'department_id' => $deptId]);
        $roomId = $this->seedRoom();
        $itemId = $this->seedItem(['item_type' => 'room_asset', 'room_id' => $roomId]);

        $this
            ->actingAsSessionUser($adminId, 'maintenance_admin')
            ->postJson('/api/reports', [
                'problem_type' => 'HVAC / Aircon',
                'title' => 'Aircon not cooling',
                'description' => 'Unit runs but no cold air.',
                'location' => 'Room 204',
                'priority' => 'high',
                'department_id' => $deptId,
                'item_id' => $itemId,
                'room_id' => $roomId,
                'severity_level' => 'medium',
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('damage_reports')->count());
    }

    // ---------------------------------------------------------------
    // Schema / seeding — mirrors DamageReportControllerTest's isolated
    // in-memory schema, since this suite exercises the same underlying
    // tables through a different endpoint (/api/reports instead of
    // /api/damage-reports).
    // ---------------------------------------------------------------

    private function createTestSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('maintenance_reports');
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('users');

        $this->createUsersTable();
        $this->createDepartmentsTable();
        $this->createRoomsTable();
        $this->createItemsTable();
        $this->createMaintenanceReportsTable();
        $this->addMaintenanceReportAssetColumns();
        $this->createInventoryTransactionsTable();
        $this->createActivityLogsTable();
        $this->createNotificationsTable();
        $this->createDamageReportsTable();
        $this->createDamageReportHistoriesTable();

        Schema::enableForeignKeyConstraints();
    }

    private function addMaintenanceReportAssetColumns(): void
    {
        Schema::table('maintenance_reports', function ($table): void {
            $table->string('report_category', 30)->default('general')->after('description');
            $table->unsignedInteger('item_id')->nullable()->after('report_category');
            $table->unsignedBigInteger('source_dispatch_id')->nullable()->after('item_id');
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

    private function createDamageReportsTable(): void
    {
        Schema::create('damage_reports', function ($table): void {
            $table->bigIncrements('id');
            $table->string('damage_report_code')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedBigInteger('source_dispatch_id')->nullable();
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
    }

    private function createDamageReportHistoriesTable(): void
    {
        Schema::create('damage_report_histories', function ($table): void {
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
    }

    private function seedDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'name' => 'Department ' . uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'department_id');
    }

    private function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Room ' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * The GD extension is not installed in this environment, so
     * UploadedFile::fake()->image() (which requires imagecreatetruecolor())
     * cannot be used to exercise the store() endpoint's 'image' validation
     * rule, which inspects actual file content rather than the declared
     * extension. A minimal, valid, real 1x1 JPEG is written to a temp file
     * and wrapped as a test UploadedFile instead — copied verbatim from
     * DamageReportControllerTest::makeRealJpegUploadedFile().
     */
    private function makeRealJpegUploadedFile(): \Illuminate\Http\UploadedFile
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIy'
            . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIA'
            . 'AhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEB'
            . 'AQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX'
            . '/9k='
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'dmgtest') . '.jpg';
        file_put_contents($tmpPath, $bytes);

        return new \Illuminate\Http\UploadedFile($tmpPath, 'damage.jpg', 'image/jpeg', null, true);
    }
}
