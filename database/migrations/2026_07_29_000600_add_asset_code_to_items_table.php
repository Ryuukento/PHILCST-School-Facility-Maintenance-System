<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            if (!Schema::hasColumn('items', 'asset_code')) {
                $table->string('asset_code', 50)->nullable()->unique()->after('name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            if (Schema::hasColumn('items', 'asset_code')) {
                $table->dropColumn('asset_code');
            }
        });
    }
};
