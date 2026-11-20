<?php
/**
 * Authentication and authorization.
 *
 * The session only stores the user_id, the login time and the last-activity time.
 * On every request the account is re-read from the database, so the ROLE always comes from the
 * database (never from the browser), and a deactivated account is signed out immediately.
 */
declare(strict_types=1);

const ROLE_ADMIN   = 'admin';
const ROLE_STUDENT = 'student';

/** Generic message for any wrong username/student number/password combination (no hint which part was wrong). */
const LOGIN_FAILED_MESSAGE = 'Invalid username/student number or password.';

/** Load an account with its role and (for students) its student record. */
function find_account(string $column, string|int $value): ?array
{
    if (!in_array($column, ['u.user_id', 'u.username'], true)) {
        throw new InvalidArgumentException('Unsupported lookup column');
    }
    $st = db()->prepare(
        "SELECT u.user_id, u.username, u.password_hash, u.full_name, u.email, u.is_active,
                u.must_change_password, u.last_login_at, r.role_key,
                s.student_id, s.student_number, s.last_name, s.first_name, s.middle_name,
                s.program, s.year_level, s.section, s.status AS student_status, s.prototype_ref
           FROM users u
           JOIN roles r ON r.role_id = u.role_id
      LEFT JOIN students s ON s.user_id = u.user_id
          WHERE $column = ?
          LIMIT 1"
    );
    $st->execute([$value]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Check a sign-in attempt.
 * Returns ['ok' => true, 'user' => array] or ['ok' => false, 'error' => message].
 */
function attempt_login(string $identifier, string $password): array
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your username or student number and your password.'];
    }
    if (strlen($identifier) > 50 || !preg_match('/^[A-Za-z0-9._@-]+$/', $identifier) || strlen($password) > 200) {
        return ['ok' => false, 'error' => LOGIN_FAILED_MESSAGE];
    }

    $key  = normalize_login_name($identifier);
    $ip   = client_ip();
    $wait = login_lock_seconds($key, $ip);
    if ($wait > 0) {
        $min = (int)ceil($wait / 60);
        return ['ok' => false, 'locked' => true,
                'error' => "Too many failed sign-in attempts. Please wait $min minute" . ($min === 1 ? '' : 's') . ' and try again.'];
    }

    $user = find_account('u.username', $identifier);

    // Always run password_verify, even for unknown usernames, so response time does not reveal which usernames exist.
    // (The fallback below is the hash of a random value that was thrown away - it is not a credential.)
    $hash  = $user['password_hash'] ?? '$2y$10$ze9l6ujqXg2qE7G8nb0Buevj3TM/lfk21pBW46.5cSieROC2HIc3e';
    $valid = password_verify($password, $hash) && $user !== null;

    if (!$valid) {
        record_login_attempt($key, $ip, false);
        return ['ok' => false, 'error' => LOGIN_FAILED_MESSAGE];
    }
    if ((int)$user['is_active'] !== 1) {
        record_login_attempt($key, $ip, false);
        return ['ok' => false, 'error' => 'This account is inactive. Please contact the system administrator.'];
    }
    if ($user['role_key'] === ROLE_STUDENT && empty($user['student_id'])) {
        record_login_attempt($key, $ip, false);
        return ['ok' => false, 'error' => 'This student account is not linked to a student record. Please contact the system administrator.'];
    }

    // Upgrade the hash automatically if PHP's default algorithm/cost changes in the future.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE user_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), now_sql(), $user['user_id']]);
    }

    record_login_attempt($key, $ip, true);
    db()->prepare('UPDATE users SET last_login_at = ? WHERE user_id = ?')->execute([now_sql(), $user['user_id']]);

    // New session ID after login (prevents session fixation), fresh CSRF token, minimal session data.
    session_boot();
    session_regenerate_id(true);
    $_SESSION = [
        'user_id'       => (int)$user['user_id'],
        'login_at'      => time(),
        'last_activity' => time(),
    ];
    csrf_rotate();

    return ['ok' => true, 'user' => $user];
}

/**
 * The signed-in account for this request, or null.
 * Applies the inactivity and absolute timeouts. $reason receives 'expired' when a session just timed out.
 */
function current_user(?string &$reason = null): ?array
{
    static $cached = false, $cachedReason = null;
    if ($cached !== false) {
        $reason = $cachedReason;
        return $cached;
    }
    session_boot();
    $cached = null;

    if (!empty($_SESSION['user_id'])) {
        $c   = cfg('session');
        $now = time();
        $idle = $now - (int)($_SESSION['last_activity'] ?? 0);
        $age  = $now - (int)($_SESSION['login_at'] ?? 0);

        if ($idle > (int)($c['idle_timeout'] ?? 1800) || $age > (int)($c['absolute_timeout'] ?? 28800)) {
            session_end_completely();
            $cachedReason = 'expired';
        } else {
            $user = find_account('u.user_id', (int)$_SESSION['user_id']);
            if ($user === null || (int)$user['is_active'] !== 1
                || ($user['role_key'] === ROLE_STUDENT && empty($user['student_id']))) {
                session_end_completely();
                $cachedReason = 'revoked';
            } else {
                $_SESSION['last_activity'] = $now;
                unset($user['password_hash']);
                $cached = $user;
            }
        }
    }
    $reason = $cachedReason;
    return $cached;
}

function home_path_for(array $user): string
{
    return $user['role_key'] === ROLE_ADMIN ? 'dashboard.php' : 's-dashboard.php';
}

/**
 * Protect a PHP page. Not signed in -> login page. Wrong role -> 403 page.
 * $role = ROLE_ADMIN, ROLE_STUDENT, or null for "any signed-in user".
 */
function require_page_access(?string $role, bool $allowPasswordChange = false): array
{
    send_security_headers();
    $user = current_user($reason);
    if ($user === null) {
        redirect('login.php' . ($reason === 'expired' ? '?expired=1' : ($reason === 'revoked' ? '?revoked=1' : '')));
    }
    if ((int)$user['must_change_password'] === 1 && !$allowPasswordChange) {
        redirect('change-password.php');
    }
    if ($role !== null && $user['role_key'] !== $role) {
        http_response_code(403);
        render_message_page(
            'Access denied',
            'Your account does not have permission to open this page.',
            'forbidden',
            ['href' => app_url(home_path_for($user)), 'label' => 'Go to my dashboard']
        );
        exit;
    }
    return $user;
}

/** Protect a JSON endpoint (same rules, JSON errors instead of redirects). */
function require_api_access(?string $role, bool $allowPasswordChange = false): array
{
    $user = current_user($reason);
    if ($user === null) {
        json_response(['error' => $reason === 'expired' ? 'Your session has expired. Please sign in again.' : 'Please sign in.',
                       'login' => app_url('login.php' . ($reason === 'expired' ? '?expired=1' : ''))], 401);
    }
    if ((int)$user['must_change_password'] === 1 && !$allowPasswordChange) {
        json_response(['error' => 'You must change your temporary password first.'], 403);
    }
    if ($role !== null && $user['role_key'] !== $role) {
        json_response(['error' => 'Your account does not have permission for this action.'], 403);
    }
    return $user;
}

/** For POST endpoints: require a valid CSRF token. */
function require_csrf_json(): void
{
    if (!csrf_valid(csrf_from_request())) {
        json_response(['error' => 'Security check failed (CSRF token). Reload the page and try again.'], 403);
    }
}

/**
 * Student data isolation rule (use this in every endpoint that returns student records):
 * - Admin: any student.
 * - Student: only the student_id linked to their own account. Any other ID is refused.
 */
function can_access_student(array $user, int $studentId): bool
{
    if ($user['role_key'] === ROLE_ADMIN) {
        return true;
    }
    return $user['role_key'] === ROLE_STUDENT
        && !empty($user['student_id'])
        && (int)$user['student_id'] === $studentId;
}

/** Public account information that pages may show (no password hash). */
function public_user(array $user): array
{
    $isStudent = $user['role_key'] === ROLE_STUDENT;
    return [
        'id'       => (int)$user['user_id'],
        'username' => $user['username'],
        'name'     => $user['full_name'],
        'email'    => $user['email'],
        'role'     => $user['role_key'],
        'roleName' => $isStudent ? 'Student' : 'System Administrator',
        'student'  => $isStudent ? [
            'studentId'     => (int)$user['student_id'],
            'studentNumber' => $user['student_number'],
            'program'       => $user['program'],
            'yearLevel'     => (int)$user['year_level'],
            'section'       => $user['section'],
        ] : null,
    ];
}
