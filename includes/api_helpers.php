<?php
/** Helpers shared by the JSON API endpoints in api/. */
declare(strict_types=1);

/** JSON request body as an array (POST requests from js/app.js send JSON). */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 2 * 1024 * 1024) {
        json_response(['error' => 'Request is too large.'], 413);
    }
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) {
        json_response(['error' => 'Invalid request body (JSON expected).'], 400);
    }
    return $data;
}

/** Positive integer from request data, or null. */
function int_param(mixed $v): ?int
{
    return (is_int($v) && $v > 0) || (is_string($v) && preg_match('/^[1-9]\d{0,9}$/', $v)) ? (int)$v : null;
}

/** Validation error -> HTTP 422 with one message per field. */
function json_validation_error(M1ValidationError $ex): never
{
    json_response(['error' => $ex->getMessage(), 'errors' => $ex->errors], 422);
}
