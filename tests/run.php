<?php
declare(strict_types=1);
/** TimePool self-checks. Run: php tests/run.php */
error_reporting(E_ALL);

define('ROOT_DIR', dirname(__DIR__));
define('APP_DIR', ROOT_DIR . '/app');
define('DATA_DIR', sys_get_temp_dir());

$tmp = sys_get_temp_dir() . '/tp_test_' . getmypid() . '.sqlite';
@unlink($tmp);
$GLOBALS['config'] = ['db' => $tmp, 'pretty' => true];

require APP_DIR . '/helpers.php';
require APP_DIR . '/db.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/poll.php';
require APP_DIR . '/ics.php';
date_default_timezone_set('UTC');

$pass = 0; $fail = 0;
function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \xE2\x9C\x93 $msg\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 $msg\n"; }
}

// --- Timezone: store absolute UTC, survive DST ---
ok(gmdate('H:i', local_to_utc('2026-01-15 14:00', 'America/New_York')) === '19:00', 'EST 2pm -> 19:00 UTC');
ok(gmdate('H:i', local_to_utc('2026-07-15 14:00', 'America/New_York')) === '18:00', 'EDT 2pm -> 18:00 UTC (DST)');

// --- Poll + slots ---
$uid = create_user('admin@test.org', 'password123', 'Admin', 'admin');
ok($uid > 0, 'admin user created');
$pollId = create_poll($uid, ['title' => 'Test', 'organizer_tz' => 'America/New_York', 'blind' => 0], [
    ['kind' => 'datetime', 'date' => '2026-07-15', 'time' => '14:00', 'duration' => 60],
    ['kind' => 'datetime', 'date' => '2026-07-16', 'time' => '10:00', 'duration' => 30],
    ['kind' => 'date', 'date' => '2026-07-17'],
]);
$poll = poll_by_id($pollId);
$slots = slots_for_poll($pollId);
ok(count($slots) === 3, '3 slots created');
ok($slots[2]['kind'] === 'date', 'all-day slot stored as date kind');
$s0 = (int)$slots[0]['id']; $s1 = (int)$slots[1]['id']; $s2 = (int)$slots[2]['id'];

// --- Responses + ranking (Maybe ranked below Yes) ---
save_response($poll, 'Alice', '', [$s0 => 'yes', $s1 => 'no', $s2 => 'maybe'], '1.1.1.1', null);
save_response($poll, 'Bob', '', [$s0 => 'yes', $s1 => 'yes', $s2 => 'no'], '1.1.1.2', null);
$carolTok = save_response($poll, 'Carol', '', [$s0 => 'yes', $s1 => 'maybe', $s2 => 'yes'], '1.1.1.3', null);
$t = tally($pollId);
ok($t['counts'][$s0]['yes'] === 3, 'slot0 has 3 Yes');
ok($t['best'] === [$s0], 'slot0 is the single best slot');
ok($t['total'] === 3, '3 participants');

// --- Edit token: update does not create a duplicate participant ---
$carolTok2 = save_response($poll, 'Carol', 'changed', [$s0 => 'no', $s1 => 'no', $s2 => 'no'], '1.1.1.3', $carolTok);
ok($carolTok2 === $carolTok, 'edit token stable across update');
$t = tally($pollId);
ok($t['total'] === 3, 'still 3 participants after edit (no duplicate)');
ok($t['counts'][$s0]['yes'] === 2, 'slot0 Yes dropped to 2 after Carol changed');
ok(participant_by_token($pollId, $carolTok)['comment'] === 'changed', 'comment updated via edit token');

// --- Deadline auto-close ---
ok(poll_is_closed(['closed' => 0, 'deadline_utc' => time() + 3600]) === false, 'future deadline = open');
ok(poll_is_closed(['closed' => 0, 'deadline_utc' => time() - 10]) === true, 'past deadline auto-closes');
ok(poll_is_closed(['closed' => 1, 'deadline_utc' => null]) === true, 'manual close = closed');

// --- ICS ---
$ics = ics_for_slot($poll, $slots[0]);
ok(str_contains($ics, 'BEGIN:VEVENT') && str_contains($ics, 'DTSTART:' . gmdate('Ymd\THis\Z', (int)$slots[0]['start_utc'])), 'timed ICS DTSTART matches stored UTC');
ok(str_contains(ics_for_slot($poll, $slots[2]), 'DTSTART;VALUE=DATE:20260717'), 'all-day ICS uses VALUE=DATE');

// --- Base-path aware URLs (subdomain root, subfolder, no-rewrite fallback) ---
$_SERVER['SCRIPT_NAME'] = '/index.php';
ok(url('/dashboard') === '/dashboard', 'root install: clean url');
$_SERVER['SCRIPT_NAME'] = '/meet/index.php';
ok(url('/dashboard') === '/meet/dashboard', 'subfolder install: prefixed url');
$GLOBALS['config']['pretty'] = false;
ok(url('/dashboard') === '/meet/index.php?r=%2Fdashboard', 'no mod_rewrite: query-string fallback');
// Query params (reset token, ics slot) must survive the no-rewrite fallback.
ok(url('/reset?token=abc') === '/meet/index.php?r=%2Freset&token=abc', 'no-rewrite: ?token kept as real param');
ok(url('/p/TOK/ics?slot=final') === '/meet/index.php?r=%2Fp%2FTOK%2Fics&slot=final', 'no-rewrite: ?slot kept as real param');
$GLOBALS['config']['pretty'] = true;
ok(url('/reset?token=abc') === '/meet/reset?token=abc', 'pretty: ?token preserved');

echo "\n$pass passed, $fail failed\n";
@unlink($tmp); @unlink($tmp . '-wal'); @unlink($tmp . '-shm');
exit($fail ? 1 : 0);
