<?php
/**
 * MODULE 1 - student profiles.
 *   GET                         Admin: all students.  Student: only their own profile (from the session).
 *   POST {action:"create"|"update", student:{...}}            Admin only
 *   POST {action:"import", rows:[{line, data:{...}, overwrite}]}  Admin only (CSV rows, re-validated here)
 * POST requests need the CSRF token in the X-CSRF-Token header.
 * Every successful POST returns the refreshed Module 1 data so the page shows exactly what the database holds.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = require_api_access(null);
    $data = m1_page_data($user);
    json_response(['students' => $data['students']]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$admin = require_api_access(ROLE_ADMIN);
require_csrf_json();
$in = json_input();

try {
    switch ($in['action'] ?? '') {
        case 'create':
        case 'update':
            $isNew = $in['action'] === 'create';
            $v = m1_save_student(is_array($in['student'] ?? null) ? $in['student'] : [], $isNew);
            json_response(['ok' => true, 'message' => "Student {$v['student_id']} " . ($isNew ? 'added' : 'updated') . ' successfully.',
                           'id' => $v['student_id'], 'data' => m1_page_data($admin)]);
        case 'import':
            $results = m1_import_students(is_array($in['rows'] ?? null) ? $in['rows'] : []);
            json_response(['ok' => true, 'results' => $results, 'counts' => m1_count_results($results), 'data' => m1_page_data($admin)]);
        default:
            json_response(['error' => 'Unknown action.'], 400);
    }
} catch (M1ValidationError $ex) {
    json_validation_error($ex);
}
