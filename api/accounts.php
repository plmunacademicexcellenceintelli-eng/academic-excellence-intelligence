<?php
/**
 * Admin-only user account management (used by Admin > Settings > User Accounts).
 *   GET                      -> list of accounts
 *   POST action=create_student   student_id           -> creates the sign-in account for an existing Module 1 student
 *                                                       record (username = student number); temporary password returned once
 *   POST action=reset_password   user_id               -> new temporary password, returned once
 *   POST action=set_active       user_id, active=0|1
 * Every POST needs the CSRF token (X-CSRF-Token header). Students receive 403 for every request.
 * Admin accounts are NOT created here: use scripts/create_admin.php on the server (no web page can create an Admin).
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$admin = require_api_access(ROLE_ADMIN);

function account_row(array $r): array
{
    return [
        'userId'        => (int)$r['user_id'],
        'username'      => $r['username'],
        'name'          => $r['full_name'],
        'email'         => $r['email'],
        'role'          => $r['role_key'],
        'active'        => (int)$r['is_active'] === 1,
        'mustChange'    => (int)$r['must_change_password'] === 1,
        'lastLogin'     => $r['last_login_at'],
        'program'       => $r['program'],
        'yearLevel'     => $r['year_level'] !== null ? (int)$r['year_level'] : null,
        'section'       => $r['section'],
        'studentId'     => $r['student_id'] !== null ? (int)$r['student_id'] : null,
        'studentNumber' => $r['student_number'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['accounts' => array_map('account_row', list_accounts()), 'me' => (int)$admin['user_id']]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
require_csrf_json();

$action = (string)($_POST['action'] ?? '');
$userId = (int)($_POST['user_id'] ?? 0);

try {
    switch ($action) {
        case 'create_student':
            $r = create_student_login((int)($_POST['student_id'] ?? 0));
            json_response(['ok' => true, 'username' => $r['username'], 'tempPassword' => $r['temp_password'],
                           'message' => 'Student account created.']);
        case 'reset_password':
            $temp = reset_account_password($userId);
            json_response(['ok' => true, 'tempPassword' => $temp, 'message' => 'Temporary password set.']);
        case 'set_active':
            set_account_active($userId, ($_POST['active'] ?? '') === '1', (int)$admin['user_id']);
            json_response(['ok' => true, 'message' => ($_POST['active'] ?? '') === '1' ? 'Account activated.' : 'Account deactivated.']);
        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (AccountError $ex) {
    json_response(['error' => $ex->getMessage()], 422);
}
