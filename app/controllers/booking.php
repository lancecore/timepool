<?php
declare(strict_types=1);

/* Controllers for 1:1 booking pages. Admin side is login-gated; the /b and /m
 * routes are public (no account) and mirror the poll public flow. */

/** Resolve a booking page the current user may manage; 404/403 otherwise. */
function own_booking_page(string $id): array {
    $u = require_login();
    $page = booking_page_by_id((int)$id);
    // One generic 404 whether the page is missing or simply not this user's — avoids an
    // existence oracle over sequential IDs.
    if (!$page || ((int)$page['user_id'] !== (int)$u['id'] && ($u['role'] ?? '') !== 'admin')) {
        http_response_code(404);
        view('error', ['title' => 'Not found', 'code' => 404, 'message' => 'Booking page not found.'], 'public');
        exit;
    }
    return [$u, $page];
}

/** Gather + normalise the booking-page form fields (does not validate). */
function collect_booking_data(): array {
    $tz = trim((string)param('tz', ''));
    if (!in_array($tz, timezone_identifiers_list(), true)) $tz = (string)setting('default_tz', 'UTC');
    if ($tz === '') $tz = 'UTC';

    $durSel = (string)param('duration', '30');
    $duration = $durSel === 'custom' ? (int)param('duration_custom', 0) : (int)$durSel;
    if ($duration > 1440) $duration = 1440; // cap at 24h; non-positive is left for validation to reject

    $h = trim((string)param('horizon_days', ''));
    $n = trim((string)param('min_notice_hours', ''));
    $b = trim((string)param('buffer_min', ''));

    // Type is chosen at creation and is immutable afterwards; callers editing a page overwrite this
    // with the page's stored type before validating. Calendar pages have no weekly template.
    $type = in_array(param('type'), ['weekly', 'calendar'], true) ? (string)param('type') : 'weekly';

    // Clamp both bounds server-side: the form maxes are client-only, and an unbounded
    // horizon would make every public GET loop that many days (self-service DoS).
    return [
        'title'            => trim((string)param('title', '')),
        'description'      => trim((string)param('description', '')),
        'location'         => trim((string)param('location', '')),
        'duration_min'     => $duration,
        'tz'               => $tz,
        'type'             => $type,
        'availability'     => $type === 'calendar' ? [] : collect_availability(),
        'horizon_days'     => $h === '' ? 60 : min(365, max(1, (int)$h)),
        'min_notice_hours' => $n === '' ? 4 : min(8760, max(0, (int)$n)),
        'buffer_min'       => $b === '' ? 0 : min(1440, max(0, (int)$b)),
    ];
}

/** Read the per-weekday time ranges from the form into [w => [[start,end],...]]. */
function collect_availability(): array {
    $raw = (array)($_POST['avail'] ?? []);
    $avail = [];
    foreach (booking_weekdays() as $wd) {
        $w = $wd['w'];
        $starts = (array)($raw[$w]['start'] ?? []);
        $ends   = (array)($raw[$w]['end'] ?? []);
        $ranges = [];
        foreach ($starts as $i => $st) {
            $st = trim((string)$st);
            $en = trim((string)($ends[$i] ?? ''));
            if ($st === '' && $en === '') continue;
            $ranges[] = [$st, $en];
        }
        if ($ranges) $avail[$w] = $ranges;
    }
    return $avail;
}

function validate_booking_data(array $d): ?string {
    if ($d['title'] === '') return 'Please give the booking page a title.';
    if ($d['duration_min'] <= 0) return 'Please choose a positive meeting duration.';
    // Calendar pages carry no weekly template — their availability lives in date windows edited
    // week by week after creation, so the weekly-range checks below don't apply.
    if (($d['type'] ?? 'weekly') === 'calendar') return null;
    $hasRange = false;
    $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
    foreach ($d['availability'] as $ranges) {
        $mins = [];
        foreach ($ranges as $r) {
            $hasRange = true;
            if (!preg_match($re, $r[0]) || !preg_match($re, $r[1])) return 'Please enter valid start and end times (HH:MM).';
            $sm = booking_hm_to_min($r[0]);
            $em = booking_hm_to_min($r[1]);
            if ($em <= $sm) return 'Each availability end time must be after its start time.';
            $mins[] = [$sm, $em];
        }
        usort($mins, function ($a, $b) { return $a[0] <=> $b[0]; });
        for ($i = 1; $i < count($mins); $i++) {
            if ($mins[$i][0] < $mins[$i - 1][1]) return 'Availability ranges within a day must not overlap.';
        }
    }
    if (!$hasRange) return 'Add at least one availability range so people can book a time.';
    return null;
}

/* ---------------- Admin: pages, bookings, days off ---------------- */

function booking_index(): void {
    $u = require_login();
    view('booking_index', [
        'title' => 'Booking pages',
        'me' => $u,
        'pages' => booking_pages_for_user((int)$u['id']),
        'bookings' => bookings_for_user((int)$u['id']),
        'daysoff' => blocked_dates_for_user((int)$u['id']),
    ]);
}

function booking_new(): void {
    require_login();
    view('booking_form', ['title' => 'New booking page', 'page' => null]);
}

/**
 * View data for the edit form. Both types get their per-page blocked dates; calendar pages also get
 * the displayed week (from ?week, snapped to Monday), that week's placed windows, and today's date in
 * the page tz. Pass $weekWindows to re-show just-submitted (invalid) input instead of the stored rows.
 */
function booking_form_vars(array $page, ?string $week = null, ?array $weekWindows = null): array {
    $vars = [
        'title'  => 'Edit booking page',
        'page'   => $page,
        'blocks' => page_blocks_for_page((int)$page['id']),
    ];
    if (booking_is_calendar($page)) {
        $today = booking_today($page);
        $week = booking_week_monday($week ?? (string)param('week', $today));
        $vars['today'] = $today;
        $vars['week'] = $week;
        $vars['weekWindows'] = $weekWindows
            ?? array_intersect_key(booking_windows_day_map((int)$page['id']), array_flip(booking_week_dates($week)));
    }
    return $vars;
}

function booking_create(): void {
    $u = require_login();
    csrf_check();
    $data = collect_booking_data();
    $err = validate_booking_data($data);
    if ($err) {
        keep_input();
        flash($err, 'error');
        view('booking_form', ['title' => 'New booking page', 'page' => array_merge($data, ['id' => 0])]);
        return;
    }
    $id = create_booking_page((int)$u['id'], $data);
    flash('Booking page created. Share the link below for people to book you.', 'success');
    redirect('/booking/' . $id . '/edit');
}

function booking_edit(string $id): void {
    [, $page] = own_booking_page($id);
    view('booking_form', booking_form_vars($page));
}

function booking_update(string $id): void {
    [, $page] = own_booking_page($id);
    csrf_check();
    $data = collect_booking_data();
    $data['type'] = (string)$page['type']; // type is fixed at creation; never changed by an edit
    $err = validate_booking_data($data);
    if ($err) {
        keep_input();
        flash($err, 'error');
        view('booking_form', booking_form_vars(array_merge($page, $data)));
        return;
    }
    update_booking_page((int)$page['id'], $data);
    flash('Booking page updated. Existing bookings keep their original time.', 'success');
    redirect('/booking/' . $page['id'] . '/edit');
}

/**
 * Organizer month calendar for one page: every active booking (past and future) placed on its
 * date, plus per-day open-slot counts, day-off/blocked markers, and month navigation. Days are
 * bucketed in the page's own timezone so they match the week editor and the public page.
 */
function booking_month(string $id): void {
    [, $page] = own_booking_page($id);
    $tz = new DateTimeZone((string)$page['tz']);
    $today = booking_today($page);

    $m = (string)param('month', substr($today, 0, 7));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = substr($today, 0, 7);
    $weeks = booking_month_weeks($m . '-01');

    // Grid bounds in UTC: first shown day 00:00 through the day after the last shown day 00:00.
    $fromUtc = (new DateTime($weeks[0][0] . ' 00:00:00', $tz))->getTimestamp();
    $toUtc = (new DateTime(booking_shift_date($weeks[count($weeks) - 1][6], 1) . ' 00:00:00', $tz))->getTimestamp();

    $booked = [];
    foreach (bookings_for_page_between((int)$page['id'], $fromUtc, $toUtc) as $b) {
        $d = (new DateTime('@' . $b['start_utc']))->setTimezone($tz);
        $booked[$d->format('Y-m-d')][] = ['time' => $d->format('g:i A'), 'b' => $b];
    }

    $openCount = [];
    foreach (booking_open_slots($page) as $s) {
        $day = (new DateTime('@' . $s['start_utc']))->setTimezone($tz)->format('Y-m-d');
        $openCount[$day] = ($openCount[$day] ?? 0) + 1;
    }

    view('booking_month', [
        'title' => 'Calendar · ' . $page['title'],
        'page' => $page,
        'month' => $m,
        'weeks' => $weeks,
        'today' => $today,
        'booked' => $booked,
        'openCount' => $openCount,
        'blockedOrg' => blocked_dates_set((int)$page['user_id']),
        'blockedPage' => page_blocks_set((int)$page['id']),
    ]);
}

/* ---------------- Calendar pages: week editor, copy-week, per-page blocks ---------------- */

/** Read the week editor's date windows from the form into ['Y-m-d' => [[start,end],...]]. */
function collect_week_windows(): array {
    $raw = (array)($_POST['win'] ?? []);
    $out = [];
    foreach ($raw as $day => $fields) {
        $day = (string)$day;
        $starts = (array)($fields['start'] ?? []);
        $ends   = (array)($fields['end'] ?? []);
        $ranges = [];
        foreach ($starts as $i => $st) {
            $st = trim((string)$st);
            $en = trim((string)($ends[$i] ?? ''));
            if ($st === '' && $en === '') continue;
            $ranges[] = [$st, $en];
        }
        if ($ranges) $out[$day] = $ranges;
    }
    return $out;
}

/** Validate submitted week windows. Same HH:MM / end>start / no-overlap rules as weekly pages,
 *  plus a refusal to place availability on a date already in the past ($todayStr, page tz). */
function validate_week_windows(array $dateRanges, string $todayStr): ?string {
    $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
    foreach ($dateRanges as $day => $ranges) {
        if (!$ranges) continue;
        if ((string)$day < $todayStr) return 'Availability cannot be added to a date in the past.';
        $mins = [];
        foreach ($ranges as $r) {
            if (!preg_match($re, $r[0]) || !preg_match($re, $r[1])) return 'Please enter valid start and end times (HH:MM).';
            $sm = booking_hm_to_min($r[0]);
            $em = booking_hm_to_min($r[1]);
            if ($em <= $sm) return 'Each availability end time must be after its start time.';
            $mins[] = [$sm, $em];
        }
        usort($mins, function ($a, $b) { return $a[0] <=> $b[0]; });
        for ($i = 1; $i < count($mins); $i++) {
            if ($mins[$i][0] < $mins[$i - 1][1]) return 'Availability ranges within a day must not overlap.';
        }
    }
    return null;
}

function booking_save_week(string $id): void {
    [, $page] = own_booking_page($id);
    csrf_check();
    if (!booking_is_calendar($page)) { redirect('/booking/' . $page['id'] . '/edit'); }
    $today = booking_today($page);
    $week = booking_week_monday((string)param('week', $today));
    $ranges = collect_week_windows();
    $err = validate_week_windows($ranges, $today);
    if ($err) {
        // Re-render (no redirect) so the just-typed times survive; the settings form keeps its
        // stored values via old()'s fallback since this POST carried none of those fields.
        flash($err, 'error');
        $shown = array_intersect_key($ranges, array_flip(booking_week_dates($week)));
        view('booking_form', booking_form_vars($page, $week, $shown));
        return;
    }
    // Replace exactly the displayed week's non-past dates (emptied dates clear); past dates untouched.
    $editable = array_values(array_filter(booking_week_dates($week), function ($d) use ($today) { return $d >= $today; }));
    set_week_windows((int)$page['id'], $editable, $ranges);
    flash('Availability saved for the week of ' . $week . '.', 'success');
    redirect('/booking/' . $page['id'] . '/edit?week=' . $week);
}

function booking_copy_week_confirm(string $id): void {
    [, $page] = own_booking_page($id);
    if (!booking_is_calendar($page)) { redirect('/booking/' . $page['id'] . '/edit'); }
    $today = booking_today($page);
    $week = booking_week_monday((string)param('week', $today));
    $src = booking_shift_date($week, -7);
    $map = booking_windows_day_map((int)$page['id']);
    $srcHas = false;
    foreach (booking_week_dates($src) as $sd) { if (!empty($map[$sd])) { $srcHas = true; break; } }
    if (!$srcHas) {
        flash('The previous week has no availability to copy.', 'error');
        redirect('/booking/' . $page['id'] . '/edit?week=' . $week);
    }
    view('booking_copy_confirm', ['title' => 'Copy previous week', 'page' => $page, 'week' => $week, 'src' => $src]);
}

function booking_copy_week_do(string $id): void {
    [, $page] = own_booking_page($id);
    csrf_check();
    if (!booking_is_calendar($page)) { redirect('/booking/' . $page['id'] . '/edit'); }
    $today = booking_today($page);
    $week = booking_week_monday((string)param('week', $today));
    $res = copy_previous_week((int)$page['id'], $week, $today);
    if (empty($res['ok'])) flash('The previous week has no availability to copy.', 'error');
    else flash('Copied the previous week onto the week of ' . $week . '.', 'success');
    redirect('/booking/' . $page['id'] . '/edit?week=' . $week);
}

function booking_block_add(string $id): void {
    [, $page] = own_booking_page($id);
    csrf_check();
    $day = trim((string)param('day', ''));
    if (add_page_block((int)$page['id'], $day)) flash('Date blocked for this page only.', 'success');
    else flash('Please pick a valid date.', 'error');
    redirect('/booking/' . $page['id'] . '/edit');
}

function booking_block_remove(string $id, string $blockId): void {
    [, $page] = own_booking_page($id);
    csrf_check();
    remove_page_block((int)$page['id'], (int)$blockId);
    flash('Date unblocked for this page.', 'success');
    redirect('/booking/' . $page['id'] . '/edit');
}

function booking_pause(string $id): void {
    [, $page] = own_booking_page($id);
    csrf_check();
    $paused = param('paused') === '1';
    set_booking_page_paused((int)$page['id'], $paused);
    flash($paused ? 'Booking page paused. It no longer accepts bookings.' : 'Booking page resumed.', 'success');
    redirect('/booking');
}

function booking_delete(string $id): void {
    [, $page] = own_booking_page($id);
    csrf_check();
    if (page_upcoming_active_count((int)$page['id']) > 0) {
        flash('This page has upcoming bookings. Cancel them first, then delete the page.', 'error');
        redirect('/booking');
    }
    delete_booking_page((int)$page['id']);
    flash('Booking page deleted.', 'success');
    redirect('/booking');
}

function booking_org_cancel(string $id): void {
    $u = require_login();
    csrf_check();
    $b = booking_by_id((int)$id);
    $page = $b ? booking_page_by_id((int)$b['page_id']) : null;
    $owned = $b && $page && ((int)$page['user_id'] === (int)$u['id'] || ($u['role'] ?? '') === 'admin');
    // Same response whether the booking is missing or belongs to another organizer — no oracle.
    if (!$owned) { flash('That booking could not be found.', 'error'); redirect('/booking'); }
    if (cancel_booking($b)) {
        notify_booking_cancelled($page, $b, 'organizer');
        flash('Booking cancelled. The slot is open again.', 'success');
    } else {
        flash('That booking was already cancelled.', 'success');
    }
    redirect('/booking');
}

function daysoff_add(): void {
    $u = require_login();
    csrf_check();
    $day = trim((string)param('day', ''));
    if (add_blocked_date((int)$u['id'], $day)) flash('Day off added. No slots will be offered on ' . $day . '.', 'success');
    else flash('Please pick a valid date.', 'error');
    redirect('/booking');
}

function daysoff_remove(string $id): void {
    $u = require_login();
    csrf_check();
    remove_blocked_date((int)$u['id'], (int)$id);
    flash('Day off removed.', 'success');
    redirect('/booking');
}

/* ---------------- Public: booking flow ---------------- */

function load_public_page(string $token): array {
    $page = booking_page_by_token($token);
    if (!$page) { http_response_code(404); view('error', ['title' => 'Not found', 'code' => 404, 'message' => 'This booking page does not exist or has been removed.'], 'public'); exit; }
    return $page;
}

function booking_public(string $token): void {
    $page = load_public_page($token);
    list($viewTz, $tzRequested) = booking_view_tz($page);
    // Only persist a zone the server genuinely resolved — an unresolvable ?tz fell back to page
    // tz and must NOT be remembered, so it degrades exactly like fresh detection (one redirect, no loop).
    $tzReq = trim((string)param('tz', ''));
    $tzResolved = $tzReq !== '' && booking_resolve_tz($tzReq) !== null;
    $slots = !empty($page['paused']) ? [] : booking_open_slots($page);
    view('booking_public', [
        'title' => $page['title'],
        'page' => $page,
        'days' => booking_group_by_day($slots, $viewTz),
        'hasSlots' => (bool)$slots,
        'viewTz' => $viewTz,
        'tzRequested' => $tzRequested,
        'tzResolved' => $tzResolved,
    ], 'public');
}

function booking_submit(string $token): void {
    $page = load_public_page($token);
    csrf_check();

    // Preserve the viewer's chosen display timezone across the round-trip.
    list($viewTz) = booking_view_tz($page);
    $tzq = '?tz=' . rawurlencode($viewTz);

    if (!empty($page['paused'])) { flash('This page is not currently accepting bookings.', 'error'); redirect('/b/' . $token . $tzq); }

    // Honeypot: silently drop bots.
    if (trim((string)param('website', '')) !== '') { redirect('/b/' . $token . $tzq); }

    $ip = client_ip();
    if (!rate_ok($ip)) { keep_input(); flash('Too many submissions from your connection. Please wait a minute and try again.', 'error'); redirect('/b/' . $token . $tzq); }

    $name = str_cap(trim((string)param('name', '')), 80);
    if ($name === '') { keep_input(); flash('Please enter your name.', 'error'); redirect('/b/' . $token . $tzq); }
    $email = strtolower(trim((string)param('email', '')));
    if (!valid_email($email)) { keep_input(); flash('Please enter a valid email address.', 'error'); redirect('/b/' . $token . $tzq); }
    $note = str_cap(trim((string)param('note', '')), 500);
    $startUtc = (int)param('start', 0);
    if ($startUtc <= 0) { keep_input(); flash('Please choose a time to book.', 'error'); redirect('/b/' . $token . $tzq); }

    $res = book_slot($page, $startUtc, $name, $email, $note, $ip);
    if (!$res['ok']) {
        keep_input();
        $msg = $res['reason'] === 'paused'
            ? 'This page is not currently accepting bookings.'
            : 'Sorry — that time was just taken. Please pick another.';
        flash($msg, 'error');
        redirect('/b/' . $token . $tzq);
    }
    $booking = booking_by_id((int)$res['id']);
    if ($booking) notify_booking_created($page, $booking);
    flash('Booked! A confirmation is below — bookmark this page to manage your booking.', 'success');
    redirect('/m/' . $res['token'] . $tzq);
}

/** Load a booking + its page by manage token, or render a friendly not-found. */
function load_managed_booking(string $token): array {
    $booking = booking_by_token($token);
    if (!$booking) { http_response_code(404); view('error', ['title' => 'Not found', 'code' => 404, 'message' => 'We could not find that booking. The link may be mistyped.'], 'public'); exit; }
    $page = booking_page_by_id((int)$booking['page_id']);
    if (!$page) { http_response_code(404); view('error', ['title' => 'Not found', 'code' => 404, 'message' => 'We could not find that booking.'], 'public'); exit; }
    return [$booking, $page];
}

function booking_manage(string $token): void {
    [$booking, $page] = load_managed_booking($token);
    list($viewTz) = booking_view_tz($page); // manage pages carry explicit ?tz via links; no auto-detect sentinel
    view('booking_manage', [
        'title' => 'Your booking',
        'booking' => $booking,
        'page' => $page,
        'viewTz' => $viewTz,
    ], 'public');
}

function booking_ics(string $token): void {
    [$booking, $page] = load_managed_booking($token);
    header('Content-Type: text/calendar; charset=UTF-8');
    header('Content-Disposition: attachment; filename="booking.ics"');
    echo ics_for_slot(booking_event_poll($page), booking_event_slot($booking));
}

function booking_cancel_confirm(string $token): void {
    [$booking, $page] = load_managed_booking($token);
    list($viewTz) = booking_view_tz($page);
    view('booking_cancel', ['title' => 'Cancel booking', 'booking' => $booking, 'page' => $page, 'viewTz' => $viewTz], 'public');
}

function booking_cancel_do(string $token): void {
    [$booking, $page] = load_managed_booking($token);
    csrf_check();
    list($viewTz) = booking_view_tz($page);
    if (cancel_booking($booking)) {
        notify_booking_cancelled($page, $booking, 'invitee');
        flash('Your booking has been cancelled.', 'success');
    } else {
        flash('This booking was already cancelled.', 'success');
    }
    redirect('/m/' . $token . '?tz=' . rawurlencode($viewTz));
}
