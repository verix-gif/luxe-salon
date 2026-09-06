-- Luxe Salon — Booking System Database Schema
-- Step 1: minimal viable schema. Just enough to make booking + double-booking
-- prevention real. Reviews, clients, and admin auth are NOT in this file yet —
-- add them as separate migrations once this piece is proven out.

CREATE DATABASE IF NOT EXISTS luxe_salon
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE luxe_salon;

CREATE TABLE bookings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ref             VARCHAR(20) NOT NULL UNIQUE,

    -- Client details (no accounts — matches the guest-booking decision made earlier)
    client_name     VARCHAR(100) NOT NULL,
    client_phone    VARCHAR(20)  NOT NULL,
    client_email    VARCHAR(150) NOT NULL,

    -- What was booked
    service         VARCHAR(100) NOT NULL,
    stylist         VARCHAR(100) NOT NULL DEFAULT 'No Preference',

    -- When
    booking_date    DATE NOT NULL,
    booking_time    TIME NOT NULL,

    notes           TEXT NULL,

    -- Deposit (matches the front-end deposit step already built)
    deposit_amount  DECIMAL(10,2) NOT NULL DEFAULT 500.00,
    deposit_method  VARCHAR(20)   NOT NULL DEFAULT 'mpesa', -- mpesa | airtel | card
    deposit_status  VARCHAR(20)   NOT NULL DEFAULT 'pending', -- pending | paid | failed

    -- Booking lifecycle
    status          VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | confirmed | cancelled

    -- Added for rate limiting (create-booking.php) and abuse investigation.
    submitter_ip    VARCHAR(45) NOT NULL DEFAULT '',

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- 'No Preference' bookings deliberately do NOT count toward the unique
    -- slot constraint below — up to 3 different clients can all pick "No
    -- Preference" for the same slot (one will end up with each named
    -- stylist). This generated column is NULL for 'No Preference' rows;
    -- MySQL/InnoDB treats multiple NULLs in a unique index as distinct,
    -- so those rows never collide with each other. Named-stylist rows
    -- still get a real value here and ARE protected from double-booking.
    stylist_for_lock VARCHAR(100) GENERATED ALWAYS AS (
        CASE WHEN stylist = 'No Preference' THEN NULL ELSE stylist END
    ) STORED,

    -- The actual double-booking prevention: enforced by the database
    -- itself, not just application logic.
    UNIQUE KEY unique_stylist_slot (stylist_for_lock, booking_date, booking_time)
);

-- Helpful index for the availability-check queries the front end already needs
CREATE INDEX idx_date_time ON bookings (booking_date, booking_time);
CREATE INDEX idx_phone ON bookings (client_phone);
CREATE INDEX idx_submitter_ip ON bookings (submitter_ip, created_at);


-- Step 2: real admin authentication.
-- Replaces the hardcoded JS username/password in admin.html with a real,
-- hashed-password login checked server-side.
CREATE TABLE admin_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   TIMESTAMP NULL DEFAULT NULL
);

-- No default admin user is seeded here on purpose — a real password should
-- never sit in a schema file that might end up in version control. Use
-- create_admin.php (run once, then delete it) to create the first account.


-- Step 3: reviews. Same guest-submission pattern as bookings — no login
-- required, matches the front-end review.html form already built.
CREATE TABLE reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_name     VARCHAR(100) NOT NULL,
    service         VARCHAR(100) NOT NULL,
    rating          TINYINT UNSIGNED NOT NULL,
    review_text     TEXT NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | approved | rejected
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_rating_range CHECK (rating BETWEEN 1 AND 5)
);

CREATE INDEX idx_review_status ON reviews (status);


-- Step 4: basic login rate limiting (found missing during security audit).
-- Without this, login.php has no defense against a brute-force password
-- guessing attack against the single admin account.
CREATE TABLE login_attempts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50) NOT NULL,
    ip_address      VARCHAR(45) NOT NULL, -- long enough for IPv6
    attempted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_login_attempts_lookup ON login_attempts (username, attempted_at);
