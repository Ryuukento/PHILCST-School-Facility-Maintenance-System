<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class NormalizeUserRoles extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add a reversible backup column to allow restoring original roles if needed
        if (!Schema::hasColumn('users', 'previous_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('previous_role', 100)->nullable()->after('role');
            });
        }

        // Copy current role values into previous_role only once so rollback can restore them.
        DB::table('users')
            ->whereNull('previous_role')
            ->update(['previous_role' => DB::raw('role')]);

        // Normalize common alias values to canonical tokens
        // Map only the known aliases that have been found in this codebase.
        $aliasGroups = [
            ['admin', 'administrator', 'super admin'],
            ['admin_maintenance', 'department_admin'],
            ['eelab_staff', 'maintenance_personnel'],
        ];

        foreach ($aliasGroups as $aliasGroup) {
            $normalizedAliases = array_map(static fn ($value) => strtolower(trim($value)), $aliasGroup);
            $placeholders = implode(',', array_fill(0, count($normalizedAliases), '?'));

            $targetRole = match (true) {
                in_array('admin', $normalizedAliases, true) || in_array('administrator', $normalizedAliases, true) || in_array('super admin', $normalizedAliases, true) => 'super_admin',
                in_array('admin_maintenance', $normalizedAliases, true) || in_array('department_admin', $normalizedAliases, true) => 'maintenance_admin',
                default => 'maintenance_staff',
            };

            DB::update(
                "UPDATE users SET role = ? WHERE LOWER(TRIM(role)) IN ($placeholders)",
                array_merge([$targetRole], $normalizedAliases)
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original role values from previous_role if available
        if (Schema::hasColumn('users', 'previous_role')) {
            DB::update("UPDATE users SET role = previous_role WHERE previous_role IS NOT NULL");
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('previous_role');
            });
        }
    }
}
