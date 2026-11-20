<?php
/** CSRF protection: one random token per session, required on every state-changing POST. */
declare(strict_types=1);

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** New token (called after login so a token seen before login cannot be reused). */
function csrf_rotate(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Hidden form field. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Token sent by the browser: form field, or X-CSRF-Token header for fetch() requests. */
function csrf_from_request(): ?string
{
    $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    return is_string($t) ? $t : null;
}

function csrf_valid(?string $token): bool
{
    session_boot();
    return is_string($token) && $token !== ''
        && !empty($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
