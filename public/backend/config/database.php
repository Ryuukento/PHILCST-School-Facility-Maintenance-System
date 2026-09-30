<?php
/**
 * Database Configuration
 *
 * TASK 100.1 remediation (2026-08-17): credentials are no longer hardcoded.
 * They are sourced from the environment — the same DB_HOST / DB_PORT /
 * DB_DATABASE / DB_USERNAME / DB_PASSWORD variables Laravel's own
 * config/database.php mysql connection already reads — instead of being
 * hardcoded here. This file is loaded directly by public/backend/* legacy
 * scripts outside Laravel's normal request lifecycle, so env() is not
 * guaranteed to be defined; the real .env file is loaded explicitly below
 * (via the Composer Dotenv package already bundled with this project) so
 * the same .env values apply to both stacks. The literal fallback defaults
 * below (localhost / root / '' / school_facility_maintenance / 3306)
 * preserve today's exact local-XAMPP behavior when no override is present
 * — they are the same values that used to be hardcoded, now used only as
 * a last resort, not the primary source.
 */

$sfmsBackendProjectRoot = dirname(__DIR__, 3);
$sfmsBackendAutoload = $sfmsBackendProjectRoot . '/vendor/autoload.php';

if (is_file($sfmsBackendAutoload)) {
    require_once $sfmsBackendAutoload;

    if (class_exists(\Dotenv\Dotenv::class) && is_file($sfmsBackendProjectRoot . '/.env')) {
        // safeLoad(): never overwrites a real OS-level env var that is
        // already set, matches Laravel's own precedence.
        \Dotenv\Dotenv::createImmutable($sfmsBackendProjectRoot)->safeLoad();
    }
}

/**
 * Read an environment variable without depending on Laravel's env()
 * helper (which may not be defined in this legacy request lifecycle).
 */
function sfmsBackendEnv(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
}

define('DB_HOST', sfmsBackendEnv('DB_HOST', 'localhost'));
define('DB_USER', sfmsBackendEnv('DB_USERNAME', 'root'));
define('DB_PASSWORD', sfmsBackendEnv('DB_PASSWORD', ''));
define('DB_NAME', sfmsBackendEnv('DB_DATABASE', 'school_facility_maintenance'));
define('DB_PORT', (int) sfmsBackendEnv('DB_PORT', 3306));

/**
 * Create PDO connection.
 */
function getDBConnection() {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        return new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $isApiRequest = strpos($requestUri, '/backend/api/') !== false;

        if ($isApiRequest) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
            }

            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed. Please check your configuration.'
            ]);
            exit;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo 'Database connection failed. Please import/setup database first.';
        exit;
    }
}

$pdo = getDBConnection();

return $pdo;
