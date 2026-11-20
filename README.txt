PLMun Academic Excellence Intelligence (AEI) - PHASE 3 + UI REDESIGN ("navy and gold").
The redesign changes only the look (css/style.css, js/app.js presentation, includes/layout.php templates).
Database, APIs, login and calculations are unchanged - no SQL import is needed when updating from Phase 3.
See REDESIGN_NOTES.md (design system, files changed, testing report, XAMPP install / rollback).

PLMun Academic Excellence Intelligence (AEI) - PHASE 3 (Module 1 on MySQL).
PHP + MySQL (XAMPP). Sign-in (Phase 2) and Module 1 student profiles + academic records (Phase 3) are in the database.
Modules 2-5 are unchanged; co-curricular activities and the activity log are still kept in the browser.

UPGRADE FROM PHASE 2 (keeps your accounts)
1. phpMyAdmin -> aei_db -> Export -> Go   (backup)
2. Replace C:\xampp\htdocs\plmun-ui with this folder (keep your config\config.php password if you set one).
3. phpMyAdmin -> aei_db -> Import -> database\migrations\phase3_module1.sql -> Go
   (also import it again if you imported the earlier Phase 3 copy: it adds academic year + semester)
4. Optional demo data: Import -> database\seeds\phase3_demo_data.sql -> Go
5. Open http://localhost/plmun-ui/ and sign in with your existing Admin account.

FRESH INSTALL
Import database\aei_schema.sql, then database\migrations\phase3_module1.sql, then (optional) the demo seed.
Create the first Admin:  C:\xampp\php\php.exe scripts\create_admin.php
Student logins: add the student in Student Management, then Settings > User Accounts > Create student account.
Internet is required (Bootstrap, Bootstrap Icons and Chart.js load from a CDN).

READ NEXT: PHASE3_NOTES.md (setup, Module 1 guide, local testing checklist, limitations)
           TESTING_REPORT_PHASE3.md (tests that were actually executed)
Earlier notes: PHASE2_NOTES.md (accounts, security), PHASE1_NOTES.md, DEVELOPER_NOTES.md, DEFENSE_NOTES.md.
