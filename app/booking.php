<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 * Booking pages (Calendly-style 1:1 scheduling). Model functions only;
 * reuses poll.php (local_to_utc, slot_label, client_ip, rate_ok) and
 * ics.php / mailer.php / notify.php helpers via synthesized poll+slot
 * arrays so nothing in those files needs to change.
 * ------------------------------------------------------------------ */

/** Weekdays in display order (Mon-first), keyed by PHP date('w') index (0=Sun). */
function booking_weekdays(): array {
    return [
        ['w' => 1, 'label' => 'Monday'],   ['w' => 2, 'label' => 'Tuesday'],
        ['w' => 3, 'label' => 'Wednesday'], ['w' => 4, 'label' => 'Thursday'],
        ['w' => 5, 'label' => 'Friday'],   ['w' => 6, 'label' => 'Saturday'],
        ['w' => 0, 'label' => 'Sunday'],
    ];
}

/** ponytail: fixed max ranges per weekday in the no-JS form; a 4th would need row-adding JS. */
function booking_max_ranges(): int { return 3; }

function booking_page_by_id(int $id): ?array {
    $s = db()->prepare('SELECT * FROM booking_pages WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function booking_page_by_token(string $token): ?array {
    $s = db()->prepare('SELECT * FROM booking_pages WHERE public_token = ?');
    $s->execute([$token]);
    return $s->fetch() ?: null;
}

function booking_pages_for_user(int $userId): array {
    $s = db()->prepare('SELECT * FROM booking_pages WHERE user_id = ? ORDER BY created_at DESC');
    $s->execute([$userId]);
    return $s->fetchAll();
}

/** Decoded availability: [weekday(0-6) => [[start,end],...]], ranges sorted by start. */
function booking_availability(array $page): array {
    $raw = json_decode((string)($page['availability'] ?? '{}'), true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $w => $ranges) {
        if (!is_array($ranges)) continue;
        $clean = [];
        foreach ($ranges as $r) {
            if (is_array($r) && isset($r[0], $r[1])) $clean[] = [(string)$r[0], (string)$r[1]];
        }
        usort($clean, function ($a, $b) { return strcmp($a[0], $b[0]); });
        if ($clean) $out[(int)$w] = $clean;
    }
    return $out;
}

/** Minutes past midnight for an 'H:i' string. */
function booking_hm_to_min(string $hm): int {
    $p = explode(':', $hm);
    return ((int)($p[0] ?? 0)) * 60 + (int)($p[1] ?? 0);
}

function create_booking_page(int $userId, array $d): int {
    $db = db();
    $now = time();
    $st = $db->prepare('INSERT INTO booking_pages
        (user_id, public_token, title, description, location, duration_min, tz, availability, horizon_days, min_notice_hours, buffer_min, paused, created_at, updated_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?,?)');
    $st->execute([
        $userId, random_token(9), $d['title'], $d['description'], $d['location'],
        $d['duration_min'], $d['tz'], json_encode($d['availability']),
        $d['horizon_days'], $d['min_notice_hours'], $d['buffer_min'], $now, $now,
    ]);
    return (int)$db->lastInsertId();
}

function update_booking_page(int $pageId, array $d): void {
    db()->prepare('UPDATE booking_pages SET title=?, description=?, location=?, duration_min=?, tz=?, availability=?, horizon_days=?, min_notice_hours=?, buffer_min=?, updated_at=? WHERE id=?')
        ->execute([
            $d['title'], $d['description'], $d['location'], $d['duration_min'], $d['tz'],
            json_encode($d['availability']), $d['horizon_days'], $d['min_notice_hours'],
            $d['buffer_min'], time(), $pageId,
        ]);
}

function set_booking_page_paused(int $pageId, bool $paused): void {
    db()->prepare('UPDATE booking_pages SET paused=?, updated_at=? WHERE id=?')
        ->execute([$paused ? 1 : 0, time(), $pageId]);
}

/** Active (not cancelled) bookings for a page that start now or later. Blocks deletion. */
function page_upcoming_active_count(int $pageId): int {
    $s = db()->prepare("SELECT COUNT(*) FROM bookings WHERE page_id=? AND status='active' AND start_utc >= ?");
    $s->execute([$pageId, time()]);
    return (int)$s->fetchColumn();
}

/** Delete a page and all its bookings (past/cancelled). Caller must check no upcoming actives remain. */
function delete_booking_page(int $pageId): void {
    $db = db();
    $db->prepare('DELETE FROM bookings WHERE page_id=?')->execute([$pageId]);
    $db->prepare('DELETE FROM booking_pages WHERE id=?')->execute([$pageId]);
}

/* ---------------- Days off (per organizer, all their pages) ---------------- */

function blocked_dates_for_user(int $userId): array {
    $s = db()->prepare('SELECT * FROM blocked_dates WHERE user_id=? ORDER BY day');
    $s->execute([$userId]);
    return $s->fetchAll();
}

/** Set of blocked 'Y-m-d' => true for fast lookup during generation. */
function blocked_dates_set(int $userId): array {
    $set = [];
    foreach (blocked_dates_for_user($userId) as $b) $set[$b['day']] = true;
    return $set;
}

function add_blocked_date(int $userId, string $day): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $day);
    if (!$d || $d->format('Y-m-d') !== $day) return false;
    try {
        db()->prepare('INSERT INTO blocked_dates(user_id, day, created_at) VALUES(?,?,?)')
            ->execute([$userId, $day, time()]);
    } catch (PDOException $e) {
        return false; // duplicate (unique index) — already blocked, treat as no-op
    }
    return true;
}

function remove_blocked_date(int $userId, int $id): void {
    db()->prepare('DELETE FROM blocked_dates WHERE id=? AND user_id=?')->execute([$id, $userId]);
}

/* ---------------- Slot generation ---------------- */

/** Active-booking intervals [[start,end],...] for an organizer across ALL their pages. */
function booking_busy_intervals(int $userId): array {
    $s = db()->prepare("SELECT start_utc, duration_min FROM bookings WHERE user_id=? AND status='active'");
    $s->execute([$userId]);
    $out = [];
    foreach ($s->fetchAll() as $b) {
        $start = (int)$b['start_utc'];
        $out[] = [$start, $start + max(1, (int)$b['duration_min']) * 60];
    }
    return $out;
}

/** True if [start,end] overlaps any busy interval once expanded by $bufferMin on each side. */
function booking_conflicts(int $start, int $end, int $bufferMin, array $busy): bool {
    $buf = max(0, $bufferMin) * 60;
    foreach ($busy as $iv) {
        if ($start < $iv[1] + $buf && $end > $iv[0] - $buf) return true;
    }
    return false;
}

/**
 * Open slots for a page: every duration-length start (stepping by the duration)
 * inside each availability window on each date within the horizon, converted
 * date-by-date from the page tz to UTC so wall-clock stays correct across DST.
 * Excludes: starts before now+minNotice, blocked dates, and slots overlapping
 * (buffer included) any active booking of the same organizer on any page.
 * Returns slot arrays shaped like poll slots so time_attr()/slot_label() work.
 */
function booking_open_slots(array $page, ?int $now = null): array {
    $now = $now ?? time();
    $tz = new DateTimeZone((string)$page['tz']);
    $dur = max(1, (int)$page['duration_min']);
    $step = $dur * 60;
    $buffer = max(0, (int)$page['buffer_min']);
    $earliest = $now + max(0, (int)$page['min_notice_hours']) * 3600;
    $horizon = max(1, (int)$page['horizon_days']);
    $limit = $now + $horizon * 86400;

    $avail = booking_availability($page);
    if (!$avail) return [];
    $blocked = blocked_dates_set((int)$page['user_id']);
    $busy = booking_busy_intervals((int)$page['user_id']);

    $cursor = new DateTime('@' . $now);
    $cursor->setTimezone($tz);
    $cursor->setTime(0, 0, 0);

    $out = [];
    $seen = [];
    for ($i = 0; $i <= $horizon; $i++) {
        $dateStr = $cursor->format('Y-m-d');
        $wd = (int)$cursor->format('w');
        if (!isset($blocked[$dateStr]) && !empty($avail[$wd])) {
            foreach ($avail[$wd] as $range) {
                $ws = (new DateTime($dateStr . ' ' . $range[0], $tz))->getTimestamp();
                $we = (new DateTime($dateStr . ' ' . $range[1], $tz))->getTimestamp();
                for ($t = $ws; $t + $step <= $we; $t += $step) {
                    if ($t < $earliest || $t >= $limit) continue;
                    if (isset($seen[$t])) continue;
                    if (booking_conflicts($t, $t + $step, $buffer, $busy)) continue;
                    $seen[$t] = true;
                    $out[] = ['kind' => 'datetime', 'start_utc' => $t, 'duration_min' => $dur];
                }
            }
        }
        $cursor->modify('+1 day');
    }
    usort($out, function ($a, $b) { return $a['start_utc'] <=> $b['start_utc']; });
    return $out;
}

/**
 * Pure-rename timezone aliases (bidirectional). These zones were renamed in IANA with
 * identical rules, so either name denotes the same clock; which one local tzdata knows
 * depends on its age (PHP 7.4 on old tzdata has Europe/Kiev but not Europe/Kyiv), so we map
 * both directions and the caller still validates the alias before use.
 * ponytail: renames ONLY — never geographic splits with divergent rules (e.g.
 * America/Ciudad_Juarez, which correctly falls back to the page tz instead of re-homing).
 */
function booking_tz_aliases(): array {
    return [
        'Europe/Kyiv' => 'Europe/Kiev',      'Europe/Kiev' => 'Europe/Kyiv',
        'Asia/Kolkata' => 'Asia/Calcutta',   'Asia/Calcutta' => 'Asia/Kolkata',
        'Asia/Ho_Chi_Minh' => 'Asia/Saigon', 'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Asia/Yangon' => 'Asia/Rangoon',     'Asia/Rangoon' => 'Asia/Yangon',
        'America/Nuuk' => 'America/Godthab',  'America/Godthab' => 'America/Nuuk',
    ];
}

/** Resolve a requested zone to a name this machine's tzdata actually knows, or null. */
function booking_resolve_tz(string $req): ?string {
    if ($req === '') return null;
    $known = timezone_identifiers_list();
    if (in_array($req, $known, true)) return $req;
    $aliases = booking_tz_aliases();
    if (isset($aliases[$req]) && in_array($aliases[$req], $known, true)) return $aliases[$req];
    return null;
}

/**
 * Resolve the display timezone for a public booking/manage view from a ?tz= request param.
 * Returns [tz, requested]: requested is true whenever ANY tz param was present — valid,
 * aliasable, or garbage. The public page emits its JS auto-detect sentinel ONLY when no tz
 * param was present, so a zone the server can't resolve renders as plain page-tz with NO
 * sentinel and therefore cannot drive an infinite re-detect/redirect loop. An unresolvable
 * zone falls back to the page's own timezone.
 */
function booking_view_tz(array $page): array {
    $req = trim((string)param('tz', ''));
    if ($req === '') return [(string)$page['tz'], false];
    $resolved = booking_resolve_tz($req);
    return [$resolved !== null ? $resolved : (string)$page['tz'], true];
}

/** Human label for a booking row in the organizer's admin list (in the page's tz). */
function booking_when(array $b): string {
    $slot = ['kind' => 'datetime', 'start_utc' => (int)$b['start_utc'], 'duration_min' => (int)$b['duration_min']];
    return slot_label($slot, (string)$b['page_tz']);
}

/** Group open slots by day (in the given tz) for the public list. */
function booking_group_by_day(array $slots, string $tz): array {
    $zone = new DateTimeZone($tz);
    $g = [];
    foreach ($slots as $s) {
        $d = (new DateTime('@' . $s['start_utc']))->setTimezone($zone);
        $key = $d->format('Y-m-d');
        if (!isset($g[$key])) $g[$key] = ['label' => $d->format('l, M j'), 'slots' => []];
        $g[$key]['slots'][] = $s;
    }
    return $g;
}

/* ---------------- Booking a slot ---------------- */

function booking_by_token(string $manageToken): ?array {
    if ($manageToken === '') return null;
    $s = db()->prepare('SELECT * FROM bookings WHERE manage_token = ?');
    $s->execute([$manageToken]);
    return $s->fetch() ?: null;
}

function booking_by_id(int $id): ?array {
    $s = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function bookings_for_user(int $userId): array {
    $s = db()->prepare('SELECT b.*, p.title AS page_title, p.tz AS page_tz, p.public_token AS page_token
                        FROM bookings b JOIN booking_pages p ON p.id = b.page_id
                        WHERE b.user_id = ? ORDER BY b.start_utc DESC');
    $s->execute([$userId]);
    return $s->fetchAll();
}

/**
 * Attempt to book $startUtc on $page. Re-validates against freshly generated
 * open slots inside a write transaction, then inserts. The partial unique index
 * on (user_id, start_utc) WHERE status='active' is the atomic backstop against a
 * concurrent identical booking. Returns ['ok'=>true,'token'=>...] or
 * ['ok'=>false,'reason'=>'paused'|'taken'].
 */
function book_slot(array $page, int $startUtc, string $name, string $email, string $note, string $ip): array {
    $db = db();
    $db->exec('PRAGMA busy_timeout = 3000'); // serialize racing writers instead of failing fast
    try {
        // BEGIN is inside the try: under a held write lock it can itself fail with
        // SQLITE_BUSY past the timeout — the race loser must get a clean 'taken', not a fatal.
        $db->exec('BEGIN IMMEDIATE');
        if (!empty($page['paused'])) { $db->exec('ROLLBACK'); return ['ok' => false, 'reason' => 'paused']; }

        $open = booking_open_slots($page);
        $valid = false;
        foreach ($open as $s) { if ($s['start_utc'] === $startUtc) { $valid = true; break; } }
        if (!$valid) { $db->exec('ROLLBACK'); return ['ok' => false, 'reason' => 'taken']; }

        $token = random_token(16);
        $db->prepare('INSERT INTO bookings(page_id, user_id, start_utc, duration_min, name, email, note, manage_token, status, ip, created_at)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([
               (int)$page['id'], (int)$page['user_id'], $startUtc, (int)$page['duration_min'],
               $name, $email, $note, $token, 'active', $ip, time(),
           ]);
        $id = (int)$db->lastInsertId();
        $db->exec('COMMIT');
        return ['ok' => true, 'id' => $id, 'token' => $token];
    } catch (PDOException $e) {
        // Guard the rollback: if BEGIN never acquired the lock there is no active
        // transaction, and an unguarded ROLLBACK would re-throw and blow up the request.
        try { $db->exec('ROLLBACK'); } catch (PDOException $ignored) {}
        return ['ok' => false, 'reason' => 'taken']; // lock contention or unique-index violation = someone won the race
    }
}

/** Cancel an active booking (idempotent). Returns true only if it was active and is now cancelled. */
function cancel_booking(array $booking): bool {
    if (($booking['status'] ?? '') !== 'active') return false;
    $st = db()->prepare("UPDATE bookings SET status='cancelled', cancelled_at=? WHERE id=? AND status='active'");
    $st->execute([time(), (int)$booking['id']]);
    return $st->rowCount() > 0;
}

/* ---------------- Calendar reuse: synthesize poll+slot arrays ---------------- */

function booking_event_poll(array $page): array {
    return [
        'title' => $page['title'],
        'description' => (string)($page['description'] ?? ''),
        'location' => (string)($page['location'] ?? ''),
        'public_token' => $page['public_token'],
    ];
}

function booking_event_slot(array $booking): array {
    return [
        'kind' => 'datetime',
        'id' => (int)$booking['id'],
        'start_utc' => (int)$booking['start_utc'],
        'duration_min' => (int)$booking['duration_min'],
        'date' => null,
    ];
}

/* ---------------- Email (optional; every flow works without SMTP) ---------------- */

function notify_booking_created(array $page, array $booking): void {
    if (!mailer_configured()) return;
    $poll = booking_event_poll($page);
    $slot = booking_event_slot($booking);
    $when = slot_label($slot, (string)$page['tz']) . ' (' . $page['tz'] . ')';
    $manage = absolute_url('/m/' . $booking['manage_token']);
    $loc = (string)($page['location'] ?? '');

    $body = '<p>Your booking for <strong>' . e($page['title']) . '</strong> is confirmed:</p>'
        . '<p style="font-size:18px"><strong>' . e($when) . '</strong></p>'
        . ($loc !== '' ? '<p>Location: ' . e($loc) . '</p>' : '')
        . '<p><a href="' . e($manage) . '">Manage or cancel this booking</a></p>'
        . '<p><a href="' . e(gcal_link($poll, $slot)) . '">Add to Google</a> &middot; '
        . '<a href="' . e(outlook_link($poll, $slot)) . '">Add to Outlook</a></p>';
    send_mail($booking['email'], 'Booking confirmed: ' . $page['title'], email_layout('Booking confirmed', $body));

    $owner = db_user((int)$page['user_id']);
    if ($owner) {
        $note = (string)($booking['note'] ?? '');
        $obody = '<p><strong>' . e($booking['name']) . '</strong> (' . e($booking['email']) . ') booked <strong>' . e($page['title']) . '</strong>.</p>'
            . '<p style="font-size:18px"><strong>' . e($when) . '</strong></p>'
            . ($note !== '' ? '<p>Note: ' . e($note) . '</p>' : '');
        send_mail($owner['email'], 'New booking: ' . $page['title'], email_layout('New booking', $obody));
    }
}

/** Notify the *other* party. $by is 'invitee' or 'organizer'. */
function notify_booking_cancelled(array $page, array $booking, string $by): void {
    if (!mailer_configured()) return;
    $when = slot_label(booking_event_slot($booking), (string)$page['tz']) . ' (' . $page['tz'] . ')';
    if ($by === 'invitee') {
        $owner = db_user((int)$page['user_id']);
        if ($owner) {
            $body = '<p><strong>' . e($booking['name']) . '</strong> cancelled their booking for <strong>'
                . e($page['title']) . '</strong> at ' . e($when) . '.</p>';
            send_mail($owner['email'], 'Booking cancelled: ' . $page['title'], email_layout('Booking cancelled', $body));
        }
    } else {
        $body = '<p>Your booking for <strong>' . e($page['title']) . '</strong> at ' . e($when)
            . ' has been cancelled by the organizer.</p>';
        send_mail($booking['email'], 'Booking cancelled: ' . $page['title'], email_layout('Booking cancelled', $body));
    }
}
