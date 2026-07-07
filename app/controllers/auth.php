<?php
declare(strict_types=1);

function home(): void { redirect(current_user() ? '/dashboard' : '/login'); }

/** Used by the installer to detect whether clean URLs (mod_rewrite) work. */
function healthz(): void { header('Content-Type: text/plain'); echo 'TP_OK'; }

function login_form(): void {
    if (current_user()) redirect('/dashboard');
    view('login', ['title' => 'Sign in'], 'public');
}

function login_submit(): void {
    csrf_check();
    if (!rate_ok(client_ip(), 10, 60)) {
        flash('Too many sign-in attempts. Please wait a minute and try again.', 'error');
        redirect('/login');
    }
    if (login_attempt((string)param('email', ''), (string)param('password', ''))) {
        redirect('/dashboard');
    }
    flash('Incorrect email or password.', 'error');
    redirect('/login');
}

function logout_do(): void {
    csrf_check();
    logout();
    redirect('/login');
}

function forgot_form(): void {
    view('forgot', ['title' => 'Reset password'], 'public');
}

function forgot_submit(): void {
    csrf_check();
    $email = strtolower(trim((string)param('email', '')));
    $u = $email ? user_by_email($email) : null;
    if ($u) {
        $token = random_token(24);
        db()->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?')
            ->execute([$token, time() + 3600, (int)$u['id']]);
        if (mailer_configured()) {
            $link = absolute_url('/reset?token=' . $token);
            $body = "<p>Use the link below to choose a new password. It expires in 1 hour.</p>"
                . "<p><a href=\"" . e($link) . "\">Reset your password</a></p>";
            send_mail($u['email'], 'Reset your password', email_layout('Password reset', $body));
        }
    }
    flash('If that email is registered, a reset link has been sent. ' .
        (mailer_configured() ? '' : 'Email is not configured on this install — see docs/INSTALL.md for the file-based reset.'),
        'success');
    redirect('/login');
}

function reset_form(): void {
    $token = (string)param('token', '');
    $u = reset_user_for_token($token);
    if (!$u) { flash('That reset link is invalid or has expired.', 'error'); redirect('/login'); }
    view('reset', ['title' => 'Choose a new password', 'token' => $token], 'public');
}

function reset_submit(): void {
    csrf_check();
    $token = (string)param('token', '');
    $u = reset_user_for_token($token);
    if (!$u) { flash('That reset link is invalid or has expired.', 'error'); redirect('/login'); }
    $pw = (string)param('password', '');
    if (strlen($pw) < 8) { flash('Password must be at least 8 characters.', 'error'); redirect('/reset?token=' . $token); }
    db()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
        ->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$u['id']]);
    flash('Password updated. You can sign in now.', 'success');
    redirect('/login');
}

function reset_user_for_token(string $token): ?array {
    if ($token === '') return null;
    $s = db()->prepare('SELECT * FROM users WHERE reset_token = ? AND reset_expires > ?');
    $s->execute([$token, time()]);
    return $s->fetch() ?: null;
}
