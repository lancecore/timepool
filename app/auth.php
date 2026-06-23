<?php
declare(strict_types=1);

function current_user(): ?array {
    static $resolved = false; static $user = null;
    if ($resolved) return $user;
    $resolved = true;
    $id = $_SESSION['uid'] ?? null;
    $user = $id ? db_user((int)$id) : null;
    if ($user && !$user['active']) $user = null;
    return $user;
}

function require_login(): array {
    $u = current_user();
    if (!$u) redirect('/login');
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') { http_response_code(403); exit('This area is for administrators only.'); }
    return $u;
}

function db_user(int $id): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function user_by_email(string $email): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE email = ?');
    $s->execute([strtolower(trim($email))]);
    return $s->fetch() ?: null;
}

function login_attempt(string $email, string $password): bool {
    $u = user_by_email($email);
    if ($u && $u['active'] && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        return true;
    }
    return false;
}

function logout(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

function create_user(string $email, string $password, string $name, string $role = 'organizer'): int {
    $st = db()->prepare('INSERT INTO users(email, password_hash, name, role, active, created_at)
                         VALUES(?, ?, ?, ?, 1, ?)');
    $st->execute([
        strtolower(trim($email)),
        password_hash($password, PASSWORD_DEFAULT),
        trim($name) ?: $email,
        $role,
        time(),
    ]);
    return (int)db()->lastInsertId();
}

function all_users(): array {
    return db()->query('SELECT * FROM users ORDER BY created_at')->fetchAll();
}
