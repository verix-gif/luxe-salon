<?php
// get-bookings.php
// Powers admin.html's calendar, client CRM, and analytics — all of which
// currently read from localStorage. This is the real data source that
// replaces that, protected by auth-guard.php so only a logged-in admin
// can pull the full client list.

declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php'; // exits 401 if not logged in
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('get-bookings failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Could not load bookings.']);
    exit;
});

$pdo = getDbConnection();
$stmt = $pdo->query(
    "SELECT ref, client_name AS name, client_phone AS phone, client_email AS email,
            service, stylist,
            DATE_FORMAT(booking_date, '%Y-%m-%d') AS date,
            TIME_FORMAT(booking_time, '%H:%i') AS time,
            notes, deposit_amount AS depositAmount, deposit_method AS depositMethod,
            status, created_at AS submittedAt
     FROM bookings
     ORDER BY booking_date ASC, booking_time ASC"
);
$bookings = $stmt->fetchAll();

// Cast numeric fields explicitly — PDO can return decimals as strings
// depending on driver config, and the front end's charts do arithmetic
// on depositAmount, so this avoids "500.00" + "500.00" = "500.00500.00"
// style bugs from silent string concatenation instead of addition.
foreach ($bookings as &$b) {
    $b['depositAmount'] = (float)$b['depositAmount'];
}
unset($b);

echo json_encode(['success' => true, 'bookings' => $bookings]);
