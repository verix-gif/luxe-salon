<?php
// update-booking-status.php
// Powers the "Confirm / Cancel / Reschedule" actions in admin.html's
// booking action modal. Replaces the localStorage read-modify-write that
// currently backs those buttons.

declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('update-booking-status failed: ' . $e->getMessage());
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

$ref = trim((string)($input['ref'] ?? ''));
$action = trim((string)($input['action'] ?? '')); // 'confirm' | 'cancel' | 'reschedule'

if ($ref === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Booking reference is required.']);
    exit;
}

$pdo = getDbConnection();

$find = $pdo->prepare('SELECT * FROM bookings WHERE ref = :ref');
$find->execute(['ref' => $ref]);
$booking = $find->fetch();

if (!$booking) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Booking not found.']);
    exit;
}

if ($action === 'confirm') {
    $update = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE ref = :ref");
    $update->execute(['ref' => $ref]);
    echo json_encode(['success' => true, 'status' => 'confirmed']);
    exit;
}

if ($action === 'cancel') {
    $update = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE ref = :ref");
    $update->execute(['ref' => $ref]);
    echo json_encode(['success' => true, 'status' => 'cancelled']);
    exit;
}

if ($action === 'reschedule') {
    $newDate = trim((string)($input['date'] ?? ''));
    $newTime = trim((string)($input['time'] ?? ''));
    $newStylist = trim((string)($input['stylist'] ?? 'No Preference'));

    $dateObj = DateTime::createFromFormat('Y-m-d', $newDate);
    $timeObj = DateTime::createFromFormat('H:i', $newTime);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $newDate ||
        !$timeObj || $timeObj->format('H:i') !== $newTime) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid date or time.']);
        exit;
    }

    $validStylists = ['No Preference', 'Amara K.', 'Brian O.', 'Faith N.'];
    if (!in_array($newStylist, $validStylists, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Unrecognized stylist.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Same conflict logic as create-booking.php, adapted to exclude
        // this booking's own current slot from the check (otherwise a
        // reschedule to the exact same time it's already at would always
        // report a "conflict" against itself).
        if ($newStylist === 'No Preference') {
            $check = $pdo->prepare(
                "SELECT COUNT(DISTINCT stylist) AS taken_count
                 FROM bookings
                 WHERE booking_date = :date AND booking_time = :time
                   AND stylist != 'No Preference' AND status != 'cancelled'
                   AND ref != :ref"
            );
            $check->execute(['date' => $newDate, 'time' => $newTime, 'ref' => $ref]);
            if ((int)$check->fetch()['taken_count'] >= 3) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Every stylist is already booked at that new time.']);
                exit;
            }
        } else {
            $check = $pdo->prepare(
                "SELECT id FROM bookings
                 WHERE stylist = :stylist AND booking_date = :date AND booking_time = :time
                   AND status != 'cancelled' AND ref != :ref"
            );
            $check->execute(['stylist' => $newStylist, 'date' => $newDate, 'time' => $newTime, 'ref' => $ref]);
            if ($check->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'That stylist already has a booking at the new time.']);
                exit;
            }
        }

        // Rescheduling resets status to pending — it's effectively a new
        // slot that needs reconfirming, same behavior as the front-end
        // demo version had.
        $update = $pdo->prepare(
            "UPDATE bookings
             SET booking_date = :date, booking_time = :time, stylist = :stylist, status = 'pending'
             WHERE ref = :ref"
        );
        $update->execute(['date' => $newDate, 'time' => $newTime, 'stylist' => $newStylist, 'ref' => $ref]);

        $pdo->commit();
        echo json_encode(['success' => true, 'status' => 'pending']);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->errorInfo[1] === 1062) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'That slot was just taken by another booking. Please pick a different time.']);
            exit;
        }
        throw $e;
    }
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'error' => 'Unknown action. Use confirm, cancel, or reschedule.']);
