<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('auth_version')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'auth_version')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('auth_version');
            });
        }
    }
};
