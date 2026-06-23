<?php
declare(strict_types=1);
/**
 * Meeting Poll — single-file installer / setup wizard.
 * Upload alongside the app (or upload alone with RELEASE_URL set to fetch the package),
 * then open this file in a browser. Creates the SQLite database, the first admin, and config.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

define('ROOT_DIR', __DIR__);
define('APP_DIR', ROOT_DIR . '/app');
define('DATA_DIR', ROOT_DIR . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.php');
const RELEASE_URL = ''; // optional .zip of the full app for single-file installs

require_once APP_DIR . '/helpers.php'; // e(), base_path() — safe, no DB

$existing = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : null;
if (is_array($existing) && !empty($existing['installed'])) {
    render('Already installed', '<div class="card auth-card center"><h1>Already installed ✓</h1>
        <p class="muted">Meeting Poll is set up on this server. For safety, delete <code>install.php</code>.</p>
        <p><a class="btn" href="' . e(app_root_url()) . '">Open Meeting Poll</a></p></div>');
    exit;
}

function app_root_url(): string {
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (base_path() ?: '') . '/';
}

function requirements(): array {
    $dataWritable = is_dir(DATA_DIR) ? is_writable(DATA_DIR) : is_writable(ROOT_DIR);
    return [
        ['PHP 7.4 or newer', version_compare(PHP_VERSION, '7.4.0', '>='), 'You have ' . PHP_VERSION . '. Ask your host to enable PHP 7.4+ (8.1+ recommended).'],
        ['SQLite support (pdo_sqlite)', extension_loaded('pdo_sqlite'), 'Enable the pdo_sqlite PHP extension (most hosts have it).'],
        ['Writable folder', $dataWritable, 'Make the install folder writable (chmod 755) so the database can be created.'],
        ['Application files present', is_dir(APP_DIR), 'Upload the full Meeting Poll package, not just install.php.'],
    ];
}

/** Single-file install: download + unpack the app package when only install.php was uploaded. */
function fetch_app(): void {
    if (RELEASE_URL === '' || !class_exists('ZipArchive')) {
        $GLOBALS['fetch_error'] = 'Automatic fetch is unavailable here. Please upload the full package manually.';
        return;
    }
    $zip = ROOT_DIR . '/_mp_pkg.zip';
    $data = @file_get_contents(RELEASE_URL);
    if ($data === false || @file_put_contents($zip, $data) === false) {
        $GLOBALS['fetch_error'] = 'Could not download the app package. Upload it manually instead.';
        return;
    }
    $za = new ZipArchive();
    $ok = $za->open($zip) === true;
    if ($ok) { $za->extractTo(ROOT_DIR); $za->close(); }
    @unlink($zip);
    if (!$ok || !is_dir(APP_DIR)) { $GLOBALS['fetch_error'] = 'The downloaded package could not be unpacked.'; return; }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'install.php'));
    exit;
}

$errors = [];
$reqs = requirements();
$canInstall = !in_array(false, array_column($reqs, 1), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch') {
    fetch_app();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canInstall) {
    $orgName  = trim((string)($_POST['org_name'] ?? '')) ?: 'Meeting Poll';
    $adminName= trim((string)($_POST['admin_name'] ?? ''));
    $email    = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $pass     = (string)($_POST['admin_pass'] ?? '');
    $tz       = (string)($_POST['default_tz'] ?? 'UTC');
    $accent   = (string)($_POST['accent'] ?? '#4f46e5');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid admin email address.';
    if (strlen($pass) < 8) $errors[] = 'Admin password must be at least 8 characters.';
    if ($adminName === '') $adminName = $email;
    if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'UTC';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#4f46e5';

    if (!$errors) {
        try {
            if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
            @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @mkdir(DATA_DIR . '/uploads', 0775, true);

            $GLOBALS['config'] = ['db' => DATA_DIR . '/app.sqlite', 'pretty' => false];
            require_once APP_DIR . '/db.php';
            require_once APP_DIR . '/auth.php';
            date_default_timezone_set('UTC');
            db(); // creates + migrates

            create_user($email, $pass, $adminName, 'admin');
            set_setting('org_name', $orgName);
            set_setting('accent', $accent);
            set_setting('default_tz', $tz);
            set_setting('max_participants', '500');
            set_setting('app_url', rtrim(app_root_url(), '/'));

            // Optional SMTP
            if (trim((string)($_POST['smtp_host'] ?? '')) !== '') {
                set_setting('smtp_host', trim((string)$_POST['smtp_host']));
                set_setting('smtp_port', (string)((int)($_POST['smtp_port'] ?? 587) ?: 587));
                set_setting('smtp_user', trim((string)($_POST['smtp_user'] ?? '')));
                set_setting('smtp_pass', (string)($_POST['smtp_pass'] ?? ''));
                $sec = (string)($_POST['smtp_secure'] ?? 'tls');
                set_setting('smtp_secure', in_array($sec, ['tls', 'ssl', 'none'], true) ? $sec : 'tls');
                set_setting('smtp_from', trim((string)($_POST['smtp_from'] ?? $email)));
            }

            // Optional logo
            if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
                $info = @getimagesize($_FILES['logo']['tmp_name']);
                $map = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
                $mime = $info['mime'] ?? '';
                if (isset($map[$mime]) && move_uploaded_file($_FILES['logo']['tmp_name'], DATA_DIR . '/uploads/logo.' . $map[$mime])) {
                    set_setting('logo_file', 'logo.' . $map[$mime]);
                }
            }

            $pretty = probe_pretty(rtrim(app_root_url(), '/'));

            $config = [
                'installed' => true,
                'db'        => DATA_DIR . '/app.sqlite',
                'secret'    => bin2hex(random_bytes(32)),
                'pretty'    => $pretty,
                'created'   => time(),
            ];
            file_put_contents(CONFIG_FILE, "<?php return " . var_export($config, true) . ";\n");

            header('Location: ' . app_root_url());
            exit;
        } catch (Throwable $ex) {
            $errors[] = 'Install failed: ' . $ex->getMessage();
            @unlink(CONFIG_FILE);
        }
    }
}

function probe_pretty(string $base): bool {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $r = @file_get_contents($base . '/healthz', false, $ctx);
    return is_string($r) && str_contains($r, 'MP_OK');
}

/* ---------- render ---------- */
function render(string $title, string $body): void {
    $css = base_path() . '/assets/app.css';
    echo '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark">'
        . '<title>' . e($title) . " · Setup</title><style>:root{--accent:#4f46e5}</style>"
        . "<link rel=\"icon\" href=\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%234f46e5'/%3E%3Cpath d='M8 17l5 5 11-12' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\">"
        . '<link rel="stylesheet" href="' . e($css) . '">'
        . '<script>(function(){try{var t=localStorage.getItem("mp-theme");if(t)document.documentElement.dataset.theme=t;else if(matchMedia("(prefers-color-scheme:dark)").matches)document.documentElement.dataset.theme="dark";}catch(e){}})();</script>'
        . '</head><body><main class="wrap narrow" style="padding-top:5vh">' . $body . '</main></body></html>';
}

ob_start();
?>
<div class="page-head"><h1>Set up Meeting Poll</h1></div>

<div class="card">
  <h2>Server check</h2>
  <ul class="user-list">
    <?php foreach ($reqs as [$label, $ok, $fix]): ?>
      <li class="user-row">
        <div><strong><?= $ok ? '✓' : '✕' ?></strong> <?= e($label) ?>
          <?php if (!$ok): ?><div class="muted small"><?= e($fix) ?></div><?php endif; ?>
        </div>
        <span class="pill <?= $ok ? 'pill-ok' : '' ?>"><?= $ok ? 'OK' : 'Fix' ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!is_dir(APP_DIR)): ?>
    <?php if (!empty($GLOBALS['fetch_error'])): ?><p class="flash flash-error"><?= e($GLOBALS['fetch_error']) ?></p><?php endif; ?>
    <?php if (RELEASE_URL !== '' && class_exists('ZipArchive')): ?>
      <form method="post" class="mt"><input type="hidden" name="action" value="fetch"><button class="btn">Download &amp; install app files</button></form>
    <?php else: ?>
      <p class="muted small mt">Upload the full Meeting Poll package into this folder, then reload.</p>
    <?php endif; ?>
  <?php elseif (!$canInstall): ?>
    <p class="flash flash-error">Resolve the items above, then reload this page.</p>
  <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($canInstall): ?>
<form method="post" enctype="multipart/form-data" class="card stack">
  <h2>Your organization</h2>
  <div class="row-2">
    <label>Organization name <input type="text" name="org_name" value="<?= e($_POST['org_name'] ?? '') ?>" placeholder="Helping Hands" required></label>
    <label>Accent color <input type="color" name="accent" value="<?= e($_POST['accent'] ?? '#4f46e5') ?>"></label>
  </div>
  <div class="row-2">
    <label>Default timezone
      <select name="default_tz" data-tz-select data-autotz>
        <?php foreach (common_timezones() as $z): ?><option value="<?= e($z) ?>" <?= ($z === ($_POST['default_tz'] ?? 'UTC')) ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Logo <span class="muted small">(optional)</span><input type="file" name="logo" accept="image/*"></label>
  </div>

  <h2 class="mt">Administrator account</h2>
  <label>Your name <input type="text" name="admin_name" value="<?= e($_POST['admin_name'] ?? '') ?>" required></label>
  <div class="row-2">
    <label>Email <input type="email" name="admin_email" value="<?= e($_POST['admin_email'] ?? '') ?>" required autocomplete="username"></label>
    <label>Password <span class="muted small">(min 8)</span><input type="password" name="admin_pass" minlength="8" required autocomplete="new-password"></label>
  </div>

  <details class="adv">
    <summary>Email setup (optional — you can skip and add it later)</summary>
    <div class="stack">
      <p class="muted small">Meeting Poll works without email by sharing links. Add SMTP to send invites and notifications.</p>
      <div class="row-2">
        <label>SMTP host <input type="text" name="smtp_host" placeholder="smtp.example.org"></label>
        <label>Port <input type="number" name="smtp_port" value="587"></label>
      </div>
      <div class="row-2">
        <label>Username <input type="text" name="smtp_user" autocomplete="off"></label>
        <label>Password <input type="password" name="smtp_pass" autocomplete="new-password"></label>
      </div>
      <div class="row-2">
        <label>Encryption
          <select name="smtp_secure"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">None</option></select>
        </label>
        <label>From address <input type="email" name="smtp_from" placeholder="noreply@example.org"></label>
      </div>
    </div>
  </details>

  <div class="form-actions"><button class="btn btn-block">Install Meeting Poll</button></div>
</form>
<script src="<?= e(base_path() . '/assets/app.js') ?>" defer></script>
<?php endif; ?>
<?php
render('Set up Meeting Poll', ob_get_clean());
