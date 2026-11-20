<?php
/**
 * POST: called by js/session.js while the user is actively using a page, so working on one page
 * (for example editing a table) counts as activity. Returns 401 when the session has expired.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}
$user = require_api_access(null, true);
require_csrf_json();
json_response(['ok' => true, 'idleTimeout' => (int)(cfg('session')['idle_timeout'] ?? 1800)]);
