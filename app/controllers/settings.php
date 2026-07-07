<?php
declare(strict_types=1);

function settings_form(): void {
    require_admin();
    view('settings', ['title' => 'Settings']);
}

function settings_save(): void {
    require_admin();
    csrf_check();

    set_setting('org_name', trim((string)param('org_name', '')) ?: 'TimePool');
    $accent = trim((string)param('accent', ''));
    set_setting('accent', preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#4f46e5');
    $tz = trim((string)param('default_tz', 'UTC'));
    set_setting('default_tz', in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC');
    $cap = (int)param('max_participants', 500);
    set_setting('max_participants', (string)max(1, min(100000, $cap)));

    // SMTP — only overwrite the password if a new one was typed.
    set_setting('smtp_host', trim((string)param('smtp_host', '')));
    set_setting('smtp_port', (string)((int)param('smtp_port', 587) ?: 587));
    set_setting('smtp_user', trim((string)param('smtp_user', '')));
    if (trim((string)param('smtp_pass', '')) !== '') set_setting('smtp_pass', (string)param('smtp_pass'));
    $secure = (string)param('smtp_secure', 'tls');
    set_setting('smtp_secure', in_array($secure, ['tls', 'ssl', 'none'], true) ? $secure : 'tls');
    set_setting('smtp_from', trim((string)param('smtp_from', '')));

    handle_logo_upload();

    if (trim((string)param('test_email', '')) !== '') {
        $to = trim((string)param('test_email'));
        $ok = send_mail($to, 'TimePool test email', email_layout('It works', '<p>SMTP is configured correctly.</p>'));
        flash($ok ? "Test email sent to $to." : 'Could not send the test email — check the SMTP settings.', $ok ? 'success' : 'error');
    } else {
        flash('Settings saved.', 'success');
    }
    redirect('/settings');
}

function handle_logo_upload(): void {
    if (empty($_FILES['logo']['tmp_name']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) return;
    $info = @getimagesize($_FILES['logo']['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = $info['mime'] ?? mime_content_type($_FILES['logo']['tmp_name']);
    if (!isset($allowed[$mime])) { flash('Logo must be a PNG, JPG, GIF or WEBP image.', 'error'); return; }
    $dir = DATA_DIR . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = 'logo.' . $allowed[$mime];
    if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $name)) {
        set_setting('logo_file', $name);
    }
}

/** Admin-readable error log (bootstrap points PHP's error_log at this file). */
function errors_page(): void {
    require_admin();
    $path = DATA_DIR . '/error.log';
    // Include the rotated generation so entries don't vanish right after a rotation.
    $tail = log_tail($path . '.1', 32768) . log_tail($path);
    view('errors', ['title' => 'Error log', 'tail' => $tail]);
}

function errors_clear(): void {
    require_admin();
    csrf_check();
    @unlink(DATA_DIR . '/error.log');
    @unlink(DATA_DIR . '/error.log.1');
    flash('Error log cleared.', 'success');
    redirect('/errors');
}

function users_list(): void {
    require_admin();
    view('users', ['title' => 'Organizers', 'users' => all_users()]);
}

function users_create(): void {
    $me = require_admin();
    csrf_check();
    $email = strtolower(trim((string)param('email', '')));
    $name = trim((string)param('name', ''));
    $pw = (string)param('password', '');
    if (!valid_email($email)) { flash('Enter a valid email address.', 'error'); redirect('/users'); }
    if (strlen($pw) < 8) { flash('Password must be at least 8 characters.', 'error'); redirect('/users'); }
    if (user_by_email($email)) { flash('A user with that email already exists.', 'error'); redirect('/users'); }
    $role = param('role') === 'admin' ? 'admin' : 'organizer';
    create_user($email, $pw, $name, $role);
    flash('Organizer added.', 'success');
    redirect('/users');
}

function users_toggle(string $id): void {
    $me = require_admin();
    csrf_check();
    if ((int)$id === (int)$me['id']) { flash('You cannot disable your own account.', 'error'); redirect('/users'); }
    $u = db_user((int)$id);
    if ($u) {
        db()->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$u['active'] ? 0 : 1, (int)$id]);
        flash($u['active'] ? 'Account disabled.' : 'Account enabled.', 'success');
    }
    redirect('/users');
}
