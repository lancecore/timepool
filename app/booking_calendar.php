<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 * Calendar booking pages: date-specific availability windows placed
 * week by week, per-page blocked dates (both page types), and the
 * copy-previous-week bulk tool. Extends app/booking.php (loaded first);
 * the slot engine there branches on booking_is_calendar() and consults
 * page_blocks_set() / booking_windows_day_map() defined here.
 * ------------------------------------------------------------------ */

/** A page is a calendar page when its type is 'calendar' (default/legacy is 'weekly'). */
function booking_is_calendar(array $page): bool {
    return (string)($page['type'] ?? 'weekly') === 'calendar';
}

/** Today's date ('Y-m-d') in the page's own timezone — the boundary for "past". */
function booking_today(array $page, ?int $now = null): string {
    $now = $now ?? time();
    return (new DateTime('@' . $now))->setTimezone(new DateTimeZone((string)$page['tz']))->format('Y-m-d');
}

/* ---------------- Week arithmetic (Mon-first, matches booking_weekdays) ---------------- */

/** Shift a 'Y-m-d' date by whole days. UTC keeps it pure date math (no DST hour drift). */
function booking_shift_date(string $dateStr, int $days): string {
    $d = new DateTime($dateStr . ' 00:00:00', new DateTimeZone('UTC'));
    $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
    return $d->format('Y-m-d');
}

/** The Monday ('Y-m-d') of the week containing $dateStr. */
function booking_week_monday(string $dateStr): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) $dateStr = gmdate('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $dateStr, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $dateStr) $dateStr = gmdate('Y-m-d');
    $dow = (int)(new DateTime($dateStr, new DateTimeZone('UTC')))->format('N'); // 1=Mon..7=Sun
    return $dow > 1 ? booking_shift_date($dateStr, -($dow - 1)) : $dateStr;
}

/** The seven 'Y-m-d' dates of the week starting at $mondayStr, Monday..Sunday. */
function booking_week_dates(string $mondayStr): array {
    $out = [];
    for ($i = 0; $i < 7; $i++) $out[] = booking_shift_date($mondayStr, $i);
    return $out;
}

/* ---------------- Calendar windows ---------------- */

function booking_windows_for_page(int $pageId): array {
    $s = db()->prepare('SELECT * FROM booking_windows WHERE page_id = ? ORDER BY day, start_hm');
    $s->execute([$pageId]);
    return $s->fetchAll();
}

/** All placed windows as ['Y-m-d' => [[start,end],...]], ranges sorted by start. */
function booking_windows_day_map(int $pageId): array {
    $out = [];
    foreach (booking_windows_for_page($pageId) as $w) {
        $out[$w['day']][] = [(string)$w['start_hm'], (string)$w['end_hm']];
    }
    foreach ($out as &$ranges) {
        usort($ranges, function ($a, $b) { return strcmp($a[0], $b[0]); });
    }
    unset($ranges);
    return $out;
}

/** Replace one date's windows with $ranges ([[start,end],...]); empty $ranges just clears the date. */
function booking_set_date_windows(int $pageId, string $day, array $ranges): void {
    $db = db();
    $db->prepare('DELETE FROM booking_windows WHERE page_id = ? AND day = ?')->execute([$pageId, $day]);
    if (!$ranges) return;
    $ins = $db->prepare('INSERT INTO booking_windows(page_id, day, start_hm, end_hm, created_at) VALUES(?,?,?,?,?)');
    $now = time();
    foreach ($ranges as $r) {
        $ins->execute([$pageId, $day, (string)$r[0], (string)$r[1], $now]);
    }
}

/** Save the displayed week: replace each editable (non-past) date's windows from $dateRanges. */
function set_week_windows(int $pageId, array $editableDates, array $dateRanges): void {
    foreach ($editableDates as $day) {
        booking_set_date_windows($pageId, $day, $dateRanges[$day] ?? []);
    }
}

/**
 * Copy the week before $targetMonday onto that week, replacing its windows entirely. Refuses when the
 * source week is empty (returns ['ok'=>false]); past target dates (< $todayStr) are skipped silently.
 */
function copy_previous_week(int $pageId, string $targetMonday, string $todayStr): array {
    $target = booking_week_dates($targetMonday);
    $source = booking_week_dates(booking_shift_date($targetMonday, -7));
    $map = booking_windows_day_map($pageId);

    $sourceHasAny = false;
    foreach ($source as $sd) { if (!empty($map[$sd])) { $sourceHasAny = true; break; } }
    if (!$sourceHasAny) return ['ok' => false, 'reason' => 'empty'];

    foreach ($target as $i => $td) {
        if ($td < $todayStr) continue; // past target dates get nothing, without error
        booking_set_date_windows($pageId, $td, $map[$source[$i]] ?? []);
    }
    return ['ok' => true];
}

/* ---------------- Per-page blocked dates (both types) ---------------- */

function page_blocks_for_page(int $pageId): array {
    $s = db()->prepare('SELECT * FROM booking_page_blocks WHERE page_id = ? ORDER BY day');
    $s->execute([$pageId]);
    return $s->fetchAll();
}

/** Set of blocked 'Y-m-d' => true for this page, for fast lookup during slot generation. */
function page_blocks_set(int $pageId): array {
    $set = [];
    foreach (page_blocks_for_page($pageId) as $b) $set[$b['day']] = true;
    return $set;
}

function add_page_block(int $pageId, string $day): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $day);
    if (!$d || $d->format('Y-m-d') !== $day) return false;
    try {
        db()->prepare('INSERT INTO booking_page_blocks(page_id, day, created_at) VALUES(?,?,?)')
            ->execute([$pageId, $day, time()]);
    } catch (PDOException $e) {
        return false; // duplicate (unique index) — already blocked, treat as no-op
    }
    return true;
}

function remove_page_block(int $pageId, int $id): void {
    db()->prepare('DELETE FROM booking_page_blocks WHERE id = ? AND page_id = ?')->execute([$id, $pageId]);
}
