<?php
/**
 * GET api/student.php                  -> the signed-in student's own student record
 * GET api/student.php?student_id=N     -> Admin: student N.  Student: allowed ONLY if N is their own ID (else 403).
 *
 * This is the pattern every future student-data endpoint (grades, honors, activities) must follow:
 * the student ID a student may see comes from the SESSION, never from the request.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed.'], 405);
}
$user = require_api_access(null);

if (isset($_GET['student_id'])) {
    if (!preg_match('/^[1-9]\d{0,9}$/', (string)$_GET['student_id'])) {
        json_response(['error' => 'Invalid student ID.'], 400);
    }
    $studentId = (int)$_GET['student_id'];
} elseif ($user['role_key'] === ROLE_STUDENT) {
    $studentId = (int)$user['student_id'];
} else {
    json_response(['error' => 'student_id is required.'], 400);
}

if (!can_access_student($user, $studentId)) {
    json_response(['error' => 'You can only view your own records.'], 403);
}

$st = db()->prepare(
    'SELECT s.student_id, s.student_number, s.last_name, s.first_name, s.middle_name, s.program,
            s.year_level, s.section, s.standing, s.status
       FROM students s WHERE s.student_id = ?'
);
$st->execute([$studentId]);
$row = $st->fetch();
if (!$row) {
    json_response(['error' => 'Student not found.'], 404);
}
json_response(['student' => $row]);
