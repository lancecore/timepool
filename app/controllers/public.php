<?php
declare(strict_types=1);

function load_public_poll(string $token): array {
    $poll = poll_by_token($token);
    if (!$poll) { http_response_code(404); view('error', ['title' => 'Not found', 'code' => 404, 'message' => 'This poll does not exist or has been removed.'], 'public'); exit; }
    return $poll;
}

function viewer_edit_token(array $poll): string {
    $fromGet = (string)param('edit', '');
    if ($fromGet !== '') return $fromGet;
    return (string)($_COOKIE['tp_edit_' . $poll['id']] ?? '');
}

function public_poll(string $token): void {
    $poll = load_public_poll($token);
    $slots = slots_for_poll((int)$poll['id']);
    $viewer = participant_by_token((int)$poll['id'], viewer_edit_token($poll));
    $blindHide = !empty($poll['blind']) && !$viewer;
    $t = tally((int)$poll['id']);
    $participants = $blindHide ? [] : participants_for_poll((int)$poll['id']);
    $responses = $blindHide ? [] : responses_map((int)$poll['id']);
    $final = $poll['final_slot_id'] ? slot_by_id((int)$poll['final_slot_id']) : null;

    view('poll_respond', [
        'title' => $poll['title'],
        'poll' => $poll,
        'slots' => $slots,
        'viewer' => $viewer,
        'blindHide' => $blindHide,
        'closed' => poll_is_closed($poll),
        'participants' => $participants,
        'responses' => $responses,
        'tally' => $t,
        'final' => $final,
    ], 'public');
}

function public_respond(string $token): void {
    $poll = load_public_poll($token);
    csrf_check();

    if (poll_is_closed($poll)) { flash('This poll is closed and no longer accepts responses.', 'error'); redirect('/p/' . $token); }

    // Honeypot: silently drop bots without an error.
    if (trim((string)param('website', '')) !== '') { redirect('/p/' . $token); }

    $ip = client_ip();
    if (!rate_ok($ip)) { flash('Too many submissions from your connection. Please wait a minute and try again.', 'error'); redirect('/p/' . $token); }

    $name = trim((string)param('name', ''));
    if ($name === '') { flash('Please enter your name.', 'error'); redirect('/p/' . $token); }
    if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 80);

    $editToken = viewer_edit_token($poll);
    $existing = participant_by_token((int)$poll['id'], $editToken);
    if (!$existing) {
        $cap = (int)setting('max_participants', '500');
        if (count(participants_for_poll((int)$poll['id'])) >= $cap) {
            flash('This poll has reached its response limit.', 'error');
            redirect('/p/' . $token);
        }
    }

    $choices = [];
    foreach (slots_for_poll((int)$poll['id']) as $slot) {
        $choices[(int)$slot['id']] = (string)param('slot_' . $slot['id'], 'no');
    }
    $comment = trim((string)param('comment', ''));
    if (mb_strlen($comment) > 500) $comment = mb_substr($comment, 0, 500);

    $newToken = save_response($poll, $name, $comment, $choices, $ip, $editToken ?: null);
    setcookie('tp_edit_' . $poll['id'], $newToken, [
        'expires' => time() + 31536000, 'path' => base_path() ?: '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!$existing) notify_new_response($poll, $name);
    flash('Thanks! Your availability has been saved. Bookmark this page to edit it later.', 'success');
    redirect('/p/' . $token);
}

function public_ics(string $token): void {
    $poll = load_public_poll($token);
    $which = (string)param('slot', 'final');
    $slot = $which === 'final'
        ? ($poll['final_slot_id'] ? slot_by_id((int)$poll['final_slot_id']) : null)
        : slot_by_id((int)$which);
    if (!$slot || (int)$slot['poll_id'] !== (int)$poll['id']) { http_response_code(404); exit('No such time.'); }

    header('Content-Type: text/calendar; charset=UTF-8');
    header('Content-Disposition: attachment; filename="meeting.ics"');
    echo ics_for_slot($poll, $slot);
}

function public_logo(): void {
    $file = (string)setting('logo_file', '');
    $path = $file ? DATA_DIR . '/uploads/' . basename($file) : '';
    if (!$file || !is_file($path)) { http_response_code(404); exit; }
    $info = getimagesize($path);
    header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=31536000, immutable'); // URL carries ?v=<mtime>, so it's safe to cache hard
    readfile($path);
}
