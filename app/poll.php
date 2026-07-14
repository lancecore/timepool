<?php
declare(strict_types=1);

function poll_by_id(int $id): ?array {
    $s = db()->prepare('SELECT * FROM polls WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function poll_by_token(string $token): ?array {
    $s = db()->prepare('SELECT * FROM polls WHERE public_token = ?');
    $s->execute([$token]);
    return $s->fetch() ?: null;
}

function polls_for_user(int $userId): array {
    $s = db()->prepare('SELECT * FROM polls WHERE user_id = ? ORDER BY created_at DESC');
    $s->execute([$userId]);
    return $s->fetchAll();
}

function slots_for_poll(int $pollId): array {
    $s = db()->prepare('SELECT * FROM slots WHERE poll_id = ? ORDER BY sort, COALESCE(start_utc, 0), COALESCE(date, "")');
    $s->execute([$pollId]);
    return $s->fetchAll();
}

function slot_by_id(int $id): ?array {
    $s = db()->prepare('SELECT * FROM slots WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Convert an organizer-local 'Y-m-d H:i' value to an absolute UTC epoch. */
function local_to_utc(string $localDateTime, string $tz): ?int {
    try {
        $dt = new DateTime($localDateTime, new DateTimeZone($tz));
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Insert slots for a poll from raw input rows.
 * Each row: ['kind'=>'datetime'|'date', 'date'=>'Y-m-d', 'time'=>'H:i', 'duration'=>int]
 */
function replace_slots(int $pollId, string $tz, array $rows): void {
    $db = db();
    $db->prepare('DELETE FROM slots WHERE poll_id = ?')->execute([$pollId]);
    $ins = $db->prepare('INSERT INTO slots(poll_id, kind, start_utc, date, duration_min, sort) VALUES(?,?,?,?,?,?)');
    $sort = 0;
    foreach ($rows as $row) {
        $date = trim((string)($row['date'] ?? ''));
        if ($date === '') continue;
        $kind = ($row['kind'] ?? 'datetime') === 'date' ? 'date' : 'datetime';
        if ($kind === 'date') {
            $ins->execute([$pollId, 'date', null, $date, null, $sort++]);
        } else {
            $time = trim((string)($row['time'] ?? ''));
            if ($time === '') continue;
            $epoch = local_to_utc("$date $time", $tz);
            if ($epoch === null) continue;
            $dur = (int)($row['duration'] ?? 60);
            $ins->execute([$pollId, 'datetime', $epoch, null, $dur > 0 ? $dur : 60, $sort++]);
        }
    }
}

function create_poll(int $userId, array $data, array $slotRows): int {
    $db = db();
    $now = time();
    $st = $db->prepare('INSERT INTO polls(user_id, public_token, title, description, location, organizer_tz, blind, deadline_utc, closed, created_at, updated_at)
                        VALUES(?,?,?,?,?,?,?,?,0,?,?)');
    $st->execute([
        $userId,
        random_token(9),
        $data['title'],
        $data['description'] ?? '',
        $data['location'] ?? '',
        $data['organizer_tz'],
        !empty($data['blind']) ? 1 : 0,
        $data['deadline_utc'] ?? null,
        $now, $now,
    ]);
    $id = (int)$db->lastInsertId();
    replace_slots($id, $data['organizer_tz'], $slotRows);
    return $id;
}

function update_poll(int $pollId, array $data, array $slotRows): void {
    $db = db();
    $st = $db->prepare('UPDATE polls SET title=?, description=?, location=?, organizer_tz=?, blind=?, deadline_utc=?, updated_at=? WHERE id=?');
    $st->execute([
        $data['title'], $data['description'] ?? '', $data['location'] ?? '',
        $data['organizer_tz'], !empty($data['blind']) ? 1 : 0, $data['deadline_utc'] ?? null,
        time(), $pollId,
    ]);
    replace_slots($pollId, $data['organizer_tz'], $slotRows);
    // Clearing/Reordering slots invalidates a finalized slot that no longer exists.
    $final = (int)(poll_by_id($pollId)['final_slot_id'] ?? 0);
    if ($final && !slot_by_id($final)) {
        $db->prepare('UPDATE polls SET final_slot_id = NULL WHERE id = ?')->execute([$pollId]);
    }
}

function duplicate_poll(int $pollId, int $userId): ?int {
    $poll = poll_by_id($pollId);
    if (!$poll) return null;
    $rows = [];
    foreach (slots_for_poll($pollId) as $s) {
        if ($s['kind'] === 'date') {
            $rows[] = ['kind' => 'date', 'date' => $s['date']];
        } else {
            $dt = (new DateTime('@' . $s['start_utc']))->setTimezone(new DateTimeZone($poll['organizer_tz']));
            $rows[] = ['kind' => 'datetime', 'date' => $dt->format('Y-m-d'), 'time' => $dt->format('H:i'), 'duration' => $s['duration_min']];
        }
    }
    return create_poll($userId, [
        'title' => $poll['title'] . ' (copy)',
        'description' => $poll['description'],
        'location' => $poll['location'],
        'organizer_tz' => $poll['organizer_tz'],
        'blind' => $poll['blind'],
        'deadline_utc' => null,
    ], $rows);
}

function delete_poll(int $pollId): void {
    $db = db();
    $db->prepare('DELETE FROM responses WHERE participant_id IN (SELECT id FROM participants WHERE poll_id = ?)')->execute([$pollId]);
    foreach (['participants', 'slots', 'activity', 'invites', 'polls'] as $t) {
        $col = $t === 'polls' ? 'id' : 'poll_id';
        $db->prepare("DELETE FROM $t WHERE $col = ?")->execute([$pollId]);
    }
}

function set_poll_closed(int $pollId, bool $closed): void {
    db()->prepare('UPDATE polls SET closed = ?, updated_at = ? WHERE id = ?')->execute([$closed ? 1 : 0, time(), $pollId]);
}

function set_final_slot(int $pollId, ?int $slotId): void {
    db()->prepare('UPDATE polls SET final_slot_id = ?, updated_at = ? WHERE id = ?')->execute([$slotId, time(), $pollId]);
}

/** True if the poll no longer accepts responses (manual close OR deadline elapsed). */
function poll_is_closed(array $poll): bool {
    if (!empty($poll['closed'])) return true;
    if (!empty($poll['deadline_utc']) && time() > (int)$poll['deadline_utc']) return true;
    return false;
}

function response_count(int $pollId): int {
    $s = db()->prepare('SELECT COUNT(*) FROM participants WHERE poll_id = ?');
    $s->execute([$pollId]);
    return (int)$s->fetchColumn();
}

function participants_for_poll(int $pollId): array {
    $s = db()->prepare('SELECT * FROM participants WHERE poll_id = ? ORDER BY created_at');
    $s->execute([$pollId]);
    return $s->fetchAll();
}

function participant_by_token(int $pollId, string $token): ?array {
    if ($token === '') return null;
    $s = db()->prepare('SELECT * FROM participants WHERE poll_id = ? AND edit_token = ?');
    $s->execute([$pollId, $token]);
    return $s->fetch() ?: null;
}

/** choices: [slot_id => 'yes'|'maybe'|'no']. Returns the participant's edit token. */
function save_response(array $poll, string $name, string $comment, array $choices, string $ip, ?string $editToken): string {
    $db = db();
    $now = time();
    $existing = $editToken ? participant_by_token((int)$poll['id'], $editToken) : null;

    if ($existing) {
        $pid = (int)$existing['id'];
        $token = $existing['edit_token'];
        $db->prepare('UPDATE participants SET name=?, comment=?, updated_at=? WHERE id=?')
           ->execute([$name, $comment, $now, $pid]);
        $db->prepare('DELETE FROM responses WHERE participant_id=?')->execute([$pid]);
        $verb = 'updated their response';
    } else {
        $token = random_token(16);
        $db->prepare('INSERT INTO participants(poll_id, name, comment, edit_token, ip, created_at, updated_at) VALUES(?,?,?,?,?,?,?)')
           ->execute([(int)$poll['id'], $name, $comment, $token, $ip, $now, $now]);
        $pid = (int)$db->lastInsertId();
        $verb = 'responded';
    }

    $valid = ['yes' => 1, 'maybe' => 1, 'no' => 1];
    $ins = $db->prepare('INSERT INTO responses(participant_id, slot_id, choice) VALUES(?,?,?)');
    foreach (slots_for_poll((int)$poll['id']) as $slot) {
        $choice = $choices[(int)$slot['id']] ?? 'no';
        if (!isset($valid[$choice])) $choice = 'no';
        $ins->execute([$pid, (int)$slot['id'], $choice]);
    }
    activity_add((int)$poll['id'], $name . ' ' . $verb);
    return $token;
}

function activity_add(int $pollId, string $message): void {
    db()->prepare('INSERT INTO activity(poll_id, message, created_at) VALUES(?,?,?)')->execute([$pollId, $message, time()]);
}

function activity_for(int $pollId, int $limit = 20): array {
    $s = db()->prepare('SELECT * FROM activity WHERE poll_id = ? ORDER BY created_at DESC, id DESC LIMIT ?');
    $s->bindValue(1, $pollId, PDO::PARAM_INT);
    $s->bindValue(2, $limit, PDO::PARAM_INT);
    $s->execute();
    return $s->fetchAll();
}

/**
 * Per-slot tally and ranking.
 * Returns ['counts'=>[slot_id=>['yes'=>,'maybe'=>,'no'=>]], 'best'=>[slot_id,...], 'total'=>int]
 * Best slot = most Yes, ties broken by most Maybe. Maybe is always ranked below Yes.
 */
function tally(int $pollId): array {
    $slots = slots_for_poll($pollId);
    $counts = [];
    foreach ($slots as $s) $counts[(int)$s['id']] = ['yes' => 0, 'maybe' => 0, 'no' => 0];

    $rows = db()->prepare('SELECT r.slot_id, r.choice FROM responses r
                           JOIN participants p ON p.id = r.participant_id WHERE p.poll_id = ?');
    $rows->execute([$pollId]);
    foreach ($rows as $r) {
        $sid = (int)$r['slot_id'];
        if (isset($counts[$sid][$r['choice']])) $counts[$sid][$r['choice']]++;
    }

    $best = [];
    $bestKey = [-1, -1];
    foreach ($slots as $s) {
        $c = $counts[(int)$s['id']];
        $key = [$c['yes'], $c['maybe']];
        if ($c['yes'] === 0 && $c['maybe'] === 0) continue; // never highlight an empty slot
        if ($key > $bestKey) { $bestKey = $key; $best = [(int)$s['id']]; }
        elseif ($key === $bestKey) { $best[] = (int)$s['id']; }
    }

    return ['counts' => $counts, 'best' => $best, 'total' => response_count($pollId)];
}

/** Server-side fallback label in the organizer's timezone; JS re-renders in the viewer's zone. */
function slot_label(array $slot, string $tz): string {
    if ($slot['kind'] === 'date') {
        $d = DateTime::createFromFormat('Y-m-d', $slot['date']) ?: new DateTime('now');
        return $d->format('D, M j') . ' · all day';
    }
    $dt = (new DateTime('@' . $slot['start_utc']))->setTimezone(new DateTimeZone($tz));
    return $dt->format('D, M j · g:i A');
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Simple per-IP rate limit: max $max writes per $windowSecs. Returns true if allowed.
 *  Default tuned so a legit burst of responders behind one office NAT isn't blocked,
 *  while still stopping automated floods. */
function rate_ok(string $ip, int $max = 30, int $windowSecs = 60): bool {
    $db = db();
    $cut = time() - $windowSecs;
    $db->prepare('DELETE FROM rate WHERE ts < ?')->execute([$cut]);
    $n = $db->prepare('SELECT COUNT(*) FROM rate WHERE ip = ? AND ts >= ?');
    $n->execute([$ip, $cut]);
    if ((int)$n->fetchColumn() >= $max) return false;
    $db->prepare('INSERT INTO rate(ip, ts) VALUES(?, ?)')->execute([$ip, time()]);
    return true;
}
