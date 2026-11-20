<?php
/**
 * Loaded first by every PHP entry point (pages, API endpoints, login/logout).
 * Loads configuration, sets error handling and pulls in the shared backend files.
 */
declare(strict_types=1);

define('AEI_ROOT', dirname(__DIR__));

$GLOBALS['AEI_CONFIG'] = require AEI_ROOT . '/config/config.php';

date_default_timezone_set($GLOBALS['AEI_CONFIG']['app']['timezone'] ?? 'Asia/Manila');

// Never print PHP errors to visitors; they go to the PHP/Apache error log instead.
ini_set('display_errors', !empty($GLOBALS['AEI_CONFIG']['app']['debug']) ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/module1.php';
require_once __DIR__ . '/layout.php';
