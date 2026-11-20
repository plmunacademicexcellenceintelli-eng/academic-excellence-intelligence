<?php
/**
 * Sign out. Only a POST with a valid CSRF token signs the user out
 * (so another website cannot sign users out with a hidden link).
 * The confirmation dialog is shown by the account menu (js/session.js);
 * opening this page directly shows a confirmation form instead.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

send_security_headers();
session_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $signedIn = !empty($_SESSION['user_id']);
    if (!$signedIn || csrf_valid(csrf_from_request())) {
        session_end_completely();
        // Ask the browser to drop any cached copies of protected pages from this site.
        header('Clear-Site-Data: "cache"');
        redirect('login.php?logged_out=1');
    }
    // Token missing or wrong: fall through and show the confirmation form with a fresh token.
}

$user = current_user($reason);
if ($user === null) {
    redirect('login.php' . ($reason === 'expired' ? '?expired=1' : ''));
}

auth_page_start('Sign out');
?>
      <h2 class="auth-title">Sign out?</h2>
      <p class="auth-text">You are signed in as <b><?= e($user['full_name']) ?></b>. You will need to sign in again to use the system.</p>
      <form method="post" action="<?= e(app_url('logout.php')) ?>" class="d-flex gap-2">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-p">Sign out</button>
        <a class="btn btn-o" href="<?= e(app_url(home_path_for($user))) ?>">Cancel</a>
      </form>
<?php
auth_page_end();
