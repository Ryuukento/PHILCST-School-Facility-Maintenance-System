<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report Archive — "reopened by the Administrator" marker.
 *
 * A finished (completed / closed / cancelled) report from a past academic
 * term is view-only. The Administrator may reopen one to correct it; these
 * two columns record that (and who did it). While set, the report can be
 * edited again; the Administrator's "Lock again" action — or finishing the
 * report again — clears them. See ReportArchiveService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->timestamp('archive_reopened_at')->nullable()->after('updated_at');
            $table->unsignedInteger('archive_reopened_by')->nullable()->after('archive_reopened_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $table->dropColumn(['archive_reopened_at', 'archive_reopened_by']);
        });
    }
};
