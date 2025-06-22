<?php
// config/security_headers.php - Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// HTTPS enforcement (uncomment when deploying with SSL)
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
?>
