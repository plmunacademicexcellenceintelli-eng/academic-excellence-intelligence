<?php
/** Change your own password. Required at first sign-in for accounts with a temporary password. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user   = require_page_access(null, true);
$forced = (int)$user['must_change_password'] === 1;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid(csrf_from_request())) {
        $error = 'Security check failed. Please try again.';
    } else {
        try {
            change_own_password((int)$user['user_id'], (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
            // Sign out everywhere: end this session and ask for the new password.
            session_end_completely();
            redirect('login.php?password_changed=1');
        } catch (AccountError $ex) {
            $error = $ex->getMessage();
        }
    }
}

auth_page_start('Change password');
?>
      <h2 class="auth-title">Change password</h2>
      <p class="auth-text">Signed in as <b><?= e($user['full_name']) ?></b> (<?= e($user['username']) ?>).</p>
      <?php if ($forced): ?>
      <div class="alert alert-warning py-2 small" role="status">You are using a temporary password. Choose a new password to continue.</div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle"></i> <?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= e(app_url('change-password.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
        <div class="mb-3">
          <label for="current_password" class="form-label">Current password</label>
          <div class="input-group has-validation">
            <input type="password" class="form-control" id="current_password" name="current_password" maxlength="200" autocomplete="current-password" required autofocus>
            <button class="btn btn-o pw-toggle" type="button" data-target="current_password" aria-label="Show password">Show</button>
            <div class="invalid-feedback">Enter your current password.</div>
          </div>
        </div>
        <div class="mb-3">
          <label for="new_password" class="form-label">New password</label>
          <div class="input-group has-validation">
            <input type="password" class="form-control" id="new_password" name="new_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="72"
                   pattern="(?=.*[A-Za-z])(?=.*\d).{<?= PASSWORD_MIN_LENGTH ?>,72}" autocomplete="new-password" required aria-describedby="pwHelp">
            <button class="btn btn-o pw-toggle" type="button" data-target="new_password" aria-label="Show password">Show</button>
            <div class="invalid-feedback">At least <?= PASSWORD_MIN_LENGTH ?> characters with at least one letter and one number.</div>
          </div>
          <div id="pwHelp" class="form-text">At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number. Do not use your username or student number.</div>
        </div>
        <div class="mb-3">
          <label for="confirm_password" class="form-label">Confirm new password</label>
          <input type="password" class="form-control" id="confirm_password" name="confirm_password" maxlength="72" autocomplete="new-password" required>
          <div class="invalid-feedback">The passwords must match.</div>
        </div>
        <button type="submit" class="btn btn-p w-100">Change password</button>
      </form>
      <div class="d-flex justify-content-between mt-3 small">
        <?php if (!$forced): ?><a href="<?= e(app_url(home_path_for($user))) ?>">Back to dashboard</a><?php else: ?><span></span><?php endif; ?>
        <form method="post" action="<?= e(app_url('logout.php')) ?>" class="m-0"><?= csrf_field() ?><button type="submit" class="btn btn-link btn-sm p-0">Sign out</button></form>
      </div>
<?php
auth_page_end(['js/auth-pages.js']);
