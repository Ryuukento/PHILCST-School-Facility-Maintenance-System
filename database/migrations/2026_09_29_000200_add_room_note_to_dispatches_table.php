<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch Create — Location Details.
 *
 * `dispatches.room_id` stays a strict FK into the `rooms` table (structured,
 * reportable, filterable — Deployment Tracking and room-usage reporting rely
 * on it staying that way). It does not cover every real-world need: the
 * destination may not be a room the system has registered yet, or the Head
 * Maintenance may want to hand the Release Personnel a more precise pointer
 * than a room name alone gives ("near the stockroom door", "Wing B
 * corridor").
 *
 * `room_note` is a free-text SUPPLEMENT to room_id, not a replacement for it:
 * it is never validated against any list and never drives any filter/report
 * — it exists purely so the person receiving the dispatch can read exactly
 * where to go. Nullable at the database level (existing rows have none, and
 * nothing else that writes a dispatch row is required to supply it); the
 * public create endpoint enforces "required" itself, in application-level
 * validation, exactly like it already does for release_assigned_to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dispatches')) {
            return;
        }

        Schema::table('dispatches', function (Blueprint $table): void {
            if (!Schema::hasColumn('dispatches', 'room_note')) {
                $table->string('room_note', 500)->nullable()->after('room_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('dispatches')) {
            return;
        }

        Schema::table('dispatches', function (Blueprint $table): void {
            if (Schema::hasColumn('dispatches', 'room_note')) {
                $table->dropColumn('room_note');
            }
        });
    }
};
