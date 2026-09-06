# Luxe Salon — XAMPP Setup Guide

This package contains the full front end (salon.html, booking.html,
review.html, admin.html, 404.html) plus the real PHP/MySQL backend
(the `backend/` folder).

## Security update (this version)

A security audit found and fixed several real issues since the last
version of this package:

- **Stored XSS** — booking/review data (name, phone, email, notes,
  review text) was being inserted into admin.html via `innerHTML` with
  no escaping. A malicious booking submission could have run arbitrary
  JavaScript in the admin's browser. Fixed with proper HTML escaping
  everywhere user data is displayed, plus removing an inline `onclick`
  pattern that allowed a worse variant (direct script injection via a
  phone number breaking out of a JS string).
- **Session cookie hardening** — `HttpOnly` and `SameSite=Lax` are now
  set on the admin session cookie (previously neither was set), closing
  off the most severe way the XSS above could have been exploited
  (stealing the session outright).
- **Missing server-side validation** — `service` and `phone` fields on
  bookings had no format/allowlist validation server-side (only checked
  "not empty"), so someone bypassing the HTML form could submit almost
  anything into those fields. Both now validate properly.
- **No login rate limiting** — `login.php` now blocks further attempts
  after 5 failed logins for the same username within 15 minutes.
- **No CSRF protection** — every state-changing admin request (login,
  confirm/cancel/reschedule a booking, approve/reject a review) now
  requires a CSRF token issued by the server and tied to the session.
  A request without a valid token is rejected with 403.
- **No booking spam protection** — `create-booking.php` required no
  login at all and had zero rate limiting, so it could be scripted to
  flood the system with fake bookings. Now limited to 5 bookings per
  IP address per hour.
- **Error message information disclosure** — one endpoint was
  accidentally echoing raw internal exception messages to the client
  instead of logging them server-side only. Fixed.

## Step-by-step setup

### 1. Install XAMPP (if you haven't already)
Download from https://www.apachefriends.org and install it.

### 2. Copy this folder into htdocs
Move the entire `luxe-salon` folder (this one) into your XAMPP `htdocs`
directory. On most systems that's:

- **Windows**: `C:\xampp\htdocs\luxe-salon`
- **Mac**: `/Applications/XAMPP/htdocs/luxe-salon`
- **Linux**: `/opt/lampp/htdocs/luxe-salon`

So you should end up with, e.g., `C:\xampp\htdocs\luxe-salon\admin.html`
and `C:\xampp\htdocs\luxe-salon\backend\login.php` sitting next to it.

### 3. Start Apache and MySQL
Open the **XAMPP Control Panel** and click **Start** next to both
**Apache** and **MySQL**. Both need to show green/running.

### 4. Create the database
Open **phpMyAdmin** in your browser: http://localhost/phpmyadmin

- Click **Import** in the top menu
- Click **Choose File** and select `backend/schema.sql` from this package
- Click **Go** at the bottom

This creates the `luxe_salon` database with all required tables
(bookings, reviews, admin_users, login_attempts).

**`config.php` is already set up for XAMPP's defaults** (root user, no
password) — no changes needed unless you've customized your MySQL setup.

### 5. Create your admin login
XAMPP includes a Shell button in the control panel (or just use your
system's terminal/command prompt). Navigate into the backend folder and run:

```
cd C:\xampp\htdocs\luxe-salon\backend
php create_admin.php yourname yourpassword
```

(Replace the path with wherever you actually placed the folder, and use
a real username/password — at least 8 characters.)

**After this succeeds, delete `create_admin.php`** — leaving it in place
would let anyone create their own admin account by visiting/running it.

### 6. Open the site
Visit **http://localhost/luxe-salon/salon.html** in your browser — not
by double-clicking the file. It must be loaded through
`http://localhost/...` so Apache actually executes the PHP files.

For the admin dashboard: **http://localhost/luxe-salon/admin.html**,
then log in with the username/password you created in step 5.

## Troubleshooting

**"Expected JSON but got text/html" errors** — this means you opened the
HTML file directly (`file://...`) instead of through
`http://localhost/luxe-salon/...`. PHP only executes when Apache serves
the file; opening it directly just shows/fails to run the raw file.

**"Could not connect to database" or similar** — make sure MySQL is
actually running (green in the XAMPP Control Panel) and that you
completed step 4 (importing schema.sql).

**Login fails with correct credentials** — double check you ran
`create_admin.php` successfully (step 5) and that you're using the exact
username/password you created, not example placeholder text. Also check
you haven't triggered the new rate limit (5 failed attempts locks
further tries for 15 minutes).

**"Invalid or missing security token" error** — this is the new CSRF
protection. It means the page loaded before the server connection was
ready, or the browser tab has been open a very long time. Refresh the
page and try again.

## What's still front-end only (not yet connected to this backend)

`booking.html` and `review.html` currently still save to the browser's
own `localStorage`, not to the real database yet — only `admin.html` is
fully wired to the PHP backend at this point. Bookings/reviews submitted
through those two pages won't show up in the admin dashboard until they're
connected to `create-booking.php` and a future `create-review.php`.
