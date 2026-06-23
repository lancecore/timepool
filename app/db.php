<?php
declare(strict_types=1);

/** Open the SQLite connection (singleton) and ensure the schema exists. */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $path = $GLOBALS['config']['db'] ?? (DATA_DIR . '/app.sqlite');
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'organizer',
            active INTEGER NOT NULL DEFAULT 1,
            reset_token TEXT,
            reset_expires INTEGER,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS polls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            public_token TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            location TEXT,
            organizer_tz TEXT NOT NULL DEFAULT 'UTC',
            blind INTEGER NOT NULL DEFAULT 0,
            deadline_utc INTEGER,
            closed INTEGER NOT NULL DEFAULT 0,
            final_slot_id INTEGER,
            nudged_at INTEGER,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            kind TEXT NOT NULL DEFAULT 'datetime',
            start_utc INTEGER,
            date TEXT,
            duration_min INTEGER,
            sort INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            comment TEXT,
            edit_token TEXT NOT NULL,
            ip TEXT,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            participant_id INTEGER NOT NULL,
            slot_id INTEGER NOT NULL,
            choice TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE IF NOT EXISTS rate (
            ip TEXT NOT NULL,
            ts INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_slots_poll ON slots(poll_id);
        CREATE INDEX IF NOT EXISTS idx_part_poll ON participants(poll_id);
        CREATE INDEX IF NOT EXISTS idx_resp_part ON responses(participant_id);
        CREATE INDEX IF NOT EXISTS idx_resp_slot ON responses(slot_id);
        CREATE INDEX IF NOT EXISTS idx_activity_poll ON activity(poll_id);
        CREATE INDEX IF NOT EXISTS idx_invites_poll ON invites(poll_id);
        CREATE INDEX IF NOT EXISTS idx_rate_ip ON rate(ip, ts);
    ");
}

/** Read a setting (cached per request). */
function setting(string $key, $default = null) {
    if (!isset($GLOBALS['settings_cache'])) {
        $GLOBALS['settings_cache'] = [];
        foreach (db()->query('SELECT key, value FROM settings') as $r) {
            $GLOBALS['settings_cache'][$r['key']] = $r['value'];
        }
    }
    return $GLOBALS['settings_cache'][$key] ?? $default;
}

function set_setting(string $key, ?string $value): void {
    $st = db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?)
                         ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, $value]);
    $GLOBALS['settings_cache'][$key] = $value;
}
