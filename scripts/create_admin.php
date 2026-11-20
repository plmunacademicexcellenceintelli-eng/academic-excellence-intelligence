<?php
/**
 * Create the FIRST Administrator account (command line only).
 *
 *   cd C:\xampp\htdocs\plmun-ui
 *   C:\xampp\php\php.exe scripts\create_admin.php
 *
 * It refuses to run when an Admin already exists. To add another Admin later, run:
 *   C:\xampp\php\php.exe scripts\create_admin.php --additional
 * The password is hashed with password_hash() before it is stored. No credentials are kept in source code.
 */
declare(strict_types=1);
require __DIR__ . '/cli.php';

$additional = in_array('--additional', $argv, true);
$existing   = count_admins();
if ($existing > 0 && !$additional) {
    fail("An Admin account already exists ($existing). Nothing was changed.\n"
       . "       To add another Admin on purpose, run: php scripts/create_admin.php --additional");
}

echo PHP_EOL . '=== AEI: create ' . ($existing ? 'an additional' : 'the first') . ' Administrator account ===' . PHP_EOL;
$username = ask('Username (4-30 chars, starts with a letter)');
$fullName = ask('Full name');
$email    = ask('Email (optional, press Enter to skip)', false);
$password = ask_new_password($username);

try {
    $id = create_admin_account($username, $fullName, $email === '' ? null : $email, $password);
} catch (AccountError $ex) {
    fail($ex->getMessage());
}
echo PHP_EOL . "Admin account created (user ID $id). Sign in at /plmun-ui/login.php with username \"$username\"." . PHP_EOL;
