<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Credentials are pulled from .env (never committed) with dummy
        // fallback defaults so a fresh checkout still seeds a usable local
        // account. The fallback values below are NOT real credentials —
        // set SEED_SUPER_ADMIN_* / SEED_MAINTENANCE_ADMIN_* in your local
        // .env before running this seeder against anything beyond a
        // throwaway local database, and never set them in .env.example.
        //
        // Matched on 'username' (not 'email'): TASK 81 made username the
        // sole login identifier (see AuthController::login()), so seeded
        // accounts must be keyed and populated by username to actually be
        // able to log in. 'email' is still included in the payload — it
        // remains a required/unique contact attribute on the users table —
        // but it is no longer usable to authenticate.
        User::query()->updateOrCreate([
            'username' => env('SEED_SUPER_ADMIN_USERNAME', 'superadmin'),
        ], [
            'full_name' => 'Administrator',
            'email' => env('SEED_SUPER_ADMIN_EMAIL', 'admin@example.com'),
            'password' => Hash::make(env('SEED_SUPER_ADMIN_PASSWORD', 'change-me-super-admin')),
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        User::query()->updateOrCreate([
            'username' => env('SEED_MAINTENANCE_ADMIN_USERNAME', 'maintenanceadmin'),
        ], [
            'full_name' => 'Maintenance Admin',
            'email' => env('SEED_MAINTENANCE_ADMIN_EMAIL', 'maintenance-admin@example.com'),
            'password' => Hash::make(env('SEED_MAINTENANCE_ADMIN_PASSWORD', 'change-me-maint-admin')),
            'role' => 'maintenance_admin',
            'status' => 'active',
        ]);
    }
}
