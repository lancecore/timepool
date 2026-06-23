<?php
declare(strict_types=1);

function ics_escape(string $s): string {
    return addcslashes(str_replace(["\r\n", "\n", "\r"], '\n', $s), ",;\\");
}

function ics_uid(array $poll, array $slot): string {
    return 'mp-' . $poll['public_token'] . '-' . $slot['id'] . '@' . ($_SERVER['HTTP_HOST'] ?? 'meeting-poll');
}

/** Build a valid VCALENDAR for a chosen slot (timed or all-day). */
function ics_for_slot(array $poll, array $slot): string {
    $summary = ics_escape((string)$poll['title']);
    $desc    = ics_escape((string)($poll['description'] ?? ''));
    $loc     = ics_escape((string)($poll['location'] ?? ''));
    $uid     = ics_uid($poll, $slot);
    $stamp   = gmdate('Ymd\THis\Z');

    if ($slot['kind'] === 'date') {
        $start = (new DateTime($slot['date']))->format('Ymd');
        $end   = (new DateTime($slot['date']))->modify('+1 day')->format('Ymd');
        $dt = "DTSTART;VALUE=DATE:$start\r\nDTEND;VALUE=DATE:$end";
    } else {
        $dur   = max(1, (int)($slot['duration_min'] ?: 60));
        $start = gmdate('Ymd\THis\Z', (int)$slot['start_utc']);
        $end   = gmdate('Ymd\THis\Z', (int)$slot['start_utc'] + $dur * 60);
        $dt = "DTSTART:$start\r\nDTEND:$end";
    }

    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Meeting Poll//EN', 'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH', 'BEGIN:VEVENT', "UID:$uid", "DTSTAMP:$stamp", $dt,
        "SUMMARY:$summary",
    ];
    if ($desc !== '') $lines[] = "DESCRIPTION:$desc";
    if ($loc !== '')  $lines[] = "LOCATION:$loc";
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

function gcal_link(array $poll, array $slot): string {
    if ($slot['kind'] === 'date') {
        $start = (new DateTime($slot['date']))->format('Ymd');
        $end   = (new DateTime($slot['date']))->modify('+1 day')->format('Ymd');
        $dates = "$start/$end";
    } else {
        $dur   = max(1, (int)($slot['duration_min'] ?: 60));
        $start = gmdate('Ymd\THis\Z', (int)$slot['start_utc']);
        $end   = gmdate('Ymd\THis\Z', (int)$slot['start_utc'] + $dur * 60);
        $dates = "$start/$end";
    }
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action'  => 'TEMPLATE',
        'text'    => $poll['title'],
        'dates'   => $dates,
        'details' => $poll['description'] ?? '',
        'location'=> $poll['location'] ?? '',
    ]);
}

function outlook_link(array $poll, array $slot): string {
    if ($slot['kind'] === 'date') {
        $start = (new DateTime($slot['date']))->format('Y-m-d');
        $end   = (new DateTime($slot['date']))->modify('+1 day')->format('Y-m-d');
        $allday = 'true';
    } else {
        $dur   = max(1, (int)($slot['duration_min'] ?: 60));
        $start = gmdate('Y-m-d\TH:i:s\Z', (int)$slot['start_utc']);
        $end   = gmdate('Y-m-d\TH:i:s\Z', (int)$slot['start_utc'] + $dur * 60);
        $allday = 'false';
    }
    return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query([
        'path'      => '/calendar/action/compose',
        'rru'       => 'addevent',
        'subject'   => $poll['title'],
        'startdt'   => $start,
        'enddt'     => $end,
        'allday'    => $allday,
        'body'      => $poll['description'] ?? '',
        'location'  => $poll['location'] ?? '',
    ]);
}
