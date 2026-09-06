<?php
// moderate-review.php
// Powers the Approve/Reject buttons in admin.html's review moderation section.

declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('moderate-review failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
    exit;
});

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

$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$status = trim((string)($input['status'] ?? ''));

if (!$id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'A valid review id is required.']);
    exit;
}

if (!in_array($status, ['approved', 'rejected'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Status must be approved or rejected.']);
    exit;
}

$pdo = getDbConnection();

$find = $pdo->prepare('SELECT id FROM reviews WHERE id = :id');
$find->execute(['id' => $id]);
if (!$find->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Review not found.']);
    exit;
}

$update = $pdo->prepare('UPDATE reviews SET status = :status WHERE id = :id');
$update->execute(['status' => $status, 'id' => $id]);

echo json_encode(['success' => true, 'status' => $status]);
