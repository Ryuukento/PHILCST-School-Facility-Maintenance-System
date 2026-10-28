<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Personnel Dispatch Support.
 *
 * Allow multiple maintenance personnel from different departments to be assigned
 * to a single dispatch, with each person only dispatching items relevant to their
 * department. This migration:
 *
 *   1. Creates dispatch_personnel_assignments table (many-to-many):
 *      - Links dispatches to multiple personnel
 *      - Tracks which department each person belongs to for item filtering
 *      - Stores assignment metadata (who assigned, when)
 *
 *   2. Adds dispatched_by to dispatch_items:
 *      - Tracks which specific personnel dispatched each item
 *      - Allows audit trail of who handled what
 *
 * The existing release_assigned_to field is kept for backward compatibility
 * but will be phased out as the new multi-personnel system is the primary flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create dispatch_personnel_assignments table
        if (!Schema::hasTable('dispatch_personnel_assignments')) {
            Schema::create('dispatch_personnel_assignments', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('dispatch_id');
                $table->unsignedInteger('personnel_user_id');
                $table->unsignedInteger('assigned_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();

                // Prevent duplicate assignments. Named explicitly: the
                // auto-generated name
                // ("dispatch_personnel_assignments_dispatch_id_personnel_user_id_unique",
                // 69 chars) exceeds MySQL/MariaDB's 64-character identifier
                // limit and errors with 1059 on any FRESH database (confirmed
                // via LegacyMigrationRehearsalTest, which migrates a scratch
                // DB from empty). The live dev DB never hit this because the
                // table was originally created under an older, shorter
                // column name (personnel_id) and later renamed in place by
                // 2026_10_26_000100_fix_dispatch_personnel_assignments_column_drift.php,
                // which doesn't rename the index -- so this bug was silent
                // there but fatal on any clean install/migrate from scratch.
                $table->unique(['dispatch_id', 'personnel_user_id'], 'dpa_dispatch_personnel_unique');

                $table->foreign('dispatch_id')
                    ->references('id')
                    ->on('dispatches')
                    ->onDelete('cascade');
                // personnel_user_id is NOT NULL (every assignment row needs a
                // person), so nullOnDelete() here would be invalid -- MySQL/
                // MariaDB reject a SET NULL action on a non-nullable column
                // with errno 150 "Foreign key constraint is incorrectly
                // formed" the moment this table is actually created. Cascade
                // instead: deleting a user removes their roster rows rather
                // than leaving an invalid null-person assignment behind.
                $table->foreign('personnel_user_id')
                    ->references('user_id')
                    ->on('users')
                    ->onDelete('cascade');
                $table->foreign('assigned_by')
                    ->references('user_id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        // Add dispatched_by to dispatch_items table
        Schema::table('dispatch_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('dispatch_items', 'dispatched_by')) {
                $table->unsignedInteger('dispatched_by')
                    ->nullable()
                    ->after('quantity')
                    ->comment('User ID of the personnel who dispatched this item');
            }
            if (!Schema::hasColumn('dispatch_items', 'dispatched_at')) {
                $table->timestamp('dispatched_at')
                    ->nullable()
                    ->after('dispatched_by')
                    ->comment('Timestamp when this item was dispatched');
            }
        });

        // Add foreign key for dispatched_by
        Schema::table('dispatch_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('dispatch_items', 'dispatched_by')) {
                return;
            }

            try {
                $table->foreign('dispatched_by')
                    ->references('user_id')
                    ->on('users')
                    ->nullOnDelete();
            } catch (\Exception $e) {
                // Foreign key might already exist or constraint name conflict
                // Log but don't fail the migration
                \Log::warning('Could not add dispatched_by foreign key: ' . $e->getMessage());
            }
        });
    }

    public function down(): void
    {
        // Drop foreign key and column from dispatch_items
        Schema::table('dispatch_items', function (Blueprint $table): void {
            try {
                $table->dropForeign(['dispatched_by']);
            } catch (\Exception $e) {
                // Foreign key might not exist
            }

            if (Schema::hasColumn('dispatch_items', 'dispatched_by')) {
                $table->dropColumn('dispatched_by');
            }
            if (Schema::hasColumn('dispatch_items', 'dispatched_at')) {
                $table->dropColumn('dispatched_at');
            }
        });

        // Drop dispatch_personnel_assignments table
        if (Schema::hasTable('dispatch_personnel_assignments')) {
            Schema::dropIfExists('dispatch_personnel_assignments');
        }
    }
};
