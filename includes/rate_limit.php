<?php
/**
 * Login attempt protection.
 * - At most N failed attempts for the same username / student number in the window (default 5 in 15 minutes).
 * - At most M failed attempts from the same IP address in the window (default 20 in 15 minutes).
 * While locked, the password is not even checked and the attempt is not recorded
 * (so a locked-out user is not kept locked forever by repeated tries).
 * A successful login resets the per-username counter.
 */
declare(strict_types=1);

function normalize_login_name(string $name): string
{
    return strtolower(trim($name));
}

/** Seconds until sign-in is allowed again for this username/IP; 0 = allowed now. */
function login_lock_seconds(string $username, string $ip): int
{
    $c      = cfg('login_protection');
    $window = (int)($c['window_seconds'] ?? 900);
    $now    = time();
    $since  = now_sql($now - $window);
    $wait   = 0;

    // Per username: only failures after the last successful login count.
    $st = db()->prepare('SELECT MAX(attempted_at) FROM login_attempts WHERE username = ? AND was_successful = 1');
    $st->execute([$username]);
    $lastOk = $st->fetchColumn() ?: '1970-01-01 00:00:00';

    $maxUser = max(1, (int)($c['max_failures_per_user'] ?? 5));
    $st = db()->prepare(
        'SELECT attempted_at FROM login_attempts
          WHERE username = ? AND was_successful = 0 AND attempted_at >= ? AND attempted_at > ?
          ORDER BY attempted_at DESC LIMIT ' . $maxUser
    );
    $st->execute([$username, $since, $lastOk]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) >= $maxUser) {
        $wait = max($wait, strtotime((string)end($rows)) + $window - $now);
    }

    // Per IP address.
    $maxIp = max(1, (int)($c['max_failures_per_ip'] ?? 20));
    $st = db()->prepare(
        'SELECT attempted_at FROM login_attempts
          WHERE ip_address = ? AND was_successful = 0 AND attempted_at >= ?
          ORDER BY attempted_at DESC LIMIT ' . $maxIp
    );
    $st->execute([$ip, $since]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) >= $maxIp) {
        $wait = max($wait, strtotime((string)end($rows)) + $window - $now);
    }

    return max(0, $wait);
}

function record_login_attempt(string $username, string $ip, bool $success): void
{
    db()->prepare('INSERT INTO login_attempts (username, ip_address, was_successful, attempted_at) VALUES (?, ?, ?, ?)')
        ->execute([substr($username, 0, 50), $ip, $success ? 1 : 0, now_sql()]);

    // Housekeeping: keep 30 days of history.
    db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
        ->execute([now_sql(time() - 30 * 86400)]);
}
