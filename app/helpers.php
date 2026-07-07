<?php
declare(strict_types=1);

// Polyfills so the app also runs on the older PHP some budget hosts still ship.
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool { return $n === '' || substr($h, -strlen($n)) === $n; }
}

/** HTML-escape for output. */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/** Minimal, dependency-free 500 page (used by the global error handler). */
function fail_page(): void {
    if (!headers_sent()) http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:14vh auto;text-align:center;color:#1a1a2e">'
        . '<h1 style="font-size:2rem">Something went wrong</h1>'
        . '<p style="color:#8a8aa3">An unexpected error occurred. Please try again in a moment.</p>'
        . '<p style="color:#8a8aa3;font-size:.85rem">Run this site? Sign in and check the error log linked from Settings.</p></div>';
    exit;
}

/** Last $bytes of a file, trimmed to whole lines (for surfacing logs in the UI). */
function log_tail(string $path, int $bytes = 65536): string {
    if (!is_file($path) || !($size = (int)filesize($path))) return '';
    $fh = fopen($path, 'rb');
    if (!$fh) return '';
    if ($size > $bytes) fseek($fh, -$bytes, SEEK_END);
    $s = (string)stream_get_contents($fh);
    fclose($fh);
    if ($size > $bytes && ($nl = strpos($s, "\n")) !== false) $s = substr($s, $nl + 1);
    return $s;
}

/** Base path the app is installed under ('' for a subdomain root, '/meet' for a subfolder). */
function base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $base = str_replace('\\', '/', dirname($script));
    return ($base === '/' || $base === '.') ? '' : rtrim($base, '/');
}

/** Whether clean URLs (mod_rewrite) are available; set by the installer probe. */
function pretty_urls(): bool { return !empty($GLOBALS['config']['pretty']); }

/** Build an in-app URL that works at a subdomain root, in a subfolder, with or without rewrite. */
function url(string $path = '/'): string {
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    $query = '';
    if (($q = strpos($path, '?')) !== false) { $query = substr($path, $q + 1); $path = substr($path, 0, $q); }
    if (pretty_urls()) return base_path() . $path . ($query !== '' ? '?' . $query : '');
    // No mod_rewrite: route via ?r=, but keep real query params alongside it so token/slot survive.
    return base_path() . '/index.php?r=' . rawurlencode($path) . ($query !== '' ? '&' . $query : '');
}

/** URL to a static asset (always a real file, never routed). ?v=<mtime> busts long-lived caches on upgrade. */
function asset_url(string $path): string {
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    $file = ROOT_DIR . $path;
    $v = is_file($file) ? (string)filemtime($file) : '';
    return base_path() . $path . ($v !== '' ? '?v=' . $v : '');
}

/** The route the request is asking for, normalised to a leading-slash path. */
function current_route(): string {
    if (isset($_GET['r'])) return '/' . trim((string)$_GET['r'], '/');
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri  = rawurldecode($uri);
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
    $uri = preg_replace('#^/index\.php#', '', $uri) ?? $uri;
    $uri = '/' . trim($uri, '/');
    return $uri;
}

function redirect(string $path): void {
    $loc = str_starts_with($path, 'http') ? $path : url($path);
    header('Location: ' . $loc);
    exit;
}

/** Read a request param from POST then GET. */
function param(string $key, $default = null) { return $_POST[$key] ?? $_GET[$key] ?? $default; }

function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type]; }
function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Your session expired or the form token was invalid. Please go back and try again.');
    }
}

/** URL-safe random token. */
function random_token(int $bytes = 16): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Curated IANA timezone list for pickers (JS augments with the full set when available). */
function common_timezones(): array {
    return [
        'UTC',
        'Pacific/Honolulu', 'America/Anchorage', 'America/Los_Angeles', 'America/Denver',
        'America/Chicago', 'America/New_York', 'America/Toronto', 'America/Sao_Paulo',
        'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid', 'Europe/Athens',
        'Africa/Lagos', 'Africa/Johannesburg', 'Asia/Jerusalem', 'Asia/Dubai', 'Asia/Kolkata',
        'Asia/Bangkok', 'Asia/Shanghai', 'Asia/Tokyo', 'Australia/Sydney', 'Pacific/Auckland',
    ];
}
