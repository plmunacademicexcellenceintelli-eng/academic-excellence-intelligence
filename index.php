<?php
/** Entry point: signed-in users go to their own portal, everyone else to the sign-in page. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

send_security_headers();
$user = current_user($reason);
if ($user === null) {
    redirect('login.php' . ($reason === 'expired' ? '?expired=1' : ''));
}
redirect((int)$user['must_change_password'] === 1 ? 'change-password.php' : home_path_for($user));
