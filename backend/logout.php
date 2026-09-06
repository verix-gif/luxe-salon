<?php
// logout.php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/session-init.php';
session_start();
$_SESSION = [];
session_destroy();

// Also clear the session cookie itself, not just the server-side data —
// otherwise the browser keeps sending a cookie referencing a session
// that no longer exists, which is harmless but untidy.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}

echo json_encode(['success' => true]);
