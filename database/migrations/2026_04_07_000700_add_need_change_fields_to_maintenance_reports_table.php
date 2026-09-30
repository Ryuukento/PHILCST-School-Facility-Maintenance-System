<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        // 1. Add columns kung wala pa
        Schema::table('maintenance_reports', function (Blueprint $table): void {
            if (!Schema::hasColumn('maintenance_reports', 'need_change_item_id')) {
                $table->unsignedInteger('need_change_item_id')->nullable()->after('description');
            }
            if (!Schema::hasColumn('maintenance_reports', 'need_change_quantity')) {
                $table->unsignedInteger('need_change_quantity')->default(1)->after('need_change_item_id');
            }
            if (!Schema::hasColumn('maintenance_reports', 'need_change_status')) {
                $table->enum('need_change_status', ['pending', 'approved', 'deducted', 'failed'])->nullable()->after('need_change_quantity');
            }
            if (!Schema::hasColumn('maintenance_reports', 'need_change_approved_by')) {
                $table->unsignedInteger('need_change_approved_by')->nullable()->after('need_change_status');
            }
            if (!Schema::hasColumn('maintenance_reports', 'need_change_approved_at')) {
                $table->timestamp('need_change_approved_at')->nullable()->after('need_change_approved_by');
            }
            if (!Schema::hasColumn('maintenance_reports', 'need_change_deducted_at')) {
                $table->timestamp('need_change_deducted_at')->nullable()->after('need_change_approved_at');
            }
        });

        // 2. (TEMPORARY FIX) Huwag munang maglagay ng foreign key constraint para sa need_change_item_id at need_change_approved_by
        // Pwede itong idagdag manually kapag sure na ang data at structure
    }

    public function down(): void
    {
        if (!Schema::hasTable('maintenance_reports')) {
            return;
        }

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            if (Schema::hasColumn('maintenance_reports', 'need_change_approved_by')) {
                $table->dropForeign(['need_change_approved_by']);
            }

            if (Schema::hasColumn('maintenance_reports', 'need_change_item_id')) {
                $table->dropForeign(['need_change_item_id']);
            }
        });

        Schema::table('maintenance_reports', function (Blueprint $table): void {
            $dropColumns = [];
            foreach ([
                'need_change_item_id',
                'need_change_quantity',
                'need_change_status',
                'need_change_approved_by',
                'need_change_approved_at',
                'need_change_deducted_at',
            ] as $column) {
                if (Schema::hasColumn('maintenance_reports', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
