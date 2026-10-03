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

                // Prevent duplicate assignments
                $table->unique(['dispatch_id', 'personnel_user_id']);

                $table->foreign('dispatch_id')
                    ->references('id')
                    ->on('dispatches')
                    ->onDelete('cascade');
                $table->foreign('personnel_user_id')
                    ->references('user_id')
                    ->on('users')
                    ->nullOnDelete();
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
