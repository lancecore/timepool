<?php
declare(strict_types=1);

/** Resolve a poll the current user is allowed to manage; 404/403 otherwise. */
function own_poll(string $id): array {
    $u = require_login();
    $poll = poll_by_id((int)$id);
    if (!$poll) { http_response_code(404); view('error', ['title' => 'Not found', 'code' => 404, 'message' => 'Poll not found.'], 'public'); exit; }
    if ((int)$poll['user_id'] !== (int)$u['id'] && ($u['role'] ?? '') !== 'admin') {
        http_response_code(403); view('error', ['title' => 'Forbidden', 'code' => 403, 'message' => 'You do not have access to this poll.'], 'public'); exit;
    }
    return [$u, $poll];
}

function collect_slot_rows(): array {
    $kinds = (array)($_POST['slot_kind'] ?? []);
    $dates = (array)($_POST['slot_date'] ?? []);
    $times = (array)($_POST['slot_time'] ?? []);
    $durs  = (array)($_POST['slot_dur'] ?? []);
    $rows = [];
    foreach ($dates as $i => $d) {
        $d = trim((string)$d);
        if ($d === '') continue;
        $kind = (($kinds[$i] ?? 'datetime') === 'date') ? 'date' : 'datetime';
        $rows[] = [
            'kind' => $kind,
            'date' => $d,
            'time' => trim((string)($times[$i] ?? '')),
            'duration' => (int)($durs[$i] ?? 60),
        ];
    }
    return $rows;
}

function collect_poll_data(): array {
    $tz = trim((string)param('organizer_tz', ''));
    if (!in_array($tz, timezone_identifiers_list(), true)) $tz = (string)setting('default_tz', 'UTC');
    $deadline = null;
    $dd = trim((string)param('deadline_date', ''));
    if ($dd !== '') $deadline = local_to_utc($dd . ' ' . (trim((string)param('deadline_time', '')) ?: '23:59'), $tz);
    return [
        'title'       => trim((string)param('title', '')),
        'description' => trim((string)param('description', '')),
        'location'    => trim((string)param('location', '')),
        'organizer_tz'=> $tz,
        'blind'       => param('blind') ? 1 : 0,
        'deadline_utc'=> $deadline,
    ];
}

/** Convert stored slots into the form's editable display rows. */
function slots_as_display(int $pollId, string $tz): array {
    $rows = [];
    foreach (slots_for_poll($pollId) as $s) {
        if ($s['kind'] === 'date') {
            $rows[] = ['kind' => 'date', 'date' => $s['date'], 'time' => '', 'duration' => 60];
        } else {
            $dt = (new DateTime('@' . $s['start_utc']))->setTimezone(new DateTimeZone($tz));
            $rows[] = ['kind' => 'datetime', 'date' => $dt->format('Y-m-d'), 'time' => $dt->format('H:i'), 'duration' => (int)$s['duration_min']];
        }
    }
    return $rows;
}

function dashboard(): void {
    $u = require_login();
    $polls = polls_for_user((int)$u['id']);
    maybe_send_nudges($polls);
    view('dashboard', ['title' => 'Your polls', 'polls' => $polls, 'me' => $u]);
}

function poll_new(): void {
    require_login();
    view('poll_form', ['title' => 'New poll', 'poll' => null, 'slotRows' => []]);
}

function poll_create(): void {
    $u = require_login();
    csrf_check();
    $data = collect_poll_data();
    $rows = collect_slot_rows();
    $err = validate_poll($data, $rows);
    if ($err) {
        flash($err, 'error');
        view('poll_form', ['title' => 'New poll', 'poll' => array_merge($data, ['id' => 0]), 'slotRows' => $rows]);
        return;
    }
    $id = create_poll((int)$u['id'], $data, $rows);
    flash('Poll created. Share the link below to start collecting responses.', 'success');
    redirect('/polls/' . $id);
}

function poll_edit(string $id): void {
    [, $poll] = own_poll($id);
    view('poll_form', ['title' => 'Edit poll', 'poll' => $poll, 'slotRows' => slots_as_display((int)$poll['id'], $poll['organizer_tz'])]);
}

function poll_update(string $id): void {
    [, $poll] = own_poll($id);
    csrf_check();
    $data = collect_poll_data();
    $rows = collect_slot_rows();
    $err = validate_poll($data, $rows);
    if ($err) {
        flash($err, 'error');
        view('poll_form', ['title' => 'Edit poll', 'poll' => array_merge($poll, $data), 'slotRows' => $rows]);
        return;
    }
    update_poll((int)$poll['id'], $data, $rows);
    flash('Poll updated.', 'success');
    redirect('/polls/' . $poll['id']);
}

function validate_poll(array $data, array $rows): ?string {
    if ($data['title'] === '') return 'Please give the poll a title.';
    if (count($rows) === 0) return 'Add at least one time slot.';
    return null;
}

function poll_duplicate(string $id): void {
    [$u, $poll] = own_poll($id);
    csrf_check();
    $newId = duplicate_poll((int)$poll['id'], (int)$u['id']);
    flash('Poll duplicated.', 'success');
    redirect('/polls/' . $newId);
}

function poll_close(string $id): void {
    [, $poll] = own_poll($id);
    csrf_check();
    $closed = param('closed') === '1';
    set_poll_closed((int)$poll['id'], $closed);
    flash($closed ? 'Poll closed to new responses.' : 'Poll reopened.', 'success');
    redirect('/polls/' . $poll['id']);
}

function poll_finalize(string $id): void {
    [, $poll] = own_poll($id);
    csrf_check();
    $slotId = (int)param('slot_id', 0);
    if ($slotId === 0) {
        set_final_slot((int)$poll['id'], null);
        activity_add((int)$poll['id'], 'Final time cleared');
        flash('Final time cleared.', 'success');
        redirect('/polls/' . $poll['id']);
    }
    $slot = slot_by_id($slotId);
    if (!$slot || (int)$slot['poll_id'] !== (int)$poll['id']) { flash('That slot does not belong to this poll.', 'error'); redirect('/polls/' . $poll['id']); }
    set_final_slot((int)$poll['id'], $slotId);
    activity_add((int)$poll['id'], 'Final time set: ' . slot_label($slot, $poll['organizer_tz']));
    notify_finalized(poll_by_id((int)$poll['id']), $slot);
    flash('Final time set. Everyone can now add it to their calendar.', 'success');
    redirect('/polls/' . $poll['id']);
}

function poll_delete(string $id): void {
    [, $poll] = own_poll($id);
    csrf_check();
    delete_poll((int)$poll['id']);
    flash('Poll deleted.', 'success');
    redirect('/dashboard');
}

function poll_invite(string $id): void {
    [, $poll] = own_poll($id);
    csrf_check();
    $raw = (string)param('emails', '');
    $emails = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $added = add_invites((int)$poll['id'], $emails);
    if (!$added) { keep_input(); flash('No new valid email addresses found.', 'error'); redirect('/polls/' . $poll['id']); }
    if (mailer_configured()) {
        $sent = send_invites($poll, $added);
        flash("$sent invite(s) emailed.", 'success');
    } else {
        flash(count($added) . ' invite(s) saved. Email is not configured, so share the link manually.', 'success');
    }
    redirect('/polls/' . $poll['id']);
}

function poll_manage(string $id): void {
    [$u, $poll] = own_poll($id);
    $slots = slots_for_poll((int)$poll['id']);
    $participants = participants_for_poll((int)$poll['id']);
    $responses = responses_map((int)$poll['id']);
    $t = tally((int)$poll['id']);
    view('poll_manage', [
        'title' => $poll['title'],
        'poll' => $poll,
        'slots' => $slots,
        'participants' => $participants,
        'responses' => $responses,
        'tally' => $t,
        'activity' => activity_for((int)$poll['id']),
        'invites' => invites_for_poll((int)$poll['id']),
        'me' => $u,
    ]);
}

/** [participant_id => [slot_id => choice]] */
function responses_map(int $pollId): array {
    $map = [];
    $s = db()->prepare('SELECT r.participant_id, r.slot_id, r.choice FROM responses r
                        JOIN participants p ON p.id = r.participant_id WHERE p.poll_id = ?');
    $s->execute([$pollId]);
    foreach ($s as $r) $map[(int)$r['participant_id']][(int)$r['slot_id']] = $r['choice'];
    return $map;
}
