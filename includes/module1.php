<?php
/**
 * MODULE 1 - Student Academic Data Integration & Profiling (server side).
 *
 * Tables: students (profile), academic_terms, subject_records (one row per subject per term).
 * - All reads/writes use PDO prepared statements.
 * - Every value is validated here, on the server, even when the browser already checked it.
 * - Semester GWA is never stored; it is calculated from subject_records (m1_calc_gwa, same formula as calcGWA() in js/data.js).
 * - Data isolation: m1_page_data() gives an Admin every student, and a Student ONLY their own student row and records
 *   (the student_id comes from the server session, never from the browser).
 */
declare(strict_types=1);

/** Programs (departments) offered in the Phase 1 forms. Edit this list and PROGRAMS in js/app.js together. */
const M1_PROGRAMS  = ['BSIT', 'BSCS', 'BSBA', 'BACOM', 'BSA', 'BS Psychology', 'BS Criminology', 'BEEd', 'BSEd'];
const M1_STANDINGS = ['Good Standing', 'Probation'];
const M1_STATUSES  = ['Active', 'Inactive'];
const M1_REMARKS   = ['Passed', 'Failed'];
const M1_MAX_IMPORT_ROWS = 500;

/** Validation problem with one message per field: ['field' => 'message']. */
class M1ValidationError extends RuntimeException
{
    public function __construct(public array $errors, string $message = 'Please fix the highlighted fields.')
    {
        parent::__construct($message);
    }
}

// ----------------------------------------------------------------------------------------------------------------
// Reading data
// ----------------------------------------------------------------------------------------------------------------

/** True when subject_records has the optional is_test column (migration 2026_11_18_subject_records_is_test.sql).
 *  Without it every record counts, exactly as before; the same code runs on both database versions. */
function m1_has_is_test(): bool
{
    static $has = null;
    if ($has === null) {
        try { db()->query('SELECT is_test FROM subject_records LIMIT 0'); $has = true; }
        catch (PDOException $e) { $has = false; }
    }
    return $has;
}

/** Academic terms in chronological order (sort_order):
 *  [['id' => 'T1', 'name' => 'AY 2023-2024 1st Semester', 'ay' => '2023-2024', 'sem' => '1st Semester', 'termId' => 1, 'loaded' => true], ...] */
function m1_terms(): array
{
    static $terms = null;   // terms do not change during a request
    if ($terms === null) {
        // has_records: the term has at least one COUNTED grade (is_test = 0) for any student. Used only to choose which
        // terms the cohort charts plot; it is a yes/no per term and reveals no student's record.
        // Honors "missing records" is decided per student in js/data.js (gaps between that student's own terms).
        $counted = m1_has_is_test() ? ' AND r.is_test = 0' : '';
        $rows  = db()->query('SELECT t.term_id, t.term_code, t.term_name, t.academic_year, t.semester,
                                     EXISTS (SELECT 1 FROM subject_records r WHERE r.term_id = t.term_id' . $counted . ') AS has_records
                                FROM academic_terms t ORDER BY t.sort_order')->fetchAll();
        $terms = array_map(fn($t) => ['id' => $t['term_code'], 'name' => $t['term_name'], 'ay' => $t['academic_year'],
                                      'sem' => $t['semester'], 'termId' => (int)$t['term_id'], 'loaded' => (bool)$t['has_records']], $rows);
    }
    return $terms;
}

/** "1st Semester", "1st Sem", "1st", "first semester" -> "1st"  (used to match the CSV semester column). */
function m1_semester_key(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/\s*(semester|sem\.?)\s*$/', '', $s);
    return strtr(trim($s), ['first' => '1st', 'second' => '2nd']);
}

/** Find a term by academic year ("2024-2025") + semester ("1st Semester"). */
function m1_term_by_year_and_semester(string $year, string $semester): ?array
{
    $y = preg_replace('/\s*[-\x{2013}\x{2014}]\s*/u', '-', trim($year));
    foreach (m1_terms() as $t) {
        if ($y !== '' && $t['ay'] === $y && m1_semester_key($t['sem']) === m1_semester_key($semester) && m1_semester_key($semester) !== '') {
            return $t;
        }
    }
    return null;
}

function m1_term_by_code_or_name(string $value): ?array
{
    $v = strtolower(trim($value));
    foreach (m1_terms() as $t) {
        if ($v !== '' && ($v === strtolower($t['id']) || $v === strtolower($t['name']))) {
            return $t;
        }
    }
    return null;
}

/** "Cruz, Andrea L." - display format used by every screen. */
function m1_display_name(string $last, string $first, ?string $middle): string
{
    return student_display_name($last, $first, $middle);
}

/** Student rows in the shape the screens use (js/data.js S[]). $onlyId = one student only. */
function m1_students(?int $onlyId = null): array
{
    $sql = 'SELECT student_id, user_id, student_number, last_name, first_name, middle_name, program, year_level, section,
                   standing, status
              FROM students' . ($onlyId !== null ? ' WHERE student_id = ?' : '') . ' ORDER BY student_id';
    $st = db()->prepare($sql);
    $st->execute($onlyId !== null ? [$onlyId] : []);
    return array_map(fn($s) => [
        'id'         => (int)$s['student_id'],
        'num'        => $s['student_number'],
        'last'       => $s['last_name'],
        'first'      => $s['first_name'],
        'middle'     => $s['middle_name'],
        'name'       => m1_display_name($s['last_name'], $s['first_name'], $s['middle_name']),
        'prog'       => $s['program'],
        'yr'         => (int)$s['year_level'],
        'sec'        => (string)$s['section'],
        'st'         => $s['standing'],
        'status'     => $s['status'],
        'hasAccount' => $s['user_id'] !== null,
    ], $st->fetchAll());
}

/** Subject records in the shape the screens use (js/data.js R[]). $studentId = one student only.
 *  test = true: a record marked as test data (subject_records.is_test = 1). It is shown, labelled, but never counted
 *  in GWA, honors screening or analytics. Without the is_test column (see m1_has_is_test) no record is a test record. */
function m1_records(?int $studentId = null): array
{
    $sql = 'SELECT r.record_id, r.student_id, t.term_code, r.subject_code, r.subject_title, r.units, r.grade, r.remarks, '
         . (m1_has_is_test() ? 'r.is_test' : '0 AS is_test') . '
              FROM subject_records r
              JOIN academic_terms t ON t.term_id = r.term_id'
         . ($studentId !== null ? ' WHERE r.student_id = ?' : '') . ' ORDER BY r.record_id';
    $st = db()->prepare($sql);
    $st->execute($studentId !== null ? [$studentId] : []);
    return array_map(fn($r) => [
        'rid'     => (int)$r['record_id'],
        'sid'     => (int)$r['student_id'],
        'tid'     => $r['term_code'],
        'code'    => $r['subject_code'],
        'title'   => $r['subject_title'],
        'units'   => (int)$r['units'],
        'grade'   => round((float)$r['grade'], 2),
        'remarks' => $r['remarks'],
        'test'    => (int)$r['is_test'] === 1,
    ], $st->fetchAll());
}

/**
 * Module 1 data embedded in a page.
 * Admin: all students and records. Student: ONLY the signed-in student's own row and records.
 */
function m1_page_data(array $user): array
{
    if ($user['role_key'] === ROLE_ADMIN) {
        return ['terms' => m1_terms(), 'students' => m1_students(), 'records' => m1_records()];
    }
    $own = (int)$user['student_id'];
    return ['terms' => m1_terms(), 'students' => m1_students($own), 'records' => m1_records($own)];
}

/** PROVISIONAL semester GWA = sum(units x grade) / sum(units), rounded to 2 decimals. Same as calcGWA() in js/data.js. */
function m1_calc_gwa(array $records): ?float
{
    $units = array_sum(array_map(fn($r) => (int)$r['units'], $records));
    if ($units === 0) {
        return null;
    }
    $sum = array_sum(array_map(fn($r) => (int)$r['units'] * (float)$r['grade'], $records));
    return round($sum / $units, 2);
}

/** GWA per term for one student (test records not counted):
 *  [['term' => 'T1', 'name' => ..., 'subjects' => n, 'units' => n, 'gwa' => x|null, 'testSubjects' => n], ...] */
function m1_term_summary(array $records): array
{
    $out = [];
    foreach (m1_terms() as $t) {
        $all = array_values(array_filter($records, fn($r) => $r['tid'] === $t['id']));
        $rs  = array_values(array_filter($all, fn($r) => empty($r['test'])));
        $out[] = ['term' => $t['id'], 'name' => $t['name'], 'academicYear' => $t['ay'], 'semester' => $t['sem'], 'subjects' => count($rs),
                  'units' => array_sum(array_column($rs, 'units')), 'gwa' => m1_calc_gwa($rs), 'testSubjects' => count($all) - count($rs)];
    }
    return $out;
}

// ----------------------------------------------------------------------------------------------------------------
// Validation (mirrors the browser checks in js/app.js and validateSubject() in js/data.js)
// ----------------------------------------------------------------------------------------------------------------

const M1_NAME_RE = '/^[\p{L}][\p{L} .\'-]{0,59}$/u';

/** Split "Last, First M." into [last, first, middle|null] (Phase 1 CSV "name" column). */
function m1_split_name(string $name): ?array
{
    $name = trim($name);
    if (!preg_match('/^[^<>&"]+,\s*[^<>&"]+$/', $name)) {
        return null;
    }
    [$last, $rest] = array_map('trim', explode(',', $name, 2));
    $parts  = preg_split('/\s+/', $rest);
    $middle = null;
    if (count($parts) > 1 && preg_match('/^[A-Za-z]\.?$/', (string)end($parts))) {
        $middle = array_pop($parts);
    }
    return [$last, implode(' ', $parts), $middle];
}

/** Validate one student profile. Returns clean values or throws M1ValidationError. */
function m1_validate_student(array $in): array
{
    $g = fn(string $k) => trim((string)($in[$k] ?? ''));
    $v = [
        'student_id'     => $g('student_id'),
        'student_number' => $g('student_number'),
        'last_name'      => preg_replace('/\s+/', ' ', $g('last_name')),
        'first_name'     => preg_replace('/\s+/', ' ', $g('first_name')),
        'middle_name'    => preg_replace('/\s+/', ' ', $g('middle_name')),
        'program'        => $g('program'),
        'year_level'     => $g('year_level'),
        'section'        => strtoupper($g('section')),
        'standing'       => $g('standing') === '' ? 'Good Standing' : $g('standing'),
        'status'         => $g('status') === '' ? 'Active' : $g('status'),
    ];
    // Accept values in any letter case ("bsit", "probation") and store the official spelling.
    foreach ([['program', M1_PROGRAMS], ['standing', M1_STANDINGS], ['status', M1_STATUSES]] as [$k, $list]) {
        foreach ($list as $opt) {
            if (strcasecmp($opt, $v[$k]) === 0) { $v[$k] = $opt; }
        }
    }
    $e = [];
    if (!preg_match('/^[1-9]\d{0,8}$/', $v['student_id']))                 $e['student_id'] = 'Student ID must be a positive whole number (up to 9 digits).';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{3,19}$/', $v['student_number'])) $e['student_number'] = 'Student number must be 4-20 letters, numbers or dashes.';
    if (!preg_match(M1_NAME_RE, $v['last_name']))                          $e['last_name'] = 'Last name is required (letters, spaces, dot, apostrophe or dash; up to 60).';
    if (!preg_match(M1_NAME_RE, $v['first_name']))                         $e['first_name'] = 'First name is required (letters, spaces, dot, apostrophe or dash; up to 60).';
    if ($v['middle_name'] !== '' && !preg_match(M1_NAME_RE, $v['middle_name'])) $e['middle_name'] = 'Middle name contains characters that are not allowed.';
    if (!in_array($v['program'], M1_PROGRAMS, true))                       $e['program'] = 'Choose a department from the list.';
    if (!preg_match('/^[1-4]$/', $v['year_level']))                        $e['year_level'] = 'Year level must be 1-4.';
    if (!preg_match('/^[A-Z0-9]{1,3}$/', $v['section']))                   $e['section'] = 'Section must be 1-3 letters/numbers.';
    if (!in_array($v['standing'], M1_STANDINGS, true))                     $e['standing'] = 'Standing must be Good Standing or Probation.';
    if (!in_array($v['status'], M1_STATUSES, true))                        $e['status'] = 'Status must be Active or Inactive.';
    if ($e) {
        throw new M1ValidationError($e);
    }
    $v['student_id']  = (int)$v['student_id'];
    $v['year_level']  = (int)$v['year_level'];
    $v['middle_name'] = $v['middle_name'] === '' ? null : $v['middle_name'];
    return $v;
}

/** Validate one subject record. Returns clean values or throws M1ValidationError (same rules as validateSubject()). */
function m1_validate_record(array $in): array
{
    $v = [
        'code'    => strtoupper(trim((string)($in['code'] ?? ''))),
        'title'   => trim((string)($in['title'] ?? '')),
        'units'   => trim((string)($in['units'] ?? '')),
        'grade'   => trim((string)($in['grade'] ?? '')),
        'remarks' => trim((string)($in['remarks'] ?? '')),
    ];
    foreach (M1_REMARKS as $opt) {
        if (strcasecmp($opt, $v['remarks']) === 0) { $v['remarks'] = $opt; }
    }
    $e = [];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,11}$/', $v['code'])) $e['code'] = 'Subject code is required (2-12 letters/numbers).';
    $len = mb_strlen($v['title']);
    if ($len < 3 || $len > 80 || preg_match('/[<>]/', $v['title']))    $e['title'] = 'Subject description is required (3-80 characters, no < >).';
    if (!preg_match('/^\d+$/', $v['units']) || (int)$v['units'] < 1 || (int)$v['units'] > 12) $e['units'] = 'Units must be a whole number from 1 to 12.';
    $gradeOk = preg_match('/^\d(\.\d{1,2})?$/', $v['grade']) && (float)$v['grade'] >= 1 && (float)$v['grade'] <= 5;
    if (!$gradeOk)                                                      $e['grade'] = 'Grade must be a number from 1.00 to 5.00.';
    if (!in_array($v['remarks'], M1_REMARKS, true))                     $e['remarks'] = 'Choose Passed or Failed.';
    elseif ($gradeOk && (float)$v['grade'] <= 3 && $v['remarks'] === 'Failed') $e['remarks'] = 'A grade of 3.00 or better cannot be marked Failed (Handbook grading scale).';
    elseif ($gradeOk && (float)$v['grade'] >= 5 && $v['remarks'] === 'Passed') $e['remarks'] = 'A grade of 5.00 cannot be marked Passed.';
    if ($e) {
        throw new M1ValidationError($e);
    }
    $v['units'] = (int)$v['units'];
    $v['grade'] = number_format((float)$v['grade'], 2, '.', '');
    return $v;
}

/** Default remark when a CSV row leaves it empty (same as autoRemark() in js/data.js). */
function m1_auto_remark(string $grade): string
{
    return (float)$grade <= 3 ? 'Passed' : 'Failed';
}

// ----------------------------------------------------------------------------------------------------------------
// Students: add / edit / import
// ----------------------------------------------------------------------------------------------------------------

function m1_student_row(int $id): ?array
{
    $st = db()->prepare('SELECT student_id, user_id, student_number FROM students WHERE student_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Insert or update one student (inside the caller's transaction when there is one). Returns 'added' or 'updated'. */
function m1_write_student(array $v, bool $isNew): string
{
    $now = now_sql();
    $existing = m1_student_row($v['student_id']);
    if ($isNew && $existing) {
        throw new M1ValidationError(['student_id' => "Student ID {$v['student_id']} already exists."]);
    }
    if (!$isNew && !$existing) {
        throw new M1ValidationError(['student_id' => "Student ID {$v['student_id']} was not found."]);
    }
    // Student number: unique among students, and not used as another account's sign-in name.
    $st = db()->prepare('SELECT student_id FROM students WHERE student_number = ? AND student_id <> ?');
    $st->execute([$v['student_number'], $v['student_id']]);
    if ($other = $st->fetchColumn()) {
        throw new M1ValidationError(['student_number' => "Student number {$v['student_number']} is already used by Student ID $other."]);
    }
    if ($existing && $existing['user_id'] !== null && strcasecmp((string)$existing['student_number'], $v['student_number']) !== 0) {
        throw new M1ValidationError(['student_number' => 'This student has a sign-in account, so the student number (their username) cannot be changed here.']);
    }
    $st = db()->prepare('SELECT user_id FROM users WHERE username = ?');
    $st->execute([$v['student_number']]);
    $uid = $st->fetchColumn();
    if ($uid !== false && (!$existing || (int)$uid !== (int)$existing['user_id'])) {
        throw new M1ValidationError(['student_number' => 'That student number is already used as a sign-in username.']);
    }

    if ($isNew) {
        db()->prepare(
            'INSERT INTO students (student_id, user_id, student_number, last_name, first_name, middle_name, program, year_level,
                                   section, standing, status, prototype_ref, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)'
        )->execute([$v['student_id'], $v['student_number'], $v['last_name'], $v['first_name'], $v['middle_name'],
                    $v['program'], $v['year_level'], $v['section'], $v['standing'], $v['status'], $now, $now]);
        return 'added';
    }
    db()->prepare(
        'UPDATE students SET student_number = ?, last_name = ?, first_name = ?, middle_name = ?, program = ?, year_level = ?,
                             section = ?, standing = ?, status = ?, updated_at = ?
          WHERE student_id = ?'
    )->execute([$v['student_number'], $v['last_name'], $v['first_name'], $v['middle_name'], $v['program'],
                $v['year_level'], $v['section'], $v['standing'], $v['status'], $now, $v['student_id']]);
    // Keep the linked account's display name in step with the profile.
    if ($existing['user_id'] !== null) {
        db()->prepare('UPDATE users SET full_name = ?, updated_at = ? WHERE user_id = ?')
            ->execute([m1_display_name($v['last_name'], $v['first_name'], $v['middle_name']), $now, $existing['user_id']]);
    }
    return 'updated';
}

/** Add Student / Edit Student form. */
function m1_save_student(array $in, bool $isNew): array
{
    $v = m1_validate_student($in);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        m1_write_student($v, $isNew);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $v;
}

/**
 * Student CSV import (Phase 1 columns: student_id, name, program, year_level, section [, standing, status],
 * plus student_number; "name" may be replaced by last_name/first_name/middle_name columns).
 * $rows: [['line' => n, 'data' => [...], 'overwrite' => bool], ...]. Rows are re-validated here.
 * Valid new rows are added; existing IDs are updated only when overwrite is ticked; everything else is reported.
 */
function m1_import_students(array $rows): array
{
    if (count($rows) > M1_MAX_IMPORT_ROWS) {
        throw new M1ValidationError(['file' => 'At most ' . M1_MAX_IMPORT_ROWS . ' rows can be imported at once.']);
    }
    $results = [];
    $seenId = $seenNum = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $k => $row) {
            $line = (int)($row['line'] ?? $k + 2);
            $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            try {
                if (trim((string)($d['last_name'] ?? '')) === '' && isset($d['name'])) {
                    $p = m1_split_name((string)$d['name']);
                    if (!$p) {
                        throw new M1ValidationError(['name' => 'Name must look like "Last, First M."']);
                    }
                    [$d['last_name'], $d['first_name'], $d['middle_name']] = $p;
                }
                $v = m1_validate_student($d);
                if (isset($seenId[$v['student_id']]))  throw new M1ValidationError(['student_id' => 'Duplicate student ID earlier in this file']);
                if (isset($seenNum[strtolower($v['student_number'])])) throw new M1ValidationError(['student_number' => 'Duplicate student number earlier in this file']);
                $seenId[$v['student_id']] = $seenNum[strtolower($v['student_number'])] = true;
                $exists = m1_student_row($v['student_id']) !== null;
                if ($exists && empty($row['overwrite'])) {
                    $results[] = ['line' => $line, 'result' => 'skipped', 'message' => 'Already exists (not updated)'];
                    continue;
                }
                $results[] = ['line' => $line, 'result' => m1_write_student($v, !$exists), 'message' => ''];
            } catch (M1ValidationError $ex) {
                $results[] = ['line' => $line, 'result' => 'rejected', 'message' => implode('; ', $ex->errors)];
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $results;
}

// ----------------------------------------------------------------------------------------------------------------
// Subject records: add / save a term / import
// ----------------------------------------------------------------------------------------------------------------

function m1_require_student_and_term(int $studentId, string $termCode): array
{
    if (!m1_student_row($studentId)) {
        throw new M1ValidationError(['student_id' => 'Student not found.']);
    }
    $t = m1_term_by_code_or_name($termCode);
    if (!$t) {
        throw new M1ValidationError(['term' => 'Academic term not found.']);
    }
    return $t;
}

/** Add Subject (one record). */
function m1_add_record(int $studentId, string $termCode, array $in, int $adminId): void
{
    $t = m1_require_student_and_term($studentId, $termCode);
    $v = m1_validate_record($in);
    $st = db()->prepare('SELECT 1 FROM subject_records WHERE student_id = ? AND term_id = ? AND subject_code = ?');
    $st->execute([$studentId, $t['termId'], $v['code']]);
    if ($st->fetchColumn()) {
        throw new M1ValidationError(['code' => "{$v['code']} already exists for this student and term."]);
    }
    $now = now_sql();
    db()->prepare(
        'INSERT INTO subject_records (student_id, term_id, subject_code, subject_title, units, grade, remarks, created_by, updated_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$studentId, $t['termId'], $v['code'], $v['title'], $v['units'], $v['grade'], $v['remarks'], $adminId, $adminId, $now, $now]);
}

/**
 * Edit Grades -> Save Changes: the complete list of subjects for one student in one term.
 * Rows with "rid" keep their record ID; rows without are new; rows that are missing are deleted. All-or-nothing.
 * Errors: ['rows' => ['Row 2: ...', ...]].
 */
function m1_save_term(int $studentId, string $termCode, array $rows, int $adminId): void
{
    $t = m1_require_student_and_term($studentId, $termCode);
    $st = db()->prepare('SELECT record_id, subject_code, subject_title, units, grade, remarks, ' . (m1_has_is_test() ? 'is_test' : '0 AS is_test') . ', created_by, created_at, updated_by, updated_at
                           FROM subject_records WHERE student_id = ? AND term_id = ?');
    $st->execute([$studentId, $t['termId']]);
    $old = [];
    foreach ($st->fetchAll() as $r) {
        $old[(int)$r['record_id']] = $r;
    }

    $clean = $errors = $seen = [];
    foreach (array_values($rows) as $k => $row) {
        $n = $k + 1;
        try {
            $v = m1_validate_record(is_array($row) ? $row : []);
            if (isset($seen[$v['code']])) {
                throw new M1ValidationError(['code' => "Duplicate subject code \"{$v['code']}\" in this term."]);
            }
            $seen[$v['code']] = true;
            $rid = isset($row['rid']) && $row['rid'] !== '' && $row['rid'] !== null ? (int)$row['rid'] : null;
            if ($rid !== null && !isset($old[$rid])) {
                throw new M1ValidationError(['rid' => 'This record does not belong to the selected student and term. Reload the page.']);
            }
            $v['rid'] = $rid;
            $clean[] = $v;
        } catch (M1ValidationError $ex) {
            foreach ($ex->errors as $m) {
                $errors[] = "Row $n: $m";
            }
        }
    }
    if ($errors) {
        throw new M1ValidationError(['rows' => $errors], 'Please fix the highlighted rows.');
    }

    $now = now_sql();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Remove the term's rows, then write the edited list back (existing rows keep their ID, creation details and
        // test flag - so saving a term never turns a test record into a counted one, or the reverse).
        $pdo->prepare('DELETE FROM subject_records WHERE student_id = ? AND term_id = ?')->execute([$studentId, $t['termId']]);
        $withTest = m1_has_is_test();
        $ins = $pdo->prepare(
            'INSERT INTO subject_records (record_id, student_id, term_id, subject_code, subject_title, units, grade, remarks, '
            . ($withTest ? 'is_test, ' : '') . 'created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . ($withTest ? '?, ' : '') . '?, ?, ?, ?)'
        );
        foreach ($clean as $v) {
            $o = $v['rid'] !== null ? $old[$v['rid']] : null;
            $same = $o && $o['subject_code'] === $v['code'] && $o['subject_title'] === $v['title'] && (int)$o['units'] === $v['units']
                 && number_format((float)$o['grade'], 2, '.', '') === $v['grade'] && $o['remarks'] === $v['remarks'];
            $vals = [$v['rid'], $studentId, $t['termId'], $v['code'], $v['title'], $v['units'], $v['grade'], $v['remarks']];
            if ($withTest) { $vals[] = $o ? (int)$o['is_test'] : 0; }
            array_push($vals, $o ? $o['created_by'] : $adminId, $same ? $o['updated_by'] : $adminId,
                       $o ? $o['created_at'] : $now, $same ? $o['updated_at'] : $now);
            $ins->execute($vals);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Academic records CSV import. Columns: student_id, subject_code, units, grade [, remarks], plus
 *   the term: "term" (code T1 or the stored term_name) OR "academic_year" + "semester";
 *   the description: "subject_description" (or the Phase 1 name "subject_title").
 * $rows: [['line' => n, 'data' => [...], 'overwrite' => bool], ...]. Everything is re-validated here.
 */
function m1_import_records(array $rows, int $adminId): array
{
    if (count($rows) > M1_MAX_IMPORT_ROWS) {
        throw new M1ValidationError(['file' => 'At most ' . M1_MAX_IMPORT_ROWS . ' rows can be imported at once.']);
    }
    $results = $seen = [];
    $now = now_sql();
    $pdo = db();
    $find = $pdo->prepare('SELECT record_id FROM subject_records WHERE student_id = ? AND term_id = ? AND subject_code = ?');
    $ins  = $pdo->prepare('INSERT INTO subject_records (student_id, term_id, subject_code, subject_title, units, grade, remarks, created_by, updated_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $upd  = $pdo->prepare('UPDATE subject_records SET subject_title = ?, units = ?, grade = ?, remarks = ?, updated_by = ?, updated_at = ? WHERE record_id = ?');
    $pdo->beginTransaction();
    try {
        foreach ($rows as $k => $row) {
            $line = (int)($row['line'] ?? $k + 2);
            $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            $errs = [];
            $sidRaw = trim((string)($d['student_id'] ?? ''));
            $sid = preg_match('/^[1-9]\d{0,8}$/', $sidRaw) ? (int)$sidRaw : 0;
            if (!$sid || !m1_student_row($sid)) $errs[] = "Student ID \"$sidRaw\" not found";
            $byYear = trim((string)($d['term'] ?? '')) === '' && (isset($d['academic_year']) || isset($d['semester']));
            $t = $byYear ? m1_term_by_year_and_semester((string)($d['academic_year'] ?? ''), (string)($d['semester'] ?? ''))
                         : m1_term_by_code_or_name((string)($d['term'] ?? ''));
            if (!$t) $errs[] = $byYear
                ? 'Academic year "' . trim((string)($d['academic_year'] ?? '')) . '" / semester "' . trim((string)($d['semester'] ?? '')) . '" is not a known term'
                : 'Invalid or missing term "' . trim((string)($d['term'] ?? '')) . '"';
            $rec = ['code' => $d['subject_code'] ?? '', 'title' => $d['subject_description'] ?? ($d['subject_title'] ?? ''), 'units' => $d['units'] ?? '',
                    'grade' => $d['grade'] ?? '', 'remarks' => $d['remarks'] ?? ''];
            if (trim((string)$rec['remarks']) === '' && preg_match('/^\d(\.\d{1,2})?$/', trim((string)$rec['grade']))) {
                $rec['remarks'] = m1_auto_remark(trim((string)$rec['grade']));
            }
            $v = null;
            try {
                $v = m1_validate_record($rec);
            } catch (M1ValidationError $ex) {
                $errs = array_merge($errs, array_values($ex->errors));
            }
            if (!$errs) {
                $key = "$sid|{$t['termId']}|{$v['code']}";
                if (isset($seen[$key])) $errs[] = 'Duplicate of an earlier row in this file';
                $seen[$key] = true;
            }
            if ($errs) {
                $results[] = ['line' => $line, 'result' => 'rejected', 'message' => implode('; ', $errs)];
                continue;
            }
            $find->execute([$sid, $t['termId'], $v['code']]);
            $rid = $find->fetchColumn();
            if ($rid !== false) {
                if (empty($row['overwrite'])) {
                    $results[] = ['line' => $line, 'result' => 'skipped', 'message' => 'Already exists (not overwritten)'];
                    continue;
                }
                $upd->execute([$v['title'], $v['units'], $v['grade'], $v['remarks'], $adminId, $now, $rid]);
                $results[] = ['line' => $line, 'result' => 'updated', 'message' => ''];
            } else {
                $ins->execute([$sid, $t['termId'], $v['code'], $v['title'], $v['units'], $v['grade'], $v['remarks'], $adminId, $adminId, $now, $now]);
                $results[] = ['line' => $line, 'result' => 'added', 'message' => ''];
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $results;
}

/** Count results by type: ['added' => n, 'updated' => n, 'skipped' => n, 'rejected' => n]. */
function m1_count_results(array $results): array
{
    $c = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0];
    foreach ($results as $r) {
        $c[$r['result']]++;
    }
    return $c;
}
