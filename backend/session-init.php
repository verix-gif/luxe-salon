<?php
// session-init.php
// Include this BEFORE calling session_start() in any file. Centralizes the
// session cookie security flags so they're guaranteed consistent everywhere
// (login.php, logout.php, session-status.php, auth-guard.php) instead of
// each file configuring — or forgetting to configure — this separately.
//
// Found via security audit: PHP's default session.cookie_httponly is OFF
// on this setup, meaning a successful XSS elsewhere in the app could read
// the session cookie directly via JavaScript (document.cookie) and steal
// the admin's session outright, not just perform limited damage. This is
// the fix for that specific risk — it does not fix the XSS itself, which
// needs separate output-escaping fixes wherever user data is rendered.

declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,          // session cookie, expires when browser closes
    'path'     => '/',
    'domain'   => '',         // current domain only
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    // ^ only send the cookie over HTTPS once real hosting has SSL set up.
    //   On plain HTTP (like local XAMPP testing), this correctly stays
    //   false — forcing 'secure' => true here would silently break
    //   sessions entirely on a non-HTTPS local setup.
    'httponly' => true,       // JavaScript can no longer read this cookie at all
    'samesite' => 'Lax',      // blocks the cookie being sent on most cross-site
                               // requests, a real (if partial) CSRF mitigation
]);
