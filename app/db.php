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
            show_individual INTEGER NOT NULL DEFAULT 0,
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

        CREATE TABLE IF NOT EXISTS booking_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            public_token TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            location TEXT,
            duration_min INTEGER NOT NULL DEFAULT 30,
            tz TEXT NOT NULL DEFAULT 'UTC',
            type TEXT NOT NULL DEFAULT 'weekly',
            availability TEXT NOT NULL DEFAULT '{}',
            horizon_days INTEGER NOT NULL DEFAULT 60,
            min_notice_hours INTEGER NOT NULL DEFAULT 4,
            buffer_min INTEGER NOT NULL DEFAULT 0,
            paused INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        -- Calendar pages place availability on specific dates (start_hm/end_hm are 'H:i' wall-clock
        -- in the page tz). Weekly pages ignore this table; their template stays in booking_pages.availability.
        CREATE TABLE IF NOT EXISTS booking_windows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            start_hm TEXT NOT NULL,
            end_hm TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        -- Per-page blocked dates (both types). A block hides a date on this page only; it never
        -- deletes calendar windows, so unblocking restores them. Org-wide days off live in blocked_dates.
        CREATE TABLE IF NOT EXISTS booking_page_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            start_utc INTEGER NOT NULL,
            duration_min INTEGER NOT NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            note TEXT,
            manage_token TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            ip TEXT,
            created_at INTEGER NOT NULL,
            cancelled_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS blocked_dates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_bpages_user ON booking_pages(user_id);
        CREATE INDEX IF NOT EXISTS idx_bookings_page ON bookings(page_id);
        CREATE INDEX IF NOT EXISTS idx_bookings_user ON bookings(user_id, status);
        -- DB-level double-booking guard: one active booking per organizer per start.
        -- A cancelled row (status != 'active') is excluded, so its slot can be rebooked.
        CREATE UNIQUE INDEX IF NOT EXISTS idx_bookings_active ON bookings(user_id, start_utc) WHERE status = 'active';
        CREATE UNIQUE INDEX IF NOT EXISTS idx_blocked_uday ON blocked_dates(user_id, day);
        CREATE INDEX IF NOT EXISTS idx_blocked_user ON blocked_dates(user_id);
        CREATE INDEX IF NOT EXISTS idx_bwindows_page ON booking_windows(page_id, day);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_bpblocks_pday ON booking_page_blocks(page_id, day);
        CREATE INDEX IF NOT EXISTS idx_bpblocks_page ON booking_page_blocks(page_id);
    ");

    // Zero-step upgrade: installs created before calendar pages have booking_pages without a `type`
    // column. Add it once, guarded by a PRAGMA check so re-running migrations stays a no-op. Existing
    // rows default to 'weekly', preserving every weekly page's behaviour untouched.
    $hasType = false;
    foreach ($db->query('PRAGMA table_info(booking_pages)') as $col) {
        if ($col['name'] === 'type') { $hasType = true; break; }
    }
    if (!$hasType) {
        $db->exec("ALTER TABLE booking_pages ADD COLUMN type TEXT NOT NULL DEFAULT 'weekly'");
    }

    // Zero-step upgrade: installs created before per-poll result visibility have `polls` without a
    // `show_individual` column. Add it once, guarded by a PRAGMA check so re-running migrations stays a
    // no-op. Existing rows default to 0, so every existing poll keeps hiding individual responses.
    $hasShowIndividual = false;
    foreach ($db->query('PRAGMA table_info(polls)') as $col) {
        if ($col['name'] === 'show_individual') { $hasShowIndividual = true; break; }
    }
    if (!$hasShowIndividual) {
        $db->exec("ALTER TABLE polls ADD COLUMN show_individual INTEGER NOT NULL DEFAULT 0");
    }
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
