<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            if (!Schema::hasColumn('activity_logs', 'user_role')) {
                $table->string('user_role', 100)->nullable()->after('user_id');
            }

            if (!Schema::hasColumn('activity_logs', 'module')) {
                $table->string('module', 100)->nullable()->after('action');
            }

            if (!Schema::hasColumn('activity_logs', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip_address');
            }

            if (!Schema::hasColumn('activity_logs', 'meta_json')) {
                $table->json('meta_json')->nullable()->after('user_agent');
            }

            if (!Schema::hasColumn('activity_logs', 'dedupe_key')) {
                $table->string('dedupe_key', 64)->nullable()->after('meta_json');
            }
        });

        DB::table('activity_logs')
            ->whereNull('module')
            ->update(['module' => 'system']);

        DB::table('activity_logs')
            ->whereNull('user_role')
            ->whereNotNull('user_id')
            ->update([
                'user_role' => DB::raw('(SELECT users.role FROM users WHERE users.user_id = activity_logs.user_id LIMIT 1)'),
            ]);

        if (!Schema::hasColumn('activity_logs', 'dedupe_key')) {
            return;
        }

        $logs = DB::table('activity_logs')->select([
            'id',
            'user_id',
            'user_role',
            'action',
            'module',
            'entity_type',
            'entity_id',
            'details',
        ])->get();

        foreach ($logs as $log) {
            $dedupeKey = sha1(json_encode([
                'user_id' => $log->user_id,
                'user_role' => $log->user_role,
                'action' => $log->action,
                'module' => $log->module,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'details' => $log->details,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            DB::table('activity_logs')->where('id', $log->id)->update([
                'dedupe_key' => $dedupeKey,
            ]);
        }

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['module', 'action', 'created_at'], 'activity_logs_module_action_created_idx');
            $table->index(['dedupe_key', 'created_at'], 'activity_logs_dedupe_created_idx');
            $table->index(['created_at'], 'activity_logs_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_module_action_created_idx');
            $table->dropIndex('activity_logs_dedupe_created_idx');
            $table->dropIndex('activity_logs_created_idx');
            $table->dropColumn(['user_role', 'module', 'user_agent', 'meta_json', 'dedupe_key']);
        });
    }
};
