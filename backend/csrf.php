<?php
// csrf.php
// Minimal CSRF protection for state-changing admin requests. SameSite=Lax
// on the session cookie (see session-init.php) already blocks most
// cross-site POST attempts in modern browsers, but a real CSRF token is a
// second, independent layer that doesn't rely on browser cookie behavior
// working correctly — defense in depth, not redundant.
//
// Usage:
//   require_once __DIR__ . '/csrf.php';
//   $token = getCsrfToken();              // call after session_start()
//   requireValidCsrfToken($input['csrfToken'] ?? null);  // in POST endpoints

declare(strict_types=1);

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireValidCsrfToken(?string $submittedToken): void {
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    // hash_equals() specifically to avoid timing-attack-based token guessing,
    // same reasoning as the password_verify() timing protection in login.php.
    if (!$sessionToken || !$submittedToken || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid or missing security token. Please refresh the page and try again.']);
        exit;
    }
}
