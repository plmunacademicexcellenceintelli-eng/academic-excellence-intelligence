<?php
/** Small shared helpers: configuration access, escaping, URLs, responses. */
declare(strict_types=1);

/** Read a configuration section, e.g. cfg('session')['idle_timeout']. */
function cfg(string $section): array
{
    return $GLOBALS['AEI_CONFIG'][$section] ?? [];
}

/** Escape text for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Current date-time as a DATETIME string (application time zone). */
function now_sql(?int $timestamp = null): string
{
    return date('Y-m-d H:i:s', $timestamp ?? time());
}

/** True when the current request arrived over HTTPS. */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

/**
 * Client IP address used for login rate limiting.
 * Only REMOTE_ADDR is used: X-Forwarded-For can be forged by any client.
 */
function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr($ip, 0, 45);
}

/**
 * URL of a file inside the application folder, e.g. app_url('login.php') -> "/plmun-ui/login.php".
 * Works for scripts in the root folder and in sub-folders such as api/.
 */
function app_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $root      = str_replace('\\', '/', (string)realpath(AEI_ROOT));
        $scriptDir = str_replace('\\', '/', (string)realpath(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? __FILE__))));
        $relative  = trim(substr($scriptDir, strlen($root)), '/');
        $depth     = $relative === '' ? 0 : count(explode('/', $relative));
        $url       = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        for ($i = 0; $i < $depth; $i++) {
            $url = str_replace('\\', '/', dirname($url));
        }
        $base = rtrim($url, '/') . '/';
    }
    return $base . ltrim($path, '/');
}

/** Redirect to a page inside the application and stop. */
function redirect(string $path): never
{
    header('Location: ' . app_url($path), true, 303);
    exit;
}

/**
 * Headers for every page that shows account or student information:
 * no caching (so Back after logout cannot show a stored copy), no framing, no MIME sniffing.
 */
function send_security_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/** Send a JSON response and stop. */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Encode data for safe embedding inside an inline <script>. */
function js_json(mixed $data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/** Refuse to run a command-line script from the web server. */
function require_cli(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
}
