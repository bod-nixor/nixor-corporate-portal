<?php
function set_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header("X-Frame-Options: DENY");

    $csp = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";
    header("Content-Security-Policy: {$csp}");

    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
