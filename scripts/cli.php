<?php
/** Shared helpers for the command-line scripts in this folder. */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_cli();

function ask(string $label, bool $required = true): string
{
    while (true) {
        echo $label . ': ';
        $line = fgets(STDIN);
        if ($line === false) {
            fwrite(STDERR, PHP_EOL . 'Input ended. Nothing was saved.' . PHP_EOL);
            exit(1);
        }
        $v = trim($line);
        if ($v !== '' || !$required) {
            return $v;
        }
        echo "  This field is required." . PHP_EOL;
    }
}

/** Ask for a password without showing it (Linux/macOS terminals). On Windows the typing is visible. */
function ask_password(string $label): string
{
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('stream_isatty') && stream_isatty(STDIN);
    echo $label . ': ';
    if ($hide) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hide) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    if ($line === false) {
        fwrite(STDERR, PHP_EOL . 'Input ended. Nothing was saved.' . PHP_EOL);
        exit(1);
    }
    return rtrim($line, "\r\n");
}

function ask_new_password(string $username): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        echo "Note: on Windows the password is visible while you type. Make sure nobody is watching your screen." . PHP_EOL;
    }
    while (true) {
        $p1 = ask_password('Password (min ' . PASSWORD_MIN_LENGTH . ' chars, letters + numbers)');
        if ($problem = password_problem($p1, $username)) {
            echo "  $problem" . PHP_EOL;
            continue;
        }
        $p2 = ask_password('Repeat password');
        if (!hash_equals($p1, $p2)) {
            echo "  The passwords do not match. Try again." . PHP_EOL;
            continue;
        }
        return $p1;
    }
}

function fail(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}
