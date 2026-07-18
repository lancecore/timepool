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
require APP_DIR . '/booking.php';
require APP_DIR . '/mailer.php'; // mail_header_safe (subject header-injection guard)
require APP_DIR . '/ics.php';
require APP_DIR . '/notify.php'; // add_invites / invites_for_poll (cascade-delete checks)
require APP_DIR . '/controllers/auth.php'; // reset_user_for_token (invite + reset links)
require APP_DIR . '/controllers/booking.php'; // collect_booking_data (input-clamp checks)
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

// ================= Booking (1:1 scheduling) =================
$epoch = function (string $local, string $tz): int {
    return (new DateTime($local, new DateTimeZone($tz)))->getTimestamp();
};
$starts = function (array $slots): array { return array_column($slots, 'start_utc'); };
$allDays = [];
foreach ([0, 1, 2, 3, 4, 5, 6] as $w) $allDays[$w] = [['09:00', '17:00']];
$genUser = create_user('bookgen@test.org', random_token(12), 'Gen', 'organizer');
$gpage = function (array $over) use ($genUser): array {
    return array_merge([
        'user_id' => $genUser, 'tz' => 'UTC', 'duration_min' => 30, 'availability' => json_encode([]),
        'horizon_days' => 30, 'min_notice_hours' => 0, 'buffer_min' => 0,
    ], $over);
};

// --- DST: wall-clock 09:00 New York → 14:00 UTC in winter, 13:00 UTC in summer ---
$dstPage = $gpage(['tz' => 'America/New_York', 'availability' => json_encode([1 => [['09:00', '17:00']]]), 'horizon_days' => 14]);
$winter = booking_open_slots($dstPage, $epoch('2027-01-01 00:00', 'America/New_York'));
$summer = booking_open_slots($dstPage, $epoch('2027-07-01 00:00', 'America/New_York'));
ok(count($winter) && gmdate('H:i', $winter[0]['start_utc']) === '14:00', 'winter 09:00 EST -> 14:00 UTC');
ok(count($summer) && gmdate('H:i', $summer[0]['start_utc']) === '13:00', 'summer 09:00 EDT -> 13:00 UTC (DST)');

// --- DST transition date: generation crosses spring-forward without crash, stays monotonic ---
$mono = function (array $slots, int $durMin): bool {
    for ($i = 1; $i < count($slots); $i++) {
        if ($slots[$i]['start_utc'] < $slots[$i - 1]['start_utc'] + $durMin * 60) return false;
    }
    return true;
};
$springPage = $gpage(['tz' => 'America/New_York', 'availability' => json_encode($allDays), 'duration_min' => 60, 'horizon_days' => 6]);
$spring = booking_open_slots($springPage, $epoch('2026-03-06 00:00', 'America/New_York')); // DST begins 2026-03-08
ok(count($spring) > 0 && $mono($spring, 60), 'slots across DST transition are strictly increasing, non-overlapping');

// --- Minimum notice + horizon exclusion ---
$nowN = $epoch('2027-02-01 00:00', 'UTC');
$noticePage = $gpage(['availability' => json_encode([0 => [['00:00', '23:30']], 1 => [['00:00', '23:30']], 2 => [['00:00', '23:30']],
    3 => [['00:00', '23:30']], 4 => [['00:00', '23:30']], 5 => [['00:00', '23:30']], 6 => [['00:00', '23:30']]]),
    'min_notice_hours' => 4, 'horizon_days' => 2]);
$nslots = booking_open_slots($noticePage, $nowN);
ok(count($nslots) && $nslots[0]['start_utc'] === $nowN + 4 * 3600, 'earliest slot honors 4h minimum notice');
$maxStart = max($starts($nslots));
ok($maxStart < $nowN + 2 * 86400, 'no slot beyond the 2-day horizon');

// --- Buffer excludes slots around an existing booking ---
$uBuf = create_user('bookbuf@test.org', random_token(12), 'Buf', 'organizer');
$bufId = create_booking_page($uBuf, ['title' => 'Buf', 'description' => '', 'location' => '',
    'duration_min' => 30, 'tz' => 'UTC', 'availability' => $allDays, 'horizon_days' => 1, 'min_notice_hours' => 0, 'buffer_min' => 15]);
$bufPage = booking_page_by_id($bufId);
$nowB = $epoch('2027-02-01 00:00', 'UTC');
db()->prepare('INSERT INTO bookings(page_id,user_id,start_utc,duration_min,name,email,note,manage_token,status,ip,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$bufId, $uBuf, $nowB + 10 * 3600, 30, 'X', 'x@x.org', '', 'buftok', 'active', '', time()]);
$bufSlots = $starts(booking_open_slots($bufPage, $nowB));
ok(!in_array($nowB + 10 * 3600, $bufSlots, true), 'booked start is not offered');
ok(!in_array($nowB + 9 * 3600 + 1800, $bufSlots, true), '09:30 excluded by 15-min buffer around 10:00 booking');
ok(in_array($nowB + 9 * 3600, $bufSlots, true), '09:00 stays open (outside buffer)');

// --- Cross-page conflict: a booking on one page blocks overlapping slots on another ---
$uX = create_user('bookcross@test.org', random_token(12), 'Cross', 'organizer');
$pageA = create_booking_page($uX, ['title' => 'A', 'description' => '', 'location' => '',
    'duration_min' => 30, 'tz' => 'UTC', 'availability' => $allDays, 'horizon_days' => 1, 'min_notice_hours' => 0, 'buffer_min' => 0]);
$pageB = create_booking_page($uX, ['title' => 'B', 'description' => '', 'location' => '',
    'duration_min' => 60, 'tz' => 'UTC', 'availability' => $allDays, 'horizon_days' => 1, 'min_notice_hours' => 0, 'buffer_min' => 0]);
$nowX = $epoch('2027-02-01 00:00', 'UTC');
db()->prepare('INSERT INTO bookings(page_id,user_id,start_utc,duration_min,name,email,note,manage_token,status,ip,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$pageA, $uX, $nowX + 10 * 3600, 30, 'X', 'x@x.org', '', 'crosstok', 'active', '', time()]);
$bSlots = $starts(booking_open_slots(booking_page_by_id($pageB), $nowX));
ok(!in_array($nowX + 10 * 3600, $bSlots, true), 'page B 10:00 slot blocked by page A booking (same organizer)');
ok(in_array($nowX + 9 * 3600, $bSlots, true), 'page B 09:00 still offered');
ok(in_array($nowX + 11 * 3600, $bSlots, true), 'page B 11:00 still offered');

// --- Database-level double-booking rejection (partial unique index) ---
$uD = create_user('bookdbl@test.org', random_token(12), 'Dbl', 'organizer');
$dblId = create_booking_page($uD, ['title' => 'D', 'description' => '', 'location' => '',
    'duration_min' => 30, 'tz' => 'UTC', 'availability' => $allDays, 'horizon_days' => 30, 'min_notice_hours' => 0, 'buffer_min' => 0]);
$dblStart = $epoch('2027-03-01 10:00', 'UTC');
$insBooking = function (int $page, int $user, int $start, string $tok, string $status): void {
    db()->prepare('INSERT INTO bookings(page_id,user_id,start_utc,duration_min,name,email,note,manage_token,status,ip,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$page, $user, $start, 30, 'X', 'x@x.org', '', $tok, $status, '', time()]);
};
$insBooking($dblId, $uD, $dblStart, 'dbl1', 'active');
$rejected = false;
try { $insBooking($dblId, $uD, $dblStart, 'dbl2', 'active'); } catch (PDOException $e) { $rejected = true; }
ok($rejected, 'DB rejects a second active booking at the same organizer+start');
db()->prepare("UPDATE bookings SET status='cancelled' WHERE manage_token='dbl1'")->execute();
$rebooked = true;
try { $insBooking($dblId, $uD, $dblStart, 'dbl3', 'active'); } catch (PDOException $e) { $rebooked = false; }
ok($rebooked, 'same start bookable again once the conflicting booking is cancelled');

// --- book_slot end-to-end + cancellation reopens the slot ---
$uC = create_user('bookcancel@test.org', random_token(12), 'Cancel', 'organizer');
$cancId = create_booking_page($uC, ['title' => 'C', 'description' => '', 'location' => '',
    'duration_min' => 30, 'tz' => 'UTC', 'availability' => $allDays, 'horizon_days' => 30, 'min_notice_hours' => 0, 'buffer_min' => 0]);
$cancPage = booking_page_by_id($cancId);
$open0 = booking_open_slots($cancPage);
ok(count($open0) > 0, 'open slots generated for booking');
$target = $open0[0]['start_utc'];
$res = book_slot($cancPage, $target, 'Zoe', 'zoe@test.org', 'hi', '9.9.9.9');
ok(!empty($res['ok']), 'book_slot succeeds on an open slot');
ok(!in_array($target, $starts(booking_open_slots($cancPage)), true), 'booked slot disappears from open slots');
$dup = book_slot($cancPage, $target, 'Rob', 'rob@test.org', '', '9.9.9.8');
ok(empty($dup['ok']) && $dup['reason'] === 'taken', 're-submitting the same slot is refused (taken)');
$bk = booking_by_token($res['token']);
ok(cancel_booking($bk) === true, 'cancel_booking cancels an active booking');
ok(cancel_booking(booking_by_token($res['token'])) === false, 'repeat cancel is a friendly no-op');
ok(in_array($target, $starts(booking_open_slots($cancPage)), true), 'cancelled slot reopens');

// --- book_slot on a paused page returns reason 'paused' (never throws) ---
$uPz = create_user('bookpause@test.org', random_token(12), 'Pz', 'organizer');
$pzId = create_booking_page($uPz, ['title' => 'Pz', 'description' => '', 'location' => '',
    'duration_min' => 30, 'tz' => 'UTC', 'availability' => $allDays, 'horizon_days' => 30, 'min_notice_hours' => 0, 'buffer_min' => 0]);
set_booking_page_paused($pzId, true);
$pzPage = booking_page_by_id($pzId);
$pzRes = book_slot($pzPage, time() + 86400, 'P', 'p@test.org', '', '3.3.3.3');
ok(empty($pzRes['ok']) && $pzRes['reason'] === 'paused', 'book_slot on a paused page returns reason paused');

// --- Organizer numeric inputs are clamped server-side (horizon-loop DoS guard, etc.) ---
$_POST = ['title' => 'Clamp', 'duration' => 'custom', 'duration_custom' => '99999',
    'horizon_days' => '10000000', 'min_notice_hours' => '999999', 'buffer_min' => '999999', 'tz' => 'UTC'];
$cd = collect_booking_data();
$_POST = [];
ok($cd['horizon_days'] === 365, 'horizon_days clamped to 365');
ok($cd['duration_min'] === 1440, 'custom duration clamped to 1440 minutes');
ok($cd['min_notice_hours'] === 8760 && $cd['buffer_min'] === 1440, 'notice/buffer clamped to sane maxima');

// --- Blocked date excludes all slots on that date, on any page of the organizer ---
$uBl = create_user('bookblock@test.org', random_token(12), 'Block', 'organizer');
$blId = create_booking_page($uBl, ['title' => 'Bl', 'description' => '', 'location' => '',
    'duration_min' => 30, 'tz' => 'UTC', 'availability' => $allDays, 'horizon_days' => 10, 'min_notice_hours' => 0, 'buffer_min' => 0]);
$blPage = booking_page_by_id($blId);
$nowBl = $epoch('2027-02-01 00:00', 'UTC');
$hasDate = function (array $slots, string $day): bool {
    foreach ($slots as $s) if (gmdate('Y-m-d', $s['start_utc']) === $day) return true;
    return false;
};
ok($hasDate(booking_open_slots($blPage, $nowBl), '2027-02-02'), 'slots exist on 2027-02-02 before blocking');
ok(add_blocked_date($uBl, '2027-02-02'), 'blocked date added');
ok(!$hasDate(booking_open_slots($blPage, $nowBl), '2027-02-02'), 'no slots on a blocked date');

// --- Public view timezone: [tz, requested]. The 2nd element is the sentinel key the view uses:
//     the JS auto-detect redirect is emitted ONLY when requested===false (no tz param at all), so
//     a request WITH a tz param (valid OR garbage) can never re-trigger it → no infinite loop. ---
$tzPage = ['tz' => 'America/New_York'];
$_GET['tz'] = 'Europe/London';
ok(booking_view_tz($tzPage) === ['Europe/London', true], 'valid ?tz resolves and marks requested (no sentinel)');
$_GET['tz'] = 'Not/AZone';
ok(booking_view_tz($tzPage) === ['America/New_York', true], 'garbage ?tz falls back to page tz but stays requested (no sentinel → no loop)');
$_GET['tz'] = '';
ok(booking_view_tz($tzPage) === ['America/New_York', false], 'no tz param: page tz + requested=false (sentinel emitted)');
unset($_GET['tz']);
ok(booking_view_tz($tzPage) === ['America/New_York', false], 'absent tz param behaves like empty (requested=false)');

// --- Recent-rename aliasing: a viewer on a zone this tzdata lacks still gets correct local time ---
$aliasPage = ['tz' => 'UTC'];
$dtzOk = function (string $z): bool { try { new DateTimeZone($z); return true; } catch (Exception $e) { return false; } };
$_GET['tz'] = 'Europe/Kyiv';
list($az) = booking_view_tz($aliasPage);
ok($az !== 'UTC' && $dtzOk($az) && in_array($az, timezone_identifiers_list(), true), 'Europe/Kyiv aliases to a usable zone (not page-tz fallback)');
$_GET['tz'] = 'Europe/Kiev';
list($az2) = booking_view_tz($aliasPage);
ok($az2 !== 'UTC' && $dtzOk($az2), 'Kyiv/Kiev resolves whichever name local tzdata knows');
$_GET['tz'] = 'America/Ciudad_Juarez'; // geographic split, deliberately NOT aliased
ok(in_array('America/Ciudad_Juarez', timezone_identifiers_list(), true) || booking_view_tz($aliasPage)[0] === 'UTC', 'a non-rename split falls back to page tz (only aliased when tzdata already knows it)');
// Loop-safety invariant, pinned as a pair: a PRESENT but UNRESOLVABLE ?tz must read as
// requested=true (view emits no auto-detect sentinel) AND resolve to null (controller emits
// no store script) — "neither script", so a rejected zone can never re-trigger the redirect.
$_GET['tz'] = 'Not/AZone';
ok(booking_view_tz($aliasPage) === ['UTC', true] && booking_resolve_tz('Not/AZone') === null,
   'unresolvable ?tz pins requested=true + resolved=null together (neither script emitted)');
unset($_GET['tz']);

$tzSlot = [['kind' => 'datetime', 'start_utc' => $epoch('2027-02-01 02:00', 'UTC'), 'duration_min' => 30]];
$grpNY = booking_group_by_day($tzSlot, 'America/New_York'); // 2027-01-31 21:00 local
$grpTk = booking_group_by_day($tzSlot, 'Asia/Tokyo');       // 2027-02-01 11:00 local
ok(array_keys($grpNY) === ['2027-01-31'] && array_keys($grpTk) === ['2027-02-01'], 'day grouping re-renders in the chosen timezone');
ok(strpos(slot_label($tzSlot[0], 'Asia/Tokyo'), '11:00 AM') !== false, 'slot label re-renders wall-clock in the chosen timezone');

// --- Mail subject header-injection guard (root fix in send_mail via mail_header_safe) ---
ok(mail_header_safe("Booking: Lunch\r\nBcc: evil@example.org") === 'Booking: Lunch Bcc: evil@example.org', 'CR/LF stripped from a mail subject (no header injection)');
ok(mail_header_safe("a\nb\rc") === 'a b c', 'CR and LF runs collapse to a single space');
ok(mail_header_safe('Plain subject') === 'Plain subject', 'a normal subject is unchanged');

echo "\n$pass passed, $fail failed\n";
@unlink($tmp); @unlink($tmp . '-wal'); @unlink($tmp . '-shm');
exit($fail ? 1 : 0);
