<?php
/** PDO database connection (one shared connection per request). */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c   = cfg('db');
    $dsn = !empty($c['dsn'])
        ? (string)$c['dsn']
        : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)$c['port'], $c['name']);

    try {
        $pdo = new PDO($dsn, $c['user'] ?? null, $c['password'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // every SQL error becomes an exception
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // Store and compare DATETIME values in the application time zone.
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        }
    } catch (PDOException $ex) {
        error_log('AEI database connection failed: ' . $ex->getMessage());
        db_unavailable(!empty(cfg('app')['debug']) ? $ex->getMessage() : null);
    }
    return $pdo;
}

/** Friendly "database not available" response (details only when debug is on). */
function db_unavailable(?string $detail = null): never
{
    $message = 'The database is not available. Make sure MySQL is running in XAMPP and that the database named in config/config.php has been imported.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL . ($detail ? $detail . PHP_EOL : ''));
        exit(1);
    }
    $isApi = str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/api/');
    if ($isApi) {
        json_response(['error' => $message], 503);
    }
    http_response_code(503);
    send_security_headers();
    render_message_page('Service unavailable', $message . ($detail ? "\n\nDetail: " . $detail : ''), 'database');
    exit;
}
