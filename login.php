<?php
/**
 * Sign-in page. One form for everyone: the role is NOT chosen by the user,
 * it is read from the database after the password is verified.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

send_security_headers();

// Already signed in -> go to the correct portal.
$existing = current_user($reason);
if ($existing !== null) {
    redirect((int)$existing['must_change_password'] === 1 ? 'change-password.php' : home_path_for($existing));
}

$error      = null;
$identifier = '';
$notice     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');

    if (!csrf_valid(csrf_from_request())) {
        $error = 'Your sign-in form expired. Please try again.';
    } elseif ($identifier === '' && $password === '') {
        $error = 'Enter your username or student number and your password.';
    } elseif ($identifier === '') {
        $error = 'Enter your username or student number.';
    } elseif ($password === '') {
        $error = 'Enter your password.';
    } else {
        $result = attempt_login($identifier, $password);
        if ($result['ok']) {
            $u = $result['user'];
            redirect((int)$u['must_change_password'] === 1 ? 'change-password.php' : home_path_for($u));
        }
        $error = $result['error'];
    }
} else {
    if (isset($_GET['logged_out'])) {
        $notice = ['success', 'You have been signed out.'];
    } elseif (isset($_GET['expired']) || $reason === 'expired') {
        $notice = ['warning', 'Your session expired because of inactivity. Please sign in again.'];
    } elseif (isset($_GET['revoked'])) {
        $notice = ['warning', 'Your session ended because your account was changed or deactivated.'];
    } elseif (isset($_GET['password_changed'])) {
        $notice = ['success', 'Your password was changed. Please sign in with your new password.'];
    }
}

auth_page_start('Sign in');
?>
      <h2 class="auth-title">Sign in</h2>
      <p class="auth-text">Use your administrator username or your student number.</p>

      <?php if ($notice): ?>
      <div class="alert alert-<?= e($notice[0]) ?> py-2 small" role="status"><?= e($notice[1]) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="alert alert-danger py-2 small" role="alert" id="loginError"><i class="bi bi-exclamation-circle"></i> <?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= e(app_url('login.php')) ?>" id="loginForm" novalidate autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label for="identifier" class="form-label">Username or Student Number</label>
          <input type="text" class="form-control" id="identifier" name="identifier" maxlength="50"
                 value="<?= e($identifier) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required
                 <?= $identifier === '' ? 'autofocus' : '' ?>>
          <div class="invalid-feedback">Enter your username or student number.</div>
        </div>
        <div class="mb-3">
          <label for="password" class="form-label">Password</label>
          <div class="input-group has-validation">
            <input type="password" class="form-control" id="password" name="password" maxlength="200"
                   autocomplete="current-password" required <?= $identifier !== '' ? 'autofocus' : '' ?>>
            <button class="btn btn-o pw-toggle" type="button" data-target="password" aria-controls="password" aria-label="Show password">Show</button>
            <div class="invalid-feedback">Enter your password.</div>
          </div>
        </div>
        <button type="submit" class="btn btn-p w-100" id="loginBtn">Sign in</button>
      </form>
      <p class="auth-help">Forgot your password or locked out? Please contact the system administrator.<br>
        Accounts are created by the administrator; there is no self-registration.</p>
<?php
auth_page_end(['js/auth-pages.js']);
