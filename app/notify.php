<?php
declare(strict_types=1);

/** Absolute URL for use in emails (web request context). */
function absolute_url(string $path = '/'): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return rtrim((string)setting('app_url', ''), '/') . url($path);
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function email_layout(string $heading, string $bodyHtml): string {
    $org = e(setting('org_name', 'TimePool'));
    return "<div style=\"font-family:system-ui,Arial,sans-serif;max-width:520px;margin:0 auto;color:#1a1a2e\">"
        . "<h2 style=\"color:#1a1a2e\">" . e($heading) . "</h2>"
        . $bodyHtml
        . "<hr style=\"border:none;border-top:1px solid #e5e5ef;margin:24px 0\">"
        . "<p style=\"color:#8a8aa3;font-size:13px\">Sent by {$org} · TimePool</p></div>";
}

function valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }

function add_invites(int $pollId, array $emails): array {
    $existing = array_column(invites_for_poll($pollId), 'email');
    $added = [];
    $ins = db()->prepare('INSERT INTO invites(poll_id, email, created_at) VALUES(?,?,?)');
    foreach ($emails as $email) {
        $email = strtolower(trim($email));
        if (!valid_email($email) || in_array($email, $existing, true)) continue;
        $ins->execute([$pollId, $email, time()]);
        $existing[] = $email; $added[] = $email;
    }
    return $added;
}

function invites_for_poll(int $pollId): array {
    $s = db()->prepare('SELECT * FROM invites WHERE poll_id = ? ORDER BY created_at');
    $s->execute([$pollId]);
    return $s->fetchAll();
}

/** Email invite links to a list of addresses. Returns count actually sent. */
function send_invites(array $poll, array $emails): int {
    if (!mailer_configured()) return 0;
    $link = absolute_url('/p/' . $poll['public_token']);
    $sent = 0;
    foreach ($emails as $email) {
        $body = "<p>You're invited to help pick a time for <strong>" . e($poll['title']) . "</strong>.</p>"
            . "<p><a href=\"" . e($link) . "\" style=\"display:inline-block;padding:10px 18px;background:#5b5bd6;color:#fff;border-radius:8px;text-decoration:none\">Mark your availability</a></p>"
            . "<p style=\"font-size:13px;color:#8a8aa3\">" . e($link) . "</p>";
        if (send_mail($email, 'Please pick a time: ' . $poll['title'], email_layout("You're invited", $body))) $sent++;
    }
    return $sent;
}

function notify_new_response(array $poll, string $who): void {
    if (!mailer_configured()) return;
    $owner = db_user((int)$poll['user_id']);
    if (!$owner) return;
    $link = absolute_url('/polls/' . $poll['id']);
    $body = "<p><strong>" . e($who) . "</strong> just responded to <strong>" . e($poll['title']) . "</strong>.</p>"
        . "<p><a href=\"" . e($link) . "\">View results</a></p>";
    send_mail($owner['email'], 'New response: ' . $poll['title'], email_layout('New response', $body));
}

function notify_finalized(array $poll, array $slot): void {
    if (!mailer_configured()) return;
    $when = slot_label($slot, $poll['organizer_tz']);
    $link = absolute_url('/p/' . $poll['public_token']);
    $body = "<p>The time for <strong>" . e($poll['title']) . "</strong> is confirmed:</p>"
        . "<p style=\"font-size:18px\"><strong>" . e($when) . "</strong></p>"
        . "<p><a href=\"" . e($link) . "\">View details &amp; add to calendar</a></p>";
    $subject = 'Confirmed: ' . $poll['title'];
    foreach (invites_for_poll((int)$poll['id']) as $inv) {
        send_mail($inv['email'], $subject, email_layout('Time confirmed', $body));
    }
    $owner = db_user((int)$poll['user_id']);
    if ($owner) send_mail($owner['email'], $subject, email_layout('Time confirmed', $body));
}

/** Opportunistic deadline reminders for an organizer's polls (no cron needed). */
function maybe_send_nudges(array $polls): void {
    if (!mailer_configured()) return;
    $now = time(); $soon = $now + 86400;
    foreach ($polls as $poll) {
        $dl = (int)($poll['deadline_utc'] ?? 0);
        if (!$dl || $dl < $now || $dl > $soon || !empty($poll['nudged_at']) || !empty($poll['closed'])) continue;
        $invites = invites_for_poll((int)$poll['id']);
        if (!$invites) continue;
        $link = absolute_url('/p/' . $poll['public_token']);
        $body = "<p>Reminder: the poll <strong>" . e($poll['title']) . "</strong> closes soon. "
            . "If you haven't picked your times yet, please do.</p>"
            . "<p><a href=\"" . e($link) . "\">Respond now</a></p>";
        foreach ($invites as $inv) send_mail($inv['email'], 'Reminder: ' . $poll['title'], email_layout('Closing soon', $body));
        db()->prepare('UPDATE polls SET nudged_at = ? WHERE id = ?')->execute([$now, (int)$poll['id']]);
    }
}
