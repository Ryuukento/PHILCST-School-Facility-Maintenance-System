<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Test-environment bootstrap helpers for self-contained Feature tests that
 * use a dedicated in-memory SQLite connection instead of RefreshDatabase,
 * so each test file's schema/data never touches the real application
 * database and cannot collide with other test files' connections.
 */
trait ConfiguresIsolatedSqliteConnection
{
    /**
     * Swap the default DB connection for an isolated in-memory SQLite
     * connection (named uniquely per test file to avoid cross-file
     * collisions), and use array-backed session/cache drivers.
     */
    protected function useInMemoryDatabase(string $connection): void
    {
        Config::set('session.driver', 'array');
        Config::set('cache.default', 'array');
        Config::set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        Config::set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        Config::set('database.default', $connection);

        DB::purge($connection);
        DB::setDefaultConnection($connection);
        DB::reconnect($connection);
    }

    /**
     * APP_URL in this environment includes the XAMPP htdocs subdirectory
     * (see .env), which collides with the legacy subdirectory-redirect
     * catch-all route in routes/web.php when the test client builds
     * request URLs via url(). Force a plain host for HTTP test requests
     * only; this does not affect the running application.
     */
    protected function forceLocalTestUrl(): void
    {
        Config::set('app.url', 'http://localhost');
        app('url')->forceRootUrl('http://localhost');
    }
}
