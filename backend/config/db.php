<?php
// =============================================================================
// config/db.php — PDO database connection
//
// Reads credentials from a .env file at the project root (two levels up).
// Copy .env.example → .env and fill in your values.
// NEVER commit .env to version control.
// =============================================================================

declare(strict_types=1);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Load .env from project root (two levels above this file)
    $envFile = __DIR__ . '/../../../.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($val));
        }
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('DB_NAME') ?: 's25104471_timesheet';
    $user = getenv('DB_USER') ?: 's25104471_timesheet';
    $pass = getenv('DB_PASS') ?: 'Qwerty123';

    $dsn     = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}
