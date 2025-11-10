// src/lib/security-headers/index.js

/**
 * Create the set of security headers shared between middleware and Next config.
 */
export function createSecureHeaders() {
  const csp = [
    "default-src 'self'",
    "img-src 'self' data:",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "connect-src 'self' https://accounts.google.com https://www.googleapis.com",
  ].join("; ");

  return [
    { key: "Content-Security-Policy", value: csp },
    { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
    { key: "X-Frame-Options", value: "DENY" },
    { key: "X-Content-Type-Options", value: "nosniff" },
    { key: "Permissions-Policy", value: "geolocation=(), microphone=()" },
  ];
}