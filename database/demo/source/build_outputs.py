#!/usr/bin/env python3
"""Builds every demo output from demo.json:
  - <project>/database/demo/aei_demo_install.sql        MySQL/MariaDB: creates the SEPARATE database aei_demo
  - <project>/data/seed_activities_aei_demo.json        Module 4 sample activities for aei_demo
  - <project>/tests/demo/demo_sqlite.sql               SQLite twin of the demo data (test servers only)
  - demo_accounts.json (build folder, not shipped)      account list used by the docs and tests
Usage: python3 build_outputs.py <project_dir>
"""
import json, os, sys, subprocess, random, hashlib, re

HERE = os.path.dirname(os.path.abspath(__file__))
PROJ = sys.argv[1]
D = json.load(open(os.path.join(HERE, 'demo.json')))
CUR = json.load(open(os.path.join(HERE, 'curricula.json')))
TITLE_MAX = 80

# Titles longer than the 80-character column: use the title that the OTHER provided checklists give for the same
# code (BACOM/BSBA list PFIT100/PFIT200 under their short titles). Nothing is invented or cut.
short = {}
for prog in CUR:
    if prog.startswith('_'): continue
    for t in CUR[prog]['terms'].values():
        for code, title, units in t['subjects']:
            if len(title) <= TITLE_MAX:
                short.setdefault(code, title)
replaced = {}
for r in D['records']:
    if len(r['title']) > TITLE_MAX:
        replaced[r['code']] = (r['title'], short[r['code']])
        r['title'] = short[r['code']]
assert all(len(r['title']) <= TITLE_MAX and len(r['code']) <= 12 for r in D['records'])

# ---------------------------------------------------------------- accounts (fictional, demo database only)
ADMIN = dict(username='demo.admin', full_name='Demo Administrator', password='AeiDemoAdmin2026')
STUDENT_PASSWORD = 'AeiDemoStudent26'
ACCOUNT_PROFILES = [('BACOM', 'top'), ('BACOM', 'improving3'), ('BSIT', 'magna'), ('BSIT', 'probation3'),
                    ('BSBA', 'cumlaude'), ('BSBA', 'declining4')]
def bcrypt(pw):
    return subprocess.check_output(['php', '-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', pw]).decode()
accounts = [dict(user_id=1, role_id=1, username=ADMIN['username'], full_name=ADMIN['full_name'], password=ADMIN['password'],
                 hash=bcrypt(ADMIN['password']), student_id=None)]
uid = 2
for prog, prof in ACCOUNT_PROFILES:
    s = next(x for x in D['students'] if x['prog'] == prog and x['profile'] == prof)
    accounts.append(dict(user_id=uid, role_id=2, username=s['num'], full_name=f"{s['first']} {s['last']}", password=STUDENT_PASSWORD,
                         hash=bcrypt(STUDENT_PASSWORD), student_id=s['id'], profile=prof, prog=prog))
    s['user_id'] = uid; uid += 1

# ---------------------------------------------------------------- Module 4 activities (fictional organizations)
ORG = {
 'BACOM': dict(org='Communication Arts Society', club='Campus Broadcasting Guild', pub='College Student Publication',
               comp=('Inter-School Broadcasting Competition', 'Academic Competition'), comp2=('Campus Journalism Fair', 'Academic Competition'),
               sem='Media Literacy Seminar', sem2='Risk Communication Workshop'),
 'BSIT':  dict(org='Information Technology Society', club='Programming Club', pub='Web Development Circle',
               comp=('Inter-School Programming Contest', 'Technical Competition'), comp2=('Web Design Competition', 'Technical Competition'),
               sem='Cybersecurity Awareness Seminar', sem2='Cloud Computing Workshop'),
 'BSBA':  dict(org='Marketing Students Society', club='Entrepreneurs Circle', pub='Business Case Study Group',
               comp=('Business Plan Competition', 'Academic Competition'), comp2=('Marketing Plan Challenge', 'Academic Competition'),
               sem='Digital Marketing Seminar', sem2='Financial Literacy Workshop'),
}
COMMUNITY = [('Coastal Clean-up Drive', 'Community Involvement', 'Barangay Environment Program', 'Volunteer', 'Barangay'),
             ('Literacy Tutorial Program', 'Volunteer Activities', 'Barangay Learning Center', 'Tutor', 'Barangay'),
             ('Relief Packing Volunteer', 'Volunteer Activities', 'City Disaster Response Office', 'Volunteer', 'City'),
             ('Feeding Program', 'Community Involvement', 'Barangay Outreach', 'Volunteer', 'Barangay')]
COUNT = {'top': 6, 'magna': 5, 'cumlaude': 4, 'gradelimit': 3, 'average4': 2, 'declining4': 2, 'gap4': 3,
         'improving3': 3, 'high3': 4, 'probation3': 1, 'inactive3': 1, 'second_hi': 2, 'second_av': 1, 'first': 0}
ENTRY = {4: 2023, 3: 2024, 2: 2025, 1: 2026}
acts, aid = [], 1
for s in D['students']:
    rng = random.Random(int(hashlib.sha256(('acts' + s['num']).encode()).hexdigest()[:8], 16))
    o = ORG[s['prog']]; n = COUNT[s['profile']]
    years = [f'{y}-{y + 1}' for y in range(ENTRY[s['yr']], 2026)] or ['2026-2027']
    pool = [
        (f"President, {o['org']}", 'Leadership', o['org'], 'President', 'College', '', 'Elected for the academic year.'),
        (f"Member, {o['club']}", 'Organization Membership', o['club'], 'Member', 'College', '', ''),
        (o['comp'][0], o['comp'][1], o['comp'][0], 'Participant', 'City', rng.choice(['1st Place', '2nd Place', 'Finalist']), ''),
        (o['sem'], 'Seminar and Training', o['sem'], 'Participant', 'University', 'Certificate', ''),
        (f"Secretary, {o['pub']}", 'Leadership', o['pub'], 'Secretary', 'College', '', ''),
        (o['comp2'][0], o['comp2'][1], o['comp2'][0], 'Participant', 'University', rng.choice(['Champion', 'Finalist', '']), ''),
        (o['sem2'], 'Seminar and Training', o['sem2'], 'Participant', 'College', 'Certificate', ''),
    ]
    if s['profile'] not in ('top', 'magna', 'high3'):
        pool = [pool[1], pool[3], pool[6], pool[2]]          # members/trainees; leadership posts go to the strongest profiles
    pool += [(t, c, org, role, lvl, '', '') for t, c, org, role, lvl in rng.sample(COMMUNITY, 2)]
    chosen = pool[:max(0, n - 1)] + ([pool[-1]] if n else [])
    for k, (title, cat, org, role, lvl, achv, desc) in enumerate(chosen):
        year = years[min(k % len(years), len(years) - 1)] if s['yr'] > 1 else '2026-2027'
        ver = 'Verified'
        if k == len(chosen) - 1 and n >= 3: ver = 'Pending Verification'
        if s['profile'] == 'inactive3': ver = 'Rejected'; desc = 'Certificate of participation could not be verified.'
        if ver == 'Pending Verification' and not desc: desc = 'Waiting for the organization adviser\'s certification.'
        acts.append([s['id'], title[:80], cat, org[:60], role, lvl, year, achv, ver, desc, f'D{aid:03d}']); aid += 1

# ---------------------------------------------------------------- SQL helpers
def q(v):
    if v is None: return 'NULL'
    if isinstance(v, (int, float)): return f'{v:.2f}' if isinstance(v, float) else str(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"
TERM_FINAL = [('T1', 'AY 2023-2024 1st Semester', '2023-2024', '1st Semester'), ('T2', 'AY 2023-2024 2nd Semester', '2023-2024', '2nd Semester'),
              ('T3', 'AY 2024-2025 1st Semester', '2024-2025', '1st Semester'), ('T4', 'AY 2024-2025 2nd Semester', '2024-2025', '2nd Semester'),
              ('T5', 'AY 2025-2026 1st Semester', '2025-2026', '1st Semester'), ('T6', 'AY 2025-2026 2nd Semester', '2025-2026', '2nd Semester'),
              ('T7', 'AY 2026-2027 1st Semester', '2026-2027', '1st Semester')]
NOW = "'2026-11-20 08:00:00'"

def data_sql(sqlite=False):
    L = []
    L.append('-- Demo accounts (fictional). Passwords are stored only as bcrypt hashes; see DEMO_ACCOUNTS.md.')
    L.append('INSERT INTO users (user_id, role_id, username, password_hash, full_name, email, is_active, must_change_password, created_at, updated_at) VALUES')
    L.append(',\n'.join(f"  ({a['user_id']}, {a['role_id']}, {q(a['username'])}, {q(a['hash'])}, {q(a['full_name'])}, NULL, 1, 0, {NOW}, {NOW})" for a in accounts) + ';')
    L.append('')
    L.append('-- 42 fictional students (14 each for BACOM, BSIT, BSBA). Student numbers start with DEMO- so they are never confused with real records.')
    L.append('INSERT INTO students (student_id, user_id, student_number, last_name, first_name, middle_name, program, year_level, section, standing, status, prototype_ref, created_at, updated_at) VALUES')
    L.append(',\n'.join(f"  ({s['id']}, {q(s.get('user_id'))}, {q(s['num'])}, {q(s['last'])}, {q(s['first'])}, {q(s['middle'])}, {q(s['prog'])}, {s['yr']}, {q(s['sec'])}, {q(s['standing'])}, {q(s['status'])}, NULL, {NOW}, {NOW})" for s in D['students']) + ';')
    L.append('')
    L.append(f"-- {len(D['records'])} subject records from the provided curriculum checklists (NSTP has 0 units and is not stored).")
    tid = (lambda t: f"(SELECT term_id FROM academic_terms WHERE term_code = '{t}')") if sqlite else (lambda t: f"@{t.lower()}")
    if not sqlite:
        L.append(' '.join(f"SET @{t[0].lower()} := (SELECT term_id FROM academic_terms WHERE term_code = '{t[0]}');" for t in TERM_FINAL))
    for i in range(0, len(D['records']), 200):
        chunk = D['records'][i:i + 200]
        L.append('INSERT INTO subject_records (record_id, student_id, term_id, subject_code, subject_title, units, grade, remarks, is_test, created_by, updated_by, created_at, updated_at) VALUES')
        L.append(',\n'.join(f"  ({r['rid']}, {r['sid']}, {tid(r['tid'])}, {q(r['code'])}, {q(r['title'])}, {r['units']}, {r['grade']:.2f}, {q(r['remarks'])}, 0, NULL, NULL, {NOW}, {NOW})" for r in chunk) + ';')
    return '\n'.join(L)

# ---------------------------------------------------------------- MySQL installer (separate database aei_demo)
def body(path, drop_use=True):
    s = open(os.path.join(PROJ, path)).read()
    s = re.sub(r"(?m)^USE `aei_db`;\s*$", '', s)
    s = re.sub(r"(?ms)^CREATE DATABASE IF NOT EXISTS `aei_db`.*?;\s*$", '', s)
    s = re.sub(r"(?m)^DROP TABLE IF EXISTS `[a-z_]+`;\s*$", '', s)      # fresh database: nothing to drop
    s = '\n'.join(l.replace('`aei_db`', 'the database').replace('aei_db', 'the database') if l.lstrip().startswith('--') else l for l in s.split('\n'))
    assert 'aei_db' not in s, path          # no SQL statement may refer to aei_db
    return s
is_test_mig = """-- subject_records.is_test (0 = counted record; 1 = test record shown but never counted). All demo records are 0.
ALTER TABLE `subject_records` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0 AFTER `remarks`;
INSERT IGNORE INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026_11_subject_records_is_test', 'subject_records.is_test: test records are shown but not counted', NOW());"""
terms_sql = """-- The seven academic terms (confirmed mapping). Phase 3 created T1-T4 with its earlier labels; set the final values.
UPDATE `academic_terms` SET `academic_year` = CONCAT('TMP-', `term_id`), `term_name` = CONCAT('TMP ', `term_code`), `sort_order` = 1000 + `term_id`;
""" + '\n'.join(f"UPDATE `academic_terms` SET `term_name` = '{n}', `academic_year` = '{ay}', `semester` = '{sem}', `sort_order` = {i + 1} WHERE `term_code` = '{c}';"
                for i, (c, n, ay, sem) in enumerate(TERM_FINAL[:4])) + """
INSERT INTO `academic_terms` (`term_code`, `term_name`, `academic_year`, `semester`, `sort_order`, `created_at`) VALUES
""" + ',\n'.join(f"  ('{c}', '{n}', '{ay}', '{sem}', {i + 5}, NOW())" for i, (c, n, ay, sem) in enumerate(TERM_FINAL[4:])) + """;
INSERT IGNORE INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026_11_terms_relabel_and_add', 'Seven academic terms AY 2023-2024 1st Semester to AY 2026-2027 1st Semester', NOW());"""

install = f"""-- =====================================================================================
-- AEI - DEMONSTRATION DATABASE INSTALLER  (separate database `aei_demo`)
--
-- Creates a NEW database named `aei_demo` with the full AEI structure, the seven academic terms and fictional
-- demonstration data. Your working database `aei_db` is NOT used, read or changed by this file.
--
--   * Safety: the first statement is CREATE DATABASE `aei_demo` (without IF NOT EXISTS). If aei_demo already
--     exists, the import stops at line 1 and changes nothing. To reinstall, drop aei_demo first (phpMyAdmin >
--     aei_demo > Operations > Drop the database) - never drop aei_db.
--   * Data: 42 fictional students (BACOM, BSIT, BSBA; 14 each), {len(D['records'])} subject records taken from the provided
--     curriculum checklists, 1 demo admin account and 6 demo student accounts (see DEMO_ACCOUNTS.md).
--     Every student number starts with DEMO-. No real PLMun student information is included.
--   * Use: set 'name' => 'aei_demo' in config/config.php (db section). Set it back to 'aei_db' afterwards.
--
-- Import: phpMyAdmin > Import > choose this file > Go   (do not select a database first).
-- Requires MariaDB 10.4+ (XAMPP) or MySQL 8.
-- Generated from: database/aei_schema.sql + database/migrations/phase3_module1.sql + the is_test column +
-- the seven-term mapping + demo data. Do not edit by hand; regenerate with the build script instead.
-- =====================================================================================
CREATE DATABASE `aei_demo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `aei_demo`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ======================== 1. Phase 2 structure (database/aei_schema.sql) ========================
{body('database/aei_schema.sql')}

-- ======================== 2. Phase 3 structure (database/migrations/phase3_module1.sql) ========================
{body('database/migrations/phase3_module1.sql')}

-- ======================== 3. Test-record flag ========================
{is_test_mig}

-- ======================== 4. Academic terms ========================
{terms_sql}

-- ======================== 5. Fictional demonstration data ========================
START TRANSACTION;
{data_sql()}
COMMIT;

-- ======================== 6. Checks (expected values in the comments) ========================
-- 7 terms; 42 students (14 per program); {len(D['records'])} subject records; 7 accounts (1 admin, 6 students).
SELECT (SELECT COUNT(*) FROM academic_terms) AS terms, (SELECT COUNT(*) FROM students) AS students,
       (SELECT COUNT(*) FROM subject_records) AS subject_records, (SELECT COUNT(*) FROM users) AS accounts;
SELECT program, COUNT(*) AS students FROM students GROUP BY program ORDER BY program;   -- BACOM 14, BSBA 14, BSIT 14
SELECT t.term_code, t.academic_year, t.semester, COUNT(r.record_id) AS records
  FROM academic_terms t LEFT JOIN subject_records r ON r.term_id = t.term_id GROUP BY t.term_id ORDER BY t.sort_order;
"""
os.makedirs(os.path.join(PROJ, 'database/demo'), exist_ok=True)
open(os.path.join(PROJ, 'database/demo/aei_demo_install.sql'), 'w').write(install)

# ---------------------------------------------------------------- SQLite twin (tests only)
os.makedirs(os.path.join(PROJ, 'tests/demo'), exist_ok=True)
twin = ["-- TEST ONLY: SQLite twin of database/demo/aei_demo_install.sql (data part). Applied after the SQLite schema twins.",
        "ALTER TABLE subject_records ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0;",
        "UPDATE academic_terms SET academic_year = 'TMP-' || term_id, term_name = 'TMP ' || term_code, sort_order = 1000 + term_id;"]
twin += [f"UPDATE academic_terms SET term_name = '{n}', academic_year = '{ay}', semester = '{sem}', sort_order = {i + 1} WHERE term_code = '{c}';" for i, (c, n, ay, sem) in enumerate(TERM_FINAL[:4])]
twin += [f"INSERT INTO academic_terms (term_code, term_name, academic_year, semester, sort_order, created_at) VALUES ('{c}', '{n}', '{ay}', '{sem}', {i + 5}, datetime('now'));" for i, (c, n, ay, sem) in enumerate(TERM_FINAL[4:])]
twin += ["DELETE FROM subject_records; DELETE FROM students; DELETE FROM login_attempts; DELETE FROM users;", data_sql(sqlite=True)]
open(os.path.join(PROJ, 'tests/demo/demo_sqlite.sql'), 'w').write('\n'.join(twin) + '\n')

# ---------------------------------------------------------------- Module 4 seed + accounts list
json.dump(acts, open(os.path.join(PROJ, 'data/seed_activities_aei_demo.json'), 'w'), ensure_ascii=False, separators=(',', ':'))
json.dump(dict(admin={k: accounts[0][k] for k in ('username', 'password', 'full_name')},
               students=[{k: a[k] for k in ('username', 'password', 'full_name', 'student_id', 'profile', 'prog')} for a in accounts[1:]],
               replaced_titles=replaced), open(os.path.join(HERE, 'demo_accounts.json'), 'w'), indent=1)
json.dump(D, open(os.path.join(HERE, 'demo_final.json'), 'w'), indent=1)
print(f"install SQL: {len(install)//1024} KB; activities: {len(acts)}; accounts: {len(accounts)}; titles replaced: {replaced}")
