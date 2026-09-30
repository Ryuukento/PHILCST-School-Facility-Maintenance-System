<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preventive Maintenance module — new, additive tables only. No existing
 * table is altered. See PREVENTIVE_MAINTENANCE_INVESTIGATION.md (chat report)
 * for the read-only investigation that preceded this schema.
 *
 * Design notes:
 *  - item_id/building_id/floor_id/room_id are ALL independent and nullable.
 *    A PM task may reference a specific inventory/room asset (item_id) when
 *    the source table names a specific physical unit, OR just a
 *    building/floor/room location when it represents a general equipment
 *    category (e.g. "Fire Alarm System" for a whole building) — per the
 *    task instruction not to force every record to require an asset_code.
 *  - status is intentionally NOT a stored column. Upcoming / Due Soon / Due /
 *    Overdue are derived at read-time from next_due_date vs today() (see
 *    PreventiveMaintenanceTask::getStatusAttribute()), using the
 *    configurable due_soon_days threshold in config/preventive_maintenance.php.
 *    Completed is not a task-level state at all — it is represented by the
 *    existence of history rows; the task itself simply rolls forward to its
 *    next occurrence. This avoids an awkward "was Completed, now needs to
 *    become Upcoming again next cycle" state-transition edge case.
 *  - is_active + soft-deletes give archive-not-delete semantics: is_active
 *    lets Head Maintenance/Admin hide a task from the active list without
 *    losing it or its history, and deleted_at is reserved for a genuine
 *    future "remove by mistake" undo path. No hard-delete route is exposed.
 *  - preventive_maintenance_history is intentionally append-only (no
 *    updated_at meaningfully used for edits, no destructive update path in
 *    the service layer) so historical completions are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('preventive_maintenance_tasks')) {
            Schema::create('preventive_maintenance_tasks', function (Blueprint $table): void {
                $table->increments('id');

                // Fixed category dropdown (Roof Top, Water Pump (Jetmatic),
                // Pipe Earth Grounding, Fire Alarm System, Computers, Boiler,
                // Diesel Engine, Hydraulic Equipment, Process Control
                // Equipment, Air Conditioning Unit (ACU), Generator, Electric
                // Fans/Ceiling Fans) + "Other" — enforced in the controller's
                // validation rules, not a DB enum, so the "Other" free-text
                // case doesn't require a migration to extend later.
                $table->string('category', 100);

                // Free-text label for the specific activity/equipment, e.g.
                // "ACU – Room 2-6" or "Fire Alarm System – Lourdes Bldg 4".
                $table->string('title', 255);

                // Optional link to a specific existing inventory/room asset.
                $table->unsignedInteger('item_id')->nullable();

                // Optional location, independent of item_id — lets a PM
                // record represent a general equipment category tied to a
                // building/floor/room without requiring a specific item row.
                $table->unsignedInteger('building_id')->nullable();
                $table->unsignedInteger('floor_id')->nullable();
                $table->unsignedInteger('room_id')->nullable();

                $table->unsignedInteger('department_id')->nullable();
                $table->unsignedInteger('assigned_user_id')->nullable();

                $table->enum('frequency', ['monthly', 'quarterly', 'semi_annually', 'annually']);

                $table->date('last_completed_date')->nullable();
                $table->date('next_due_date')->nullable();

                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();

                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
                $table->foreign('building_id')->references('id')->on('buildings')->nullOnDelete();
                $table->foreign('floor_id')->references('id')->on('floors')->nullOnDelete();
                $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
                $table->foreign('department_id')->references('department_id')->on('departments')->nullOnDelete();
                $table->foreign('assigned_user_id')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('user_id')->on('users')->nullOnDelete();

                $table->index(['is_active', 'next_due_date']);
                $table->index(['assigned_user_id']);
                $table->index(['department_id']);
                $table->index(['building_id', 'floor_id', 'room_id']);
            });
        }

        if (!Schema::hasTable('preventive_maintenance_history')) {
            Schema::create('preventive_maintenance_history', function (Blueprint $table): void {
                $table->increments('id');

                $table->unsignedInteger('preventive_maintenance_task_id');

                $table->date('completed_date');
                $table->unsignedInteger('performed_by')->nullable();
                $table->unsignedInteger('recorded_by')->nullable();

                $table->text('notes')->nullable();
                $table->text('findings')->nullable();

                // Reuses DamageReportService::storeImage()'s upload
                // convention (mimes:jpg,jpeg,png,webp,gif, max 5MB) — stored
                // path only, no BLOBs in the DB.
                $table->string('completion_proof_path', 500)->nullable();

                // Snapshot of the next_due_date computed at the moment this
                // completion was recorded, so history rows remain accurate
                // even if the parent task's frequency changes later.
                $table->date('next_due_date_snapshot')->nullable();

                $table->timestamps();

                $table->foreign('preventive_maintenance_task_id', 'pm_history_task_fk')
                    ->references('id')->on('preventive_maintenance_tasks')->cascadeOnDelete();
                $table->foreign('performed_by')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('recorded_by')->references('user_id')->on('users')->nullOnDelete();

                $table->index(['preventive_maintenance_task_id', 'completed_date'], 'pm_history_task_completed_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_maintenance_history');
        Schema::dropIfExists('preventive_maintenance_tasks');
    }
};
