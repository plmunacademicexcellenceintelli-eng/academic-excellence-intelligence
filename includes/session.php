<?php
/** PHP session with secure cookie settings. */
declare(strict_types=1);

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $c = cfg('session');

    ini_set('session.use_strict_mode', '1');   // reject session IDs the server did not create
    ini_set('session.use_only_cookies', '1');  // never accept the session ID from the URL
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string)max((int)($c['idle_timeout'] ?? 1800), 1440));

    $secure = ($c['secure_cookie'] ?? 'auto') === 'auto' ? is_https() : (bool)$c['secure_cookie'];

    session_name((string)($c['name'] ?? 'AEISESSID'));
    session_set_cookie_params([
        'lifetime' => 0,               // cookie ends when the browser closes
        'path'     => app_url(''),     // only sent to this application's folder
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,            // JavaScript cannot read the session cookie
        'samesite' => 'Lax',           // not sent on cross-site POST requests
    ]);
    session_cache_limiter('nocache');
    session_start();
}

/** Remove all session data, expire the session cookie and destroy the server-side session. */
function session_end_completely(): void
{
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}
