-- =====================================================================================
-- AEI - PHASE 3 MIGRATION: Module 1 (Student Academic Data Integration & Profiling)
--
-- Run this ONCE on the existing Phase 2 database `aei_db` (phpMyAdmin > select aei_db > Import).
-- * Adds tables/columns only. Nothing is dropped; existing Admin and Student accounts are kept.
-- * Safe to import again: every step checks whether it was already applied.
-- * Works on MariaDB 10.4+ (XAMPP) and MySQL 8.
-- Fresh installation order: database/aei_schema.sql -> this file -> (optional) database/seeds/phase3_demo_data.sql
-- =====================================================================================

USE `aei_db`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 0. schema_migrations: which migrations were applied (lets the application check the database version)
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version`     VARCHAR(50)  NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `applied_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 1. students.standing - academic standing set manually by the Admin (Phase 1 "Standing (manual)").
--    Added only if the column does not exist yet.
-- -------------------------------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'standing');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `students` ADD COLUMN `standing` VARCHAR(20) NOT NULL DEFAULT ''Good Standing'' AFTER `section`',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for searching students by name (added only if missing).
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND INDEX_NAME = 'idx_students_name');
SET @ddl := IF(@idx_exists = 0,
  'ALTER TABLE `students` ADD INDEX `idx_students_name` (`last_name`, `first_name`)',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------------------
-- 2. academic_terms: one row per academic term (academic year + semester). sort_order = chronological order.
--    term_code (T1..T4) is the short code used by the screens; CSV files may use the code, the term name,
--    or the academic_year + semester columns.
--    The four terms below are the terms used by the Phase 1/2 prototype. Modules 2-3 (honors screening,
--    trend forecast) are calibrated for exactly these four terms, so terms are not edited from the web in this phase.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `academic_terms` (
  `term_id`    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term_code`  VARCHAR(10)       NOT NULL,
  `term_name`  VARCHAR(40)       NOT NULL,
  `academic_year` VARCHAR(9)     NOT NULL DEFAULT '',   -- e.g. 2024-2025
  `semester`   VARCHAR(20)       NOT NULL DEFAULT '',   -- e.g. 1st Semester
  `sort_order` SMALLINT UNSIGNED NOT NULL,
  `created_at` DATETIME          NOT NULL,
  PRIMARY KEY (`term_id`),
  UNIQUE KEY `uq_terms_code`  (`term_code`),
  UNIQUE KEY `uq_terms_name`  (`term_name`),
  UNIQUE KEY `uq_terms_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- academic_year / semester were added to this table later in Phase 3: add them if the table was created without them.
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_terms' AND COLUMN_NAME = 'academic_year');
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `academic_terms` ADD COLUMN `academic_year` VARCHAR(9) NOT NULL DEFAULT '''' AFTER `term_name`, ADD COLUMN `semester` VARCHAR(20) NOT NULL DEFAULT '''' AFTER `academic_year`',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `academic_terms` (`term_code`, `term_name`, `academic_year`, `semester`, `sort_order`, `created_at`) VALUES
  ('T1', '1st Sem 2024', '2024-2025', '1st Semester', 1, NOW()),
  ('T2', '2nd Sem 2024', '2024-2025', '2nd Semester', 2, NOW()),
  ('T3', '1st Sem 2025', '2025-2026', '1st Semester', 3, NOW()),
  ('T4', '2nd Sem 2025', '2025-2026', '2nd Semester', 4, NOW());

-- Fill academic year / semester for the four prototype terms when they are still empty.
-- ASSUMPTION (to confirm with PLMun): the prototype labels "1st/2nd Sem 2024" belong to AY 2024-2025 and
-- "1st/2nd Sem 2025" to AY 2025-2026 - the only reading that keeps the prototype's chronological order.
UPDATE `academic_terms` SET `academic_year` = '2024-2025', `semester` = '1st Semester' WHERE `term_code` = 'T1' AND `academic_year` = '';
UPDATE `academic_terms` SET `academic_year` = '2024-2025', `semester` = '2nd Semester' WHERE `term_code` = 'T2' AND `academic_year` = '';
UPDATE `academic_terms` SET `academic_year` = '2025-2026', `semester` = '1st Semester' WHERE `term_code` = 'T3' AND `academic_year` = '';
UPDATE `academic_terms` SET `academic_year` = '2025-2026', `semester` = '2nd Semester' WHERE `term_code` = 'T4' AND `academic_year` = '';

-- One term per academic year + semester (added only if missing, after the values above are filled in).
SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_terms' AND INDEX_NAME = 'uq_terms_year_semester');
SET @ddl := IF(@idx_exists = 0,
  'ALTER TABLE `academic_terms` ADD UNIQUE INDEX `uq_terms_year_semester` (`academic_year`, `semester`)',
  'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------------------
-- 3. subject_records: one row per subject a student took in a term (Module 1 academic records).
--    * One student -> many terms -> many subjects. A subject code appears at most once per student per term.
--    * subject_title holds the SUBJECT DESCRIPTION shown on the screens; grade is the FINAL GRADE (1.00-5.00).
--    * Semester GWA is NOT stored: it is always calculated from these rows (no out-of-date copies).
--    * ON DELETE RESTRICT: a student or term that still has grades cannot be deleted by accident.
--    * created_by / updated_by: which Admin account entered or last changed the row.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subject_records` (
  `record_id`     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `student_id`    INT UNSIGNED      NOT NULL,
  `term_id`       SMALLINT UNSIGNED NOT NULL,
  `subject_code`  VARCHAR(12)       NOT NULL,
  `subject_title` VARCHAR(80)       NOT NULL,
  `units`         TINYINT UNSIGNED  NOT NULL,
  `grade`         DECIMAL(3,2)      NOT NULL,
  `remarks`       VARCHAR(10)       NOT NULL,
  `created_by`    INT UNSIGNED      NULL,
  `updated_by`    INT UNSIGNED      NULL,
  `created_at`    DATETIME          NOT NULL,
  `updated_at`    DATETIME          NOT NULL,
  PRIMARY KEY (`record_id`),
  UNIQUE KEY `uq_records_student_term_code` (`student_id`, `term_id`, `subject_code`),
  KEY `idx_records_term` (`term_id`),
  KEY `idx_records_created_by` (`created_by`),
  KEY `idx_records_updated_by` (`updated_by`),
  CONSTRAINT `fk_records_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_records_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`term_id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_records_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_records_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `chk_records_units`   CHECK (`units` BETWEEN 1 AND 12),
  CONSTRAINT `chk_records_grade`   CHECK (`grade` BETWEEN 1.00 AND 5.00),
  CONSTRAINT `chk_records_remarks` CHECK (`remarks` IN ('Passed', 'Failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 4. Record that this migration was applied.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026_11_phase3_module1', 'Module 1: academic_terms (academic year + semester), subject_records, students.standing', NOW());

-- Check after import:  SHOW TABLES;  -> academic_terms, login_attempts, roles, schema_migrations, students, subject_records, users
--                      SELECT term_code, term_name, academic_year, semester FROM academic_terms;  -> 4 rows (T1..T4) with years filled in
