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
require APP_DIR . '/notify.php'; // add_invites / invites_for_poll (cascade-delete checks)
require APP_DIR . '/controllers/auth.php'; // reset_user_for_token (invite + reset links)
date_default_timezone_set('UTC');
session_start(); // before any output; needed by the keep-input checks below

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

// --- Error log tail: bounded, whole lines, safe on missing files ---
$logf = sys_get_temp_dir() . '/tp_test_log_' . getmypid() . '.log';
file_put_contents($logf, str_repeat("filler line for the tail test\n", 3000)); // ~90KB
$tail = log_tail($logf, 4096);
ok(strlen($tail) > 0 && strlen($tail) <= 4096, 'log_tail is bounded');
ok(str_starts_with($tail, 'filler line'), 'log_tail starts on a whole line');
ok(log_tail($logf . '.nope') === '', 'log_tail of a missing file is empty');
@unlink($logf);

// --- Invite/reset link tokens (organizer invitations reuse the reset flow) ---
$oid = create_user('organizer@test.org', random_token(24), 'Org', 'organizer');
db()->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?')
    ->execute(['tok_valid', time() + 3600, $oid]);
ok((int)(reset_user_for_token('tok_valid')['id'] ?? 0) === $oid, 'valid setup token resolves to the invited user');
ok(reset_user_for_token('tok_wrong') === null, 'unknown token rejected');
ok(reset_user_for_token('') === null, 'empty token rejected');
db()->prepare('UPDATE users SET reset_expires = ? WHERE id = ?')->execute([time() - 1, $oid]);
ok(reset_user_for_token('tok_valid') === null, 'expired token rejected');

// --- Deleting a user cascades to their polls and all poll children ---
$opoll = create_poll($oid, ['title' => 'Org poll', 'organizer_tz' => 'UTC', 'blind' => 0], [
    ['kind' => 'date', 'date' => '2026-08-01'],
]);
add_invites($opoll, ['guest@test.org']);
$oslot = (int)slots_for_poll($opoll)[0]['id'];
save_response(poll_by_id($opoll), 'Zed', '', [$oslot => 'yes'], '2.2.2.2', null);
delete_user($oid);
ok(user_by_email('organizer@test.org') === null, 'deleted user is gone');
ok(poll_by_id($opoll) === null, "deleted user's poll is gone");
ok(slots_for_poll($opoll) === [], 'slots cascade-deleted');
ok(invites_for_poll($opoll) === [], 'invites cascade-deleted');
$c = db()->prepare('SELECT COUNT(*) c FROM participants WHERE poll_id = ?');
$c->execute([$opoll]);
ok((int)$c->fetch()['c'] === 0, 'participants cascade-deleted');
ok(create_user('organizer@test.org', random_token(24), 'Org 2', 'organizer') > 0, 'same email can be re-created after delete');

// --- Keep-input: failed POSTs repopulate forms, secrets never stashed ---
$_POST = ['name' => 'Alice', 'password' => 'hunter22', 'current_password' => 'old22', '_csrf' => 't', 'website' => '', 'slot_9' => 'maybe'];
keep_input();
ok(old('name') === 'Alice', 'old() returns the stashed field');
ok(old('slot_9') === 'maybe', 'old() works for dynamic slot fields');
ok(old('password', 'X') === 'X', 'passwords are never stashed');
ok(old('current_password', 'X') === 'X', 'current password is never stashed');
ok(old('_csrf', 'X') === 'X', 'csrf token is never stashed');
unset($_SESSION['old_input']);
ok(old('name', 'fresh') === 'fresh', 'cleared stash falls back to the default');
$_POST = [];

// --- str_cap: input caps must not depend on ext-mbstring or split UTF-8 ---
ok(str_cap('abcdef', 4) === 'abcd', 'str_cap trims ASCII');
ok(str_cap('héllo', 10) === 'héllo', 'str_cap leaves short strings alone');
ok(str_cap('ééééé', 3) === 'ééé', 'str_cap counts characters, not bytes');

echo "\n$pass passed, $fail failed\n";
@unlink($tmp); @unlink($tmp . '-wal'); @unlink($tmp . '-shm');
exit($fail ? 1 : 0);
