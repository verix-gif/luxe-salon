<?php
// create-booking.php
// Step 1 of the real backend: the single most central endpoint. Everything
// else (SMS/email confirmation, admin dashboard reading real data, reviews
// tied to real bookings) depends on this working correctly first.
//
// This replaces the front-end's fake `localStorage` booking save with a
// real database insert, and — critically — real double-booking prevention
// that works across every customer and every device, not just one browser.

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// --- Only accept POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// --- Read and decode JSON body ---
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}

// --- Extract and sanitize fields ---
// Never trust client-side validation alone — every check the front end does
// gets repeated here, because the front end can be bypassed entirely.
$name    = trim((string)($input['name']    ?? ''));
$phone   = trim((string)($input['phone']   ?? ''));
$email   = trim((string)($input['email']   ?? ''));
$service = trim((string)($input['service'] ?? ''));
$stylist = trim((string)($input['stylist'] ?? 'No Preference'));
$date    = trim((string)($input['date']    ?? ''));
$time    = trim((string)($input['time']    ?? ''));
$notes   = trim((string)($input['notes']   ?? ''));
$depositMethod = trim((string)($input['depositMethod'] ?? 'mpesa'));

// --- Required field validation ---
$errors = [];
if ($name === '')    $errors[] = 'Name is required.';
if ($phone === '')   $errors[] = 'Phone number is required.';

// Found during security audit: no format check meant a phone value
// containing a single quote could break out of a JS string literal in
// admin.html's onclick attribute — direct script injection, not just
// HTML injection. Restricting to digits, spaces, +, and - closes this
// at the source (the front-end display fix below is the second,
// independent layer of defense).
if ($phone !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
    $errors[] = 'Phone number contains invalid characters.';
}
if ($email === '')   $errors[] = 'Email is required.';
if ($service === '') $errors[] = 'Service is required.';

// Found during security audit: unlike $stylist below, this field had no
// allowlist check — only a non-empty check. Someone bypassing the HTML
// form entirely and POSTing directly to this endpoint could set it to
// anything at all, including HTML/script content that later gets stored
// and rendered in admin.html. Closing that here, not just relying on
// output escaping on the display side (defense in depth — both layers
// should hold independently).
$validServices = [
    'Hair Cutting', 'Hair Coloring', 'Hair Spa & Treatment', 'Bridal Services',
    'Nail Services', 'Makeup & Styling', 'Bridal Makeup', 'Party Makeup',
    'Basic Package', 'Premium Package', 'VIP Package',
];
if ($service !== '' && !in_array($service, $validServices, true)) {
    $errors[] = 'Unrecognized service selection.';
}
if ($date === '')    $errors[] = 'Date is required.';
if ($time === '')    $errors[] = 'Time is required.';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
}

$validStylists = ['No Preference', 'Amara K.', 'Brian O.', 'Faith N.'];
if (!in_array($stylist, $validStylists, true)) {
    $errors[] = 'Unrecognized stylist selection.';
}

$validDepositMethods = ['mpesa', 'airtel', 'card'];
if (!in_array($depositMethod, $validDepositMethods, true)) {
    $depositMethod = 'mpesa';
}

// --- Date/time format validation ---
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
    $errors[] = 'Invalid date format.';
}
$timeObj = DateTime::createFromFormat('H:i', $time);
if (!$timeObj || $timeObj->format('H:i') !== $time) {
    $errors[] = 'Invalid time format.';
}

// --- Reject past dates ---
if ($dateObj && $dateObj < new DateTime('today')) {
    $errors[] = 'Cannot book a date in the past.';
}

// --- Business hours validation (mirrors the front-end logic, re-checked server-side) ---
if ($dateObj && $timeObj) {
    $dayOfWeek = (int)$dateObj->format('w'); // 0=Sun, 6=Sat
    $isWeekend = ($dayOfWeek === 0 || $dayOfWeek === 6);
    $minTime = $isWeekend ? '10:00' : '09:00';
    $maxTime = $isWeekend ? '18:00' : '20:00';

    if ($time < $minTime || $time >= $maxTime) {
        $hoursLabel = $isWeekend ? '10AM - 6PM' : '9AM - 8PM';
        $errors[] = "That time is outside business hours ($hoursLabel) for this day.";
    }
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// --- The actual double-booking check ---
try {
    $pdo = getDbConnection();

    // Rate limiting: this endpoint requires no login at all, so without
    // this, someone could script thousands of fake bookings with no
    // friction whatsoever. 5 bookings per IP per hour.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $maxBookingsPerHour = 5;

    $rateCheck = $pdo->prepare(
        "SELECT COUNT(*) AS recent_count FROM bookings
         WHERE submitter_ip = :ip
           AND created_at > (NOW() - INTERVAL 1 HOUR)"
    );
    $rateCheck->execute(['ip' => $clientIp]);
    $recentCount = (int)$rateCheck->fetch()['recent_count'];

    if ($recentCount >= $maxBookingsPerHour) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'Too many booking requests from this connection recently. Please try again later, or contact us directly.',
        ]);
        exit;
    }

    $pdo->beginTransaction();

    if ($stylist === 'No Preference') {
        // Application-level check: only reject if EVERY named stylist is
        // already booked at this exact date+time. The database's unique
        // constraint can't express this rule on its own (see schema.sql
        // comments), so it has to happen here.
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT stylist) AS taken_count
             FROM bookings
             WHERE booking_date = :date
               AND booking_time = :time
               AND stylist != 'No Preference'
               AND status != 'cancelled'"
        );
        $stmt->execute(['date' => $date, 'time' => $time]);
        $takenCount = (int)$stmt->fetch()['taken_count'];

        if ($takenCount >= 3) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'Sorry, every stylist is already booked at that time. Please choose another slot.'
            ]);
            exit;
        }
    }
    // For named stylists, we don't need a manual check here — the UNIQUE
    // constraint on (stylist_for_lock, booking_date, booking_time) will
    // reject the INSERT below automatically if that stylist is already
    // taken. We catch that specific database error further down.

    // --- Generate a unique reference number ---
    // Retries a handful of times on the rare chance of a collision, rather
    // than trusting randomness alone — the ref column has a UNIQUE
    // constraint as the real backstop.
    $ref = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'LX-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT id FROM bookings WHERE ref = :ref');
        $check->execute(['ref' => $candidate]);
        if (!$check->fetch()) {
            $ref = $candidate;
            break;
        }
    }
    if ($ref === null) {
        throw new RuntimeException('Could not generate a unique reference number.');
    }

    // --- Insert the booking ---
    $insert = $pdo->prepare(
        "INSERT INTO bookings
            (ref, client_name, client_phone, client_email, service, stylist,
             booking_date, booking_time, notes, deposit_amount, deposit_method, submitter_ip)
         VALUES
            (:ref, :name, :phone, :email, :service, :stylist,
             :date, :time, :notes, 500.00, :depositMethod, :ip)"
    );
    $insert->execute([
        'ref' => $ref,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'service' => $service,
        'stylist' => $stylist,
        'date' => $date,
        'time' => $time,
        'notes' => $notes !== '' ? $notes : null,
        'depositMethod' => $depositMethod,
        'ip' => $clientIp,
    ]);

    $pdo->commit();

    // NOTE: this is the point where a real system would trigger:
    //   - SMS/email confirmation to the client (Africa's Talking, PHPMailer)
    //   - The actual M-Pesa/Airtel STK push or card charge via Daraja/Airtel
    //     OpenAPI/a PCI-compliant processor — none of that happens here yet.
    // Both were flagged as "Pending Setup" in the front-end UI already.

    echo json_encode([
        'success' => true,
        'ref' => $ref,
        'message' => 'Booking confirmed.',
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // MySQL error code 1062 = duplicate entry, i.e. the UNIQUE constraint
    // on (stylist_for_lock, booking_date, booking_time) was violated —
    // this is the real double-booking prevention actually firing.
    if ($e->errorInfo[1] === 1062 && str_contains($e->getMessage(), 'unique_stylist_slot')) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'That stylist already has a booking at this exact time. Please pick another slot.',
        ]);
        exit;
    }

    // Don't leak raw database errors to the client — log them instead.
    error_log('Booking insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
