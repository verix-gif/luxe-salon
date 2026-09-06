<?php
// get-reviews.php
// Powers admin.html's review moderation queue and the stats row.

declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('get-reviews failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not load reviews.']);
    exit;
});

$pdo = getDbConnection();
$stmt = $pdo->query(
    "SELECT id, client_name AS name, service, rating, review_text AS text,
            status, created_at AS date
     FROM reviews
     ORDER BY created_at DESC"
);
$reviews = $stmt->fetchAll();

foreach ($reviews as &$r) {
    $r['rating'] = (int)$r['rating'];
}
unset($r);

echo json_encode(['success' => true, 'reviews' => $reviews]);
