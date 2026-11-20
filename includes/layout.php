<?php
/** HTML output: the protected portal page shell and the small sign-in style pages. */
declare(strict_types=1);

const CDN_BOOTSTRAP_CSS = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
const CDN_ICONS_CSS     = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';
const CDN_BOOTSTRAP_JS  = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';
const CDN_CHART_JS      = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
// Typefaces of the design system (UI redesign). Without internet the pages fall back to Georgia / system fonts.
const CDN_FONTS_CSS     = 'https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&display=swap';

/**
 * Output one protected portal page (Admin or Student). Used by every page file, e.g. dashboard.php.
 * The page body is still drawn by js/app.js exactly as in Phase 1; PHP adds the access check and
 * passes the signed-in account (from the database) to the page.
 */
function render_portal_page(string $role, string $page): void
{
    $user = require_page_access($role);
    try {
        // Module 1 data from MySQL. Admin: all students; Student: ONLY their own row and records.
        $data = m1_page_data($user);
    } catch (PDOException $ex) {
        error_log('AEI Module 1 data could not be loaded: ' . $ex->getMessage());
        http_response_code(503);
        render_message_page('Database update needed',
            "The academic records could not be loaded from the database.\nPlease contact the system administrator.",
            'database');
        exit;
    }
    $boot = [
        'data'        => $data,
        'seedActs'    => seed_activities_for($user),
        'dataSet'     => activity_data_set(),
        'demo'        => str_starts_with(strtolower((string)(cfg('db')['name'] ?? '')), 'aei_demo'),   // shows the "Demonstration data" label
        'user'        => public_user($user),
        'csrf'        => csrf_token(),
        'idleTimeout' => (int)(cfg('session')['idle_timeout'] ?? 1800),
        'urls'        => [
            'login'          => app_url('login.php'),
            'logout'         => app_url('logout.php'),
            'keepalive'      => app_url('api/keepalive.php'),
            'accounts'       => app_url('api/accounts.php'),
            'students'       => app_url('api/students.php'),
            'records'        => app_url('api/records.php'),
            'changePassword' => app_url('change-password.php'),
        ],
    ];
    ?>
<!DOCTYPE html><html lang=en><head><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'><title>PLMun AEI</title><link href='<?= CDN_BOOTSTRAP_CSS ?>' rel=stylesheet><link href='<?= CDN_ICONS_CSS ?>' rel=stylesheet><link rel=preconnect href=https://fonts.googleapis.com><link rel=preconnect href=https://fonts.gstatic.com crossorigin><link href='<?= CDN_FONTS_CSS ?>' rel=stylesheet><link href=css/style.css rel=stylesheet></head><body data-role=<?= e($role) ?> data-page=<?= e($page) ?>><div id=app></div><script>window.AEI_BOOT=<?= js_json($boot) ?>;</script><script src='<?= CDN_BOOTSTRAP_JS ?>'></script><script src='<?= CDN_CHART_JS ?>'></script><script src=js/data.js></script><script src=js/app.js></script><script src=js/session.js></script><?php if ($role === ROLE_ADMIN && $page === 'settings'): ?><script src=js/accounts.js></script><?php endif; ?></body></html>
<?php
}

/**
 * Module 4 sample activities (still kept in the browser until Module 4 is migrated).
 * They are no longer in the public js/data.js: an Admin receives all samples, a Student only their own.
 */
/** Module 4 sample-activity file for the configured database: data/seed_activities_<db name>.json when it exists
 *  (e.g. the demonstration database aei_demo), otherwise data/seed_activities.json. */
function activity_seed_file(): string
{
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)(cfg('db')['name'] ?? '')));
    $own  = AEI_ROOT . '/data/seed_activities_' . $name . '.json';
    return ($name !== '' && is_file($own)) ? $own : AEI_ROOT . '/data/seed_activities.json';
}

/** Short neutral label that keeps each database's Module 4 browser data separate ('' = the default database).
 *  Without it, activities saved in the browser while using one database would appear with the other one. */
function activity_data_set(): string
{
    return basename(activity_seed_file()) === 'seed_activities.json' ? '' : substr(sha1(basename(activity_seed_file())), 0, 8);
}

function seed_activities_for(array $user): array
{
    $all = json_decode((string)@file_get_contents(activity_seed_file()), true) ?: [];
    if ($user['role_key'] === ROLE_ADMIN) {
        return $all;
    }
    return array_values(array_filter($all, fn($a) => (int)$a[0] === (int)$user['student_id']));
}

/**
 * Start a sign-in style page (login, change password, sign out, messages):
 * a navy brand panel beside the form on wide screens; a compact brand line above the form on phones.
 */
function auth_page_start(string $title, string $subtitle = 'Student Performance Analytics and Achiever Identification System'): void
{
    $logo = e(app_url('assets/plmun-logo.png'));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · Academic Excellence Intelligence</title>
<link href="<?= CDN_BOOTSTRAP_CSS ?>" rel="stylesheet">
<link href="<?= CDN_ICONS_CSS ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?= CDN_FONTS_CSS ?>" rel="stylesheet">
<link href="<?= e(app_url('css/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="auth-split">
  <aside class="auth-brand" aria-label="About this system">
    <div class="auth-seal">
      <img src="<?= $logo ?>" alt="PLMun seal" onerror="this.style.visibility='hidden'">
      <span>Pamantasan ng Lungsod<br>ng Muntinlupa</span>
    </div>
    <div class="auth-lede">
      <h1>Academic Excellence Intelligence</h1>
      <p><?= e($subtitle) ?>. Academic records, honors screening, performance trends and co-curricular achievements in one place.</p>
    </div>
    <p class="auth-small mb-0">For authorized PLMun administrators and students.</p>
  </aside>
  <main class="auth-main">
    <div class="auth-mobile-brand">
      <img src="<?= $logo ?>" alt="" onerror="this.style.visibility='hidden'">
      <div><b>Academic Excellence Intelligence</b><small>Pamantasan ng Lungsod ng Muntinlupa</small></div>
    </div>
    <div class="auth-card">
<?php
}

function auth_page_end(array $scripts = []): void
{
    ?>
    </div>
    <p class="auth-foot">Academic Excellence Intelligence · PLMun · Authorized users only</p>
  </main>
</div>
<?php foreach ($scripts as $src): ?>
<script src="<?= e(app_url($src)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Simple message page (access denied, database unavailable, ...). */
function render_message_page(string $title, string $message, string $kind = 'info', ?array $action = null): void
{
    $icon = ['forbidden' => 'shield-lock', 'database' => 'database-exclamation'][$kind] ?? 'info-circle';
    auth_page_start($title);
    ?>
      <h2 class="auth-title"><i class="bi bi-<?= e($icon) ?>"></i> <?= e($title) ?></h2>
      <p class="auth-text"><?= nl2br(e($message)) ?></p>
      <?php if ($action): ?>
      <a class="btn btn-p" href="<?= e($action['href']) ?>"><?= e($action['label']) ?></a>
      <?php else: ?>
      <a class="btn btn-o" href="<?= e(app_url('login.php')) ?>">Go to sign-in page</a>
      <?php endif; ?>
<?php
    auth_page_end();
}
