<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dispatches') && !Schema::hasColumn('dispatches', 'release_remarks')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                $table->text('release_remarks')->nullable()->after('released_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dispatches') && Schema::hasColumn('dispatches', 'release_remarks')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                $table->dropColumn('release_remarks');
            });
        }
    }
};
