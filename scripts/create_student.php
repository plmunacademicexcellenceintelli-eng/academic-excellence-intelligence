<?php
/**
 * Create the sign-in account for an EXISTING student record (command line). The username is the student number.
 *
 *   C:\xampp\php\php.exe scripts\create_student.php STUDENT_NUMBER
 *
 * Phase 3: student records are created in Admin > Student Management (or by CSV import) first;
 * this script only creates the login for that record. (Admins can also do this in Settings > User Accounts.)
 * A temporary password is generated and shown ONCE; the student must change it at first sign-in.
 */
declare(strict_types=1);
require __DIR__ . '/cli.php';

$number = $argv[1] ?? ask('Student number of an existing student record');
$st = db()->prepare('SELECT student_id FROM students WHERE student_number = ?');
$st->execute([$number]);
$sid = $st->fetchColumn();
if ($sid === false) {
    fail("No student record with student number \"$number\". Add the student in Admin > Student Management first.");
}
try {
    $r = create_student_login((int)$sid);
} catch (AccountError $ex) {
    fail($ex->getMessage());
}
echo PHP_EOL . "Student account created for Student ID {$r['student_id']}." . PHP_EOL
   . "  Username (student number): {$r['username']}" . PHP_EOL
   . "  Temporary password:        {$r['temp_password']}" . PHP_EOL
   . "Give the temporary password to the student privately. It is not shown again." . PHP_EOL;
