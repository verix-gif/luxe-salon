<?php
// auth-guard.php
// Include this at the top of any endpoint that should only be reachable by
// a logged-in admin (future: get-bookings.php, moderate-review.php, etc.).
// Usage:  require_once __DIR__ . '/auth-guard.php';
//
// This is the actual security boundary — admin.html hiding a <div> with
// CSS was never real protection, since anyone could just call these PHP
// endpoints directly with curl. This is what makes that protection real.

declare(strict_types=1);

require_once __DIR__ . '/session-init.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}
