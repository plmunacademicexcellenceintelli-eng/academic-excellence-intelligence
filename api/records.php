<?php
/**
 * MODULE 1 - academic (subject) records and GWA per term.
 *   GET ?student_id=N   Admin: student N.  Student: only their OWN ID (another ID -> 403). No ID -> own records.
 *                       Returns the records and the provisional GWA per academic term (with academic year and semester).
 *   POST {action:"add", student_id, term, record:{code,title,units,grade,remarks}}        Admin only
 *   POST {action:"save_term", student_id, term, rows:[{rid?,code,title,units,grade,remarks}]}  Admin only (Edit Grades)
 *   POST {action:"import", rows:[{line, data:{...}, overwrite}]}                          Admin only (CSV)
 * POST requests need the CSRF token in the X-CSRF-Token header.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = require_api_access(null);
    if (isset($_GET['student_id'])) {
        $sid = int_param($_GET['student_id']);
        if ($sid === null) {
            json_response(['error' => 'Invalid student ID.'], 400);
        }
    } elseif ($user['role_key'] === ROLE_STUDENT) {
        $sid = (int)$user['student_id'];
    } else {
        json_response(['error' => 'student_id is required.'], 400);
    }
    if (!can_access_student($user, $sid)) {
        json_response(['error' => 'You can only view your own records.'], 403);
    }
    $student = m1_students($sid)[0] ?? null;
    if (!$student) {
        json_response(['error' => 'Student not found.'], 404);
    }
    $records = m1_records($sid);
    json_response(['student' => $student, 'records' => $records, 'terms' => m1_term_summary($records)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$admin = require_api_access(ROLE_ADMIN);
require_csrf_json();
$in = json_input();
$adminId = (int)$admin['user_id'];

try {
    switch ($in['action'] ?? '') {
        case 'add':
            m1_add_record(int_param($in['student_id'] ?? null) ?? 0, (string)($in['term'] ?? ''),
                          is_array($in['record'] ?? null) ? $in['record'] : [], $adminId);
            json_response(['ok' => true, 'data' => m1_page_data($admin)]);
        case 'save_term':
            m1_save_term(int_param($in['student_id'] ?? null) ?? 0, (string)($in['term'] ?? ''),
                         is_array($in['rows'] ?? null) ? $in['rows'] : [], $adminId);
            json_response(['ok' => true, 'data' => m1_page_data($admin)]);
        case 'import':
            $results = m1_import_records(is_array($in['rows'] ?? null) ? $in['rows'] : [], $adminId);
            json_response(['ok' => true, 'results' => $results, 'counts' => m1_count_results($results), 'data' => m1_page_data($admin)]);
        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (M1ValidationError $ex) {
    json_validation_error($ex);
}
