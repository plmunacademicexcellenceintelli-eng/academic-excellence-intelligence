<?php
/**
 * Account management used by the CLI scripts (scripts/*.php) and the Admin "User Accounts" panel (api/accounts.php).
 * All passwords are stored with password_hash(); plaintext passwords are never stored or logged.
 */
declare(strict_types=1);

class AccountError extends RuntimeException {}

const PASSWORD_MIN_LENGTH = 10;

/** Returns an error message, or null when the password is acceptable. */
function password_problem(string $password, string $username = ''): ?string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (strlen($password) > 72) {
        return 'Password must be at most 72 characters.';   // bcrypt only uses the first 72 bytes
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    if ($username !== '' && stripos($password, $username) !== false) {
        return 'Password must not contain the username or student number.';
    }
    return null;
}

/** Random temporary password (12 characters, letters + digits, no look-alike characters). */
function generate_temp_password(): string
{
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $all     = $letters . $digits;
    do {
        $p = '';
        for ($i = 0; $i < 12; $i++) {
            $p .= $all[random_int(0, strlen($all) - 1)];
        }
    } while (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p));
    return $p;
}

function role_id(string $roleKey): int
{
    $st = db()->prepare('SELECT role_id FROM roles WHERE role_key = ?');
    $st->execute([$roleKey]);
    $id = $st->fetchColumn();
    if (!$id) {
        throw new AccountError("Role '$roleKey' is missing. Import database/aei_schema.sql first.");
    }
    return (int)$id;
}

function username_taken(string $username): bool
{
    $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
    $st->execute([$username]);
    return (bool)$st->fetchColumn();
}

function email_taken(string $email): bool
{
    $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $st->execute([$email]);
    return (bool)$st->fetchColumn();
}

function count_admins(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_key = 'admin'")->fetchColumn();
}

/** Create an Admin account. Returns the new user_id. */
function create_admin_account(string $username, string $fullName, ?string $email, string $password): int
{
    $username = trim($username);
    $fullName = trim($fullName);
    $email    = ($email !== null && trim($email) !== '') ? trim($email) : null;

    if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{3,29}$/', $username)) {
        throw new AccountError('Username must be 4-30 characters, start with a letter, and use only letters, numbers, dot, dash or underscore.');
    }
    if ($fullName === '' || mb_strlen($fullName) > 120) {
        throw new AccountError('Full name is required (up to 120 characters).');
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new AccountError('Email address is not valid.');
    }
    if ($p = password_problem($password, $username)) {
        throw new AccountError($p);
    }
    if (username_taken($username)) {
        throw new AccountError('That username is already in use.');
    }
    if ($email !== null && email_taken($email)) {
        throw new AccountError('That email address is already in use.');
    }

    $now = now_sql();
    db()->prepare(
        'INSERT INTO users (role_id, username, password_hash, full_name, email, is_active, must_change_password, password_changed_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?, ?)'
    )->execute([role_id(ROLE_ADMIN), $username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, $now, $now, $now]);
    return (int)db()->lastInsertId();
}

/** "Cruz, Andrea L." - display format used by every screen (middle name shown as its initial). */
function student_display_name(string $last, string $first, ?string $middle): string
{
    return $last . ', ' . $first . ($middle ? ' ' . mb_strtoupper(mb_substr($middle, 0, 1)) . '.' : '');
}

/**
 * Create the sign-in account for an EXISTING student record (Module 1). Username = the student's number.
 * $password null -> a temporary password is generated; the student must change it at first sign-in.
 * Returns ['user_id', 'student_id', 'username', 'temp_password'].
 */
function create_student_login(int $studentId, ?string $password = null): array
{
    $st = db()->prepare('SELECT student_id, user_id, student_number, last_name, first_name, middle_name FROM students WHERE student_id = ?');
    $st->execute([$studentId]);
    $s = $st->fetch();
    if (!$s) {
        throw new AccountError('Student record not found. Add the student in Student Management first.');
    }
    if ($s['user_id'] !== null) {
        throw new AccountError("Student {$s['student_number']} already has a sign-in account.");
    }
    if (username_taken($s['student_number'])) {
        throw new AccountError("The username {$s['student_number']} is already in use by another account.");
    }
    $temp = $password === null;
    $password ??= generate_temp_password();
    if ($p = password_problem($password, $s['student_number'])) {
        throw new AccountError($p);
    }

    $now = now_sql();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO users (role_id, username, password_hash, full_name, email, is_active, must_change_password, password_changed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, 1, 1, NULL, ?, ?)'
        )->execute([role_id(ROLE_STUDENT), $s['student_number'], password_hash($password, PASSWORD_DEFAULT),
                    student_display_name($s['last_name'], $s['first_name'], $s['middle_name']), $now, $now]);
        $userId = (int)$pdo->lastInsertId();
        // "AND user_id IS NULL" protects against two Admins linking the same student at the same moment.
        $up = $pdo->prepare('UPDATE students SET user_id = ?, updated_at = ? WHERE student_id = ? AND user_id IS NULL');
        $up->execute([$userId, $now, $studentId]);
        if ($up->rowCount() !== 1) {
            throw new AccountError('This student was linked to another account just now. Reload and try again.');
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['user_id' => $userId, 'student_id' => $studentId, 'username' => $s['student_number'],
            'temp_password' => $temp ? $password : null];
}

/** All accounts for the Admin panel (no password hashes). */
function list_accounts(): array
{
    return db()->query(
        'SELECT u.user_id, u.username, u.full_name, u.email, u.is_active, u.must_change_password, u.last_login_at, u.created_at,
                r.role_key, s.student_id, s.student_number, s.last_name, s.first_name, s.middle_name, s.program, s.year_level, s.section
           FROM users u
           JOIN roles r ON r.role_id = u.role_id
      LEFT JOIN students s ON s.user_id = u.user_id
       ORDER BY r.role_id, u.username'
    )->fetchAll();
}

/** Set a new temporary password (generated). The user must change it at next sign-in. */
function reset_account_password(int $userId, ?string $newPassword = null): string
{
    $st = db()->prepare('SELECT username FROM users WHERE user_id = ?');
    $st->execute([$userId]);
    $username = $st->fetchColumn();
    if ($username === false) {
        throw new AccountError('Account not found.');
    }
    $temp = $newPassword ?? generate_temp_password();
    if ($p = password_problem($temp, (string)$username)) {
        throw new AccountError($p);
    }
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, updated_at = ? WHERE user_id = ?')
        ->execute([password_hash($temp, PASSWORD_DEFAULT), now_sql(), $userId]);
    // Clear failed attempts so a locked-out user can sign in with the new password.
    db()->prepare('DELETE FROM login_attempts WHERE username = ? AND was_successful = 0')->execute([normalize_login_name((string)$username)]);
    return $temp;
}

function set_account_active(int $userId, bool $active, int $actingUserId): void
{
    if ($userId === $actingUserId && !$active) {
        throw new AccountError('You cannot deactivate your own account.');
    }
    $st = db()->prepare('UPDATE users SET is_active = ?, updated_at = ? WHERE user_id = ?');
    $st->execute([$active ? 1 : 0, now_sql(), $userId]);
    if ($st->rowCount() === 0) {
        $chk = db()->prepare('SELECT 1 FROM users WHERE user_id = ?');
        $chk->execute([$userId]);
        if (!$chk->fetchColumn()) {
            throw new AccountError('Account not found.');
        }
    }
}

/** A signed-in user changes their own password (current password required). */
function change_own_password(int $userId, string $current, string $new, string $confirm): void
{
    $st = db()->prepare('SELECT username, password_hash FROM users WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        throw new AccountError('Your current password is incorrect.');
    }
    if ($new !== $confirm) {
        throw new AccountError('The new passwords do not match.');
    }
    if (hash_equals($current, $new)) {
        throw new AccountError('The new password must be different from the current password.');
    }
    if ($p = password_problem($new, (string)$row['username'])) {
        throw new AccountError($p);
    }
    $now = now_sql();
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?, updated_at = ? WHERE user_id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $now, $now, $userId]);
}
