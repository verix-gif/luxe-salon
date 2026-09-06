<?php
// session-status.php
// admin.html is a static page — it can't inherently know whether the
// browser holds a valid PHP session. On every load, it asks this endpoint.

declare(strict_types=1);
header('Content-Type: application/json');

// Safety net: if anything unexpected throws, this guarantees the response
// is still valid JSON describing the failure — never a raw HTML error
// page, which is what breaks the front end's res.json() call.
set_exception_handler(function (Throwable $e) {
    error_log('session-status failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'loggedIn' => false,
        'username' => null,
    ]);
    exit;
});

require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/csrf.php';
session_start();

const SESSION_LIFETIME_SECONDS = 2 * 60 * 60; // 2 hours

$loggedIn = isset($_SESSION['admin_id']);

if ($loggedIn) {
    $age = time() - (int)($_SESSION['login_time'] ?? 0);
    if ($age > SESSION_LIFETIME_SECONDS) {
        // Session expired — clear it out rather than trusting a stale login.
        $_SESSION = [];
        session_destroy();
        $loggedIn = false;
    }
}

echo json_encode([
    'loggedIn' => $loggedIn,
    'username' => $loggedIn ? ($_SESSION['admin_username'] ?? null) : null,
    // Issued whether or not the user is logged in — the login form itself
    // needs a token to submit, before any admin session exists yet.
    'csrfToken' => getCsrfToken(),
]);
