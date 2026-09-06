<?php
// login.php
// Replaces the hardcoded JS "if (user === 'admin' && pass === '...')" check
// in admin.html with a real check against hashed passwords in the database,
// and a real PHP session instead of a sessionStorage flag anyone could set
// by typing one line in devtools.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session-init.php';
require_once __DIR__ . '/csrf.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}

requireValidCsrfToken($input['csrfToken'] ?? null);

$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
    exit;
}

try {
    $pdo = getDbConnection();

    // Rate limiting: block after 5 failed attempts for this username within
    // the last 15 minutes. Checked before touching the password at all.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $maxAttempts = 5;
    $windowMinutes = 15;

    $attemptCheck = $pdo->prepare(
        "SELECT COUNT(*) AS attempts FROM login_attempts
         WHERE username = :username
           AND attempted_at > (NOW() - INTERVAL :window MINUTE)"
    );
    $attemptCheck->execute(['username' => $username, 'window' => $windowMinutes]);
    $recentAttempts = (int)$attemptCheck->fetch()['attempts'];

    if ($recentAttempts >= $maxAttempts) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => "Too many failed login attempts. Please wait $windowMinutes minutes and try again.",
        ]);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM admin_users WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    // Always run password_verify even if the user wasn't found, using a
    // fixed dummy hash. This keeps the response time the same either way,
    // so an attacker can't tell "wrong username" from "wrong password"
    // by timing the request (a real, if minor, security consideration).
    $hashToCheck = $user['password_hash'] ?? '$2y$10$invalidinvalidinvaliduinvalidinvalidinvalidinvalidinva';
    $isValid = password_verify($password, $hashToCheck);

    if (!$user || !$isValid) {
        // Log the failed attempt so it counts toward the rate limit above.
        $logAttempt = $pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address) VALUES (:username, :ip)'
        );
        $logAttempt->execute(['username' => $username, 'ip' => $clientIp]);

        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Incorrect username or password.']);
        exit;
    }

    // Regenerate the session ID on login — prevents session fixation attacks
    // (where an attacker tricks a victim into using a known session ID).
    // Session was already started earlier (needed the CSRF token check
    // before this point), so just regenerate the existing one.
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_username'] = $username;
    $_SESSION['login_time'] = time();

    $update = $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
    $update->execute(['id' => $user['id']]);

    echo json_encode(['success' => true, 'username' => $username]);

} catch (PDOException $e) {
    error_log('Login failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
