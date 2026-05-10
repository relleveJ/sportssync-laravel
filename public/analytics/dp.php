<?php
// Compatibility shim for moved DB connection.
// The real implementation now lives in app/Legacy/db.php.
// This shim prevents direct web access while allowing legacy includes
// that reference public/db.php to keep working server-side.

// Deny direct HTTP access to this file (only allow inclusion).
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

require_once __DIR__ . '/../app/Legacy/db.php';