<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.php');

$GLOBALS['config'] = is_file(CONFIG_FILE) ? require CONFIG_FILE : null;

require APP_DIR . '/helpers.php';

// Not installed yet → hand off to the installer.
if (empty($GLOBALS['config']['installed'])) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: ' . base_path() . '/install.php');
        exit;
    }
    return;
}

date_default_timezone_set('UTC');

// Production posture: never leak errors to visitors; log everything to a file
// the admin can read at /errors (shared hosts rarely expose the PHP error log).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DATA_DIR . '/error.log');
error_reporting(E_ALL);
// ponytail: one-generation rotation so a runaway error can't eat the host's disk
if (is_file(DATA_DIR . '/error.log') && (int)@filesize(DATA_DIR . '/error.log') > 1048576) {
    @rename(DATA_DIR . '/error.log', DATA_DIR . '/error.log.1');
}
set_exception_handler(function (Throwable $e): void {
    error_log('[timepool] ' . $e);
    keep_input(); // a POST that blew up re-renders with the user's entries intact
    fail_page();
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[timepool] fatal: ' . $err['message']);
        keep_input();
        fail_page();
    }
});

require APP_DIR . '/db.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/poll.php';
require APP_DIR . '/booking.php';
require APP_DIR . '/booking_calendar.php';
require APP_DIR . '/mailer.php';
require APP_DIR . '/ics.php';
require APP_DIR . '/notify.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('tp_session');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') == 443),
    ]);
    session_start();
}

// Security headers (sent here so they apply even where mod_headers is unavailable).
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

db(); // open connection + ensure schema exists
