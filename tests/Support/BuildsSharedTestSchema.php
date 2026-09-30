<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared table builders and default-row seeders for the self-contained,
 * in-memory-SQLite Feature tests. Each test file still composes its own
 * createTestSchema() (dropping and creating only the tables it needs, in
 * its own order) — this trait just removes the byte-for-byte-duplicated
 * Blueprint/seed definitions that were repeated across those files.
 */
trait BuildsSharedTestSchema
{
    protected function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('full_name');
            $table->string('username', 100)->nullable()->unique();
            // Nullable to match the real schema. Migration
            // 2026_09_08_000100_add_out_of_band_user_and_report_columns
            // relaxes users.email to NULL because the Add New User modal has
            // no email field at all and UserController::store() persists null
            // when none is supplied. Leaving this NOT NULL made every test
            // that actually completes a POST /api/users fail with an SQLite
            // integrity-constraint error instead of exercising the endpoint.
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('role', 50)->default('maintenance_admin');
            $table->unsignedInteger('department_id')->nullable();
            $table->string('status', 50)->default('active');
            $table->string('avatar')->nullable();
            // Present in the real schema since migration
            // 2026_09_08_000100_add_out_of_band_user_and_report_columns and
            // written by UserController::store(); it was simply missing from
            // this shared blueprint. Nullable, matching that migration.
            $table->string('designation', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * TASK 60 — Dispatch Create simplified to a single location source.
     * Room / Lab (rooms.id) became the sole, required location field on
     * POST /api/dispatches once the free-text room_note field was removed
     * from the Create form, so 'exists:rooms,id' in DispatchController::
     * store() needs an actual rooms table to query against — no dispatch
     * test exercised room_id before this, so none had one. Deliberately
     * minimal (no buildings/floors FKs, unlike the real
     * 2026_03_27_000300_create_facility_tables migration): only 'name' is
     * ever read back, via Dispatch::getRoomNameAttribute()'s $this->room?->name.
     */
    protected function createRoomsTable(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id')->nullable();
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->integer('capacity')->nullable();
            $table->timestamps();
        });
    }

    protected function seedRoom(array $overrides = []): int
    {
        return DB::table('rooms')->insertGetId(array_merge([
            'name' => 'Test Room',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function createItemsTable(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('inventory_room_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('item_type', 50)->default('inventory_stock');
            $table->string('name');
            // asset_code: read by BuildingController::deployedItems() (Per
            // Room Report reuse fix) and its RBAC test. Nullable to match the
            // real items table, so existing seedItem() callers that don't set
            // it keep working unchanged.
            $table->string('asset_code')->nullable();
            $table->string('unit_type')->nullable();
            $table->string('item_condition')->nullable();
            $table->string('status', 50)->default('available');
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->integer('low_stock_threshold_override')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    protected function createInventoryTransactionsTable(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('item_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->string('transaction_type', 50);
            $table->integer('quantity');
            $table->text('reference_note')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->timestamps();
        });
    }

    protected function createActivityLogsTable(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('user_role', 100)->nullable();
            $table->string('action', 100);
            $table->string('module', 100)->nullable();
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('meta_json')->nullable();
            $table->string('dedupe_key', 64)->nullable();
            $table->timestamps();
        });
    }

    protected function createDepartmentsTable(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->increments('department_id');
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    protected function createMaintenanceReportsTable(): void
    {
        Schema::create('maintenance_reports', function (Blueprint $table): void {
            $table->increments('report_id');
            $table->string('title');
            $table->longText('description');
            // Problem Type (2026_09_20_000100_add_problem_type_to_maintenance_reports_table).
            // Nullable here for the same reason it is nullable in the real
            // migration: existing rows and the internal creators (PM, dispatch,
            // asset-damage) carry no problem type, and "required" is enforced
            // at the Create Report boundary rather than by the schema.
            $table->string('problem_type', 50)->nullable();
            $table->string('problem_type_other', 100)->nullable();
            $table->string('location')->nullable();
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('submitted');
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('assigned_to')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->unsignedInteger('need_change_item_id')->nullable();
            $table->integer('need_change_quantity')->default(1);
            $table->string('need_change_status', 50)->nullable();
            $table->unsignedInteger('need_change_approved_by')->nullable();
            $table->dateTime('need_change_approved_at')->nullable();
            $table->dateTime('need_change_deducted_at')->nullable();
            // Disposal Archive (2026_09_30_000300_add_disposal_fields_to_maintenance_reports_table).
            $table->unsignedInteger('need_change_disposed_by')->nullable();
            $table->dateTime('need_change_disposed_at')->nullable();
            $table->string('need_change_disposal_notes', 255)->nullable();
            $table->string('completion_proof_image')->nullable();
            $table->timestamps();
        });
    }

    /**
     * TASK 13 — the dispatches/dispatch_items blueprints were duplicated
     * byte-for-byte across every dispatch Feature test, so adding a column to
     * the real schema meant editing three files and silently failing wherever
     * one was missed. They live here now, mirroring the real migrations:
     * the base dispatches table plus Task 3's audit columns, Task 4's
     * release_remarks, and Task 13's release_assigned_* assignment columns
     * (2026_08_03_000100_add_release_assignment_to_dispatches_table).
     */
    protected function createDispatchesTable(): void
    {
        Schema::create('dispatches', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('dispatch_code', 50)->unique();
            $table->unsignedInteger('department_id')->nullable();
            // TASK 3 — Dispatch Personnel Audit Trail.
            $table->unsignedInteger('requested_by')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('released_by')->nullable();
            // TASK 4 — Approve & Release Workflow.
            $table->text('release_remarks')->nullable();
            // TASK 13 — Dispatch Release Assignment Workflow.
            $table->unsignedInteger('release_assigned_to')->nullable();
            $table->unsignedInteger('release_assigned_by')->nullable();
            $table->timestamp('release_assigned_at')->nullable();
            $table->unsignedInteger('receiver_user_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->string('room_note', 500)->nullable();
            $table->unsignedInteger('repair_request_id')->nullable();
            $table->unsignedInteger('damage_report_id')->nullable();
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('purchase_receipt_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    protected function createDispatchItemsTable(): void
    {
        Schema::create('dispatch_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('dispatch_id');
            $table->unsignedInteger('item_id');
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    protected function seedUser(array $overrides = []): int
    {
        return DB::table('users')->insertGetId(array_merge([
            'full_name' => 'Test User',
            'email' => 'user' . uniqid('', true) . '@example.com',
            'password' => 'secret',
            'role' => 'maintenance_admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'user_id');
    }

    protected function seedItem(array $overrides = []): int
    {
        return DB::table('items')->insertGetId(array_merge([
            'room_id' => null,
            'inventory_room_id' => null,
            'category_id' => null,
            'item_type' => 'inventory_stock',
            'name' => 'Test Item',
            'status' => 'available',
            'quantity' => 0,
            'reserved_quantity' => 0,
            'reorder_level' => 0,
            'unit_type' => 'pc',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
