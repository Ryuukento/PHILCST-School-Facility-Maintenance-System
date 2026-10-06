<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dispatch label print tracking — records when a dispatch's stickers were
     * last confirmed as printed, so the Dispatches page can filter to
     * "Not yet printed" and Print All Labels no longer re-prints stickers that
     * are already on the equipment.
     *
     * Additive and nullable: every existing dispatch starts as "not yet
     * printed" (NULL). Identifiers are explicit and under MySQL's 64-char cap.
     */
    public function up(): void
    {
        if (!Schema::hasTable('dispatches')) {
            return;
        }

        Schema::table('dispatches', function (Blueprint $table): void {
            if (!Schema::hasColumn('dispatches', 'labels_printed_at')) {
                $table->timestamp('labels_printed_at')->nullable();
                $table->index('labels_printed_at', 'dispatches_labels_printed_at_index');
            }
            if (!Schema::hasColumn('dispatches', 'labels_printed_by')) {
                $table->unsignedInteger('labels_printed_by')->nullable();
                $table->foreign('labels_printed_by', 'dispatches_labels_printed_by_foreign')
                    ->references('user_id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('dispatches')) {
            return;
        }

        Schema::table('dispatches', function (Blueprint $table): void {
            if (Schema::hasColumn('dispatches', 'labels_printed_by')) {
                $table->dropForeign('dispatches_labels_printed_by_foreign');
                $table->dropColumn('labels_printed_by');
            }
            if (Schema::hasColumn('dispatches', 'labels_printed_at')) {
                $table->dropIndex('dispatches_labels_printed_at_index');
                $table->dropColumn('labels_printed_at');
            }
        });
    }
};
