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
        'show_individual' => param('show_individual') ? 1 : 0,
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

/** Neutralize spreadsheet formula injection in participant-supplied cells. */
function csv_guard(string $v): string {
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/** Full results grid as CSV: one row per participant, then per-choice totals. */
function poll_results_csv(array $poll): string {
    $slots = slots_for_poll((int)$poll['id']);
    $tz = new DateTimeZone($poll['organizer_tz']);

    $head = ['Participant', 'Comment'];
    foreach ($slots as $s) {
        $head[] = $s['kind'] === 'date'
            ? $s['date'] . ' (all day)'
            : (new DateTime('@' . $s['start_utc']))->setTimezone($tz)->format('Y-m-d H:i T') . ' (' . (int)$s['duration_min'] . ' min)';
    }

    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel decodes non-ASCII names
    fputcsv($out, $head);
    $responses = responses_map((int)$poll['id']);
    foreach (participants_for_poll((int)$poll['id']) as $p) {
        $row = [csv_guard((string)$p['name']), csv_guard((string)$p['comment'])];
        foreach ($slots as $s) $row[] = $responses[(int)$p['id']][(int)$s['id']] ?? '';
        fputcsv($out, $row);
    }
    $t = tally((int)$poll['id']);
    foreach (['yes', 'maybe', 'no'] as $c) {
        $row = ['Total ' . $c, ''];
        foreach ($slots as $s) $row[] = $t['counts'][(int)$s['id']][$c];
        fputcsv($out, $row);
    }
    rewind($out);
    return (string)stream_get_contents($out);
}

/** Filename-safe slug from the poll title, shared by all exports. */
function poll_export_name(array $poll): string {
    return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $poll['title']), '-') ?: 'poll-results';
}

function poll_export_csv(string $id): void {
    [, $poll] = own_poll($id);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . poll_export_name($poll) . '.csv"');
    echo poll_results_csv($poll);
}

/**
 * Grid data for the styled (Word/Excel) exports, matching the sample doc's layout:
 * friendly Yes / If need be / No labels, participants as rows, per-choice totals.
 * Returns [headers, body, totals] where each body cell is ['text' => ..., 'fill' => choice|''].
 */
function poll_results_grid(array $poll): array {
    $slots = slots_for_poll((int)$poll['id']);
    $tz = new DateTimeZone($poll['organizer_tz']);
    $labels = ['yes' => 'Yes', 'maybe' => 'If need be', 'no' => 'No'];

    $headers = ['Name'];
    foreach ($slots as $s) {
        $headers[] = $s['kind'] === 'date'
            ? (new DateTime($s['date']))->format('D, M j')
            : (new DateTime('@' . $s['start_utc']))->setTimezone($tz)->format('D, M j, g:i A') . ' (' . (int)$s['duration_min'] . ' min)';
    }

    $responses = responses_map((int)$poll['id']);
    $body = [];
    foreach (participants_for_poll((int)$poll['id']) as $p) {
        $row = [['text' => (string)$p['name'], 'fill' => '']];
        foreach ($slots as $s) {
            $c = (string)($responses[(int)$p['id']][(int)$s['id']] ?? '');
            $row[] = ['text' => $labels[$c] ?? '', 'fill' => $c];
        }
        $body[] = $row;
    }

    $t = tally((int)$poll['id']);
    $totals = [];
    foreach (['yes' => 'Total Yes', 'maybe' => 'Total If need be', 'no' => 'Total No'] as $c => $label) {
        $row = [$label];
        foreach ($slots as $s) $row[] = (string)$t['counts'][(int)$s['id']][$c];
        $totals[] = $row;
    }
    return [$headers, $body, $totals];
}

/** Choice -> cell fill (hex), taken from the sample document. */
const EXPORT_FILL = ['yes' => 'B3E5A1', 'maybe' => 'F6F37A', 'no' => 'E4A2A2'];

/**
 * Build an OOXML container (.docx/.xlsx are just zips) from [path => bytes].
 * Pure PHP, STORED (uncompressed) entries — no ext-zip, no temp file, so it
 * works on hosts without ZipArchive. OOXML accepts stored entries; the parts
 * are tiny, so skipping compression costs nothing meaningful.
 */
function zip_container(array $files): string {
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data) & 0xFFFFFFFF;
        $len = strlen($data);
        $nlen = strlen($name);
        $lh = "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)                    // dos mod time, date
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nlen) . pack('v', 0) . $name . $data;
        $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)                    // dos mod time, date
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nlen) . pack('v', 0) . pack('v', 0) // name, extra, comment lengths
            . pack('v', 0) . pack('v', 0) . pack('V', 0)     // disk start, internal + external attrs
            . pack('V', $offset) . $name;
        $local .= $lh;
        $offset += strlen($lh);
    }
    $n = count($files);
    return $local . $central . "PK\x05\x06" . pack('v', 0) . pack('v', 0)
        . pack('v', $n) . pack('v', $n)
        . pack('V', strlen($central)) . pack('V', strlen($local)) . pack('v', 0);
}

function col_letter(int $n): string {
    $s = '';
    while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
    return $s;
}

// ---- Word (.docx) ----

function docx_cell(string $text, ?string $fill, bool $bold, ?string $color): string {
    $shd = $fill !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '';
    $rpr = ($bold || $color !== null)
        ? '<w:rPr>' . ($bold ? '<w:b/>' : '') . ($color !== null ? '<w:color w:val="' . $color . '"/>' : '') . '</w:rPr>'
        : '';
    $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<w:tc><w:tcPr>' . $shd . '<w:vAlign w:val="center"/></w:tcPr>'
        . '<w:p><w:r>' . $rpr . '<w:t xml:space="preserve">' . $t . '</w:t></w:r></w:p></w:tc>';
}

function poll_results_docx(array $poll): string {
    [$headers, $body, $totals] = poll_results_grid($poll);

    $rows = '<w:tr>';
    foreach ($headers as $h) $rows .= docx_cell($h, '305496', true, 'FFFFFF');
    $rows .= '</w:tr>';
    foreach ($body as $row) {
        $rows .= '<w:tr>' . docx_cell($row[0]['text'], null, true, null);
        for ($i = 1, $n = count($row); $i < $n; $i++) {
            $rows .= docx_cell($row[$i]['text'], EXPORT_FILL[$row[$i]['fill']] ?? null, false, null);
        }
        $rows .= '</w:tr>';
    }
    foreach ($totals as $row) {
        $rows .= '<w:tr>';
        foreach ($row as $v) $rows .= docx_cell((string)$v, 'E8E8E8', true, null);
        $rows .= '</w:tr>';
    }

    $border = '';
    foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) {
        $border .= '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="D0D0D0"/>';
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>' . $border . '</w:tblBorders></w:tblPr>'
        . $rows . '</w:tbl><w:p/>'
        . '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/>'
        . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="0" w:footer="0" w:gutter="0"/>'
        . '</w:sectPr></w:body></w:document>';

    return zip_container([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>',
        'word/document.xml' => $doc,
    ]);
}

function poll_export_docx(string $id): void {
    [, $poll] = own_poll($id);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . poll_export_name($poll) . '.docx"');
    echo poll_results_docx($poll);
}

// ---- Excel (.xlsx) ----

function xlsx_str_cell(string $ref, int $style, string $text): string {
    $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . $t . '</t></is></c>';
}

function poll_results_xlsx(array $poll): string {
    [$headers, $body, $totals] = poll_results_grid($poll);
    $xf = ['yes' => 3, 'maybe' => 4, 'no' => 5]; // style index per choice; empty -> 0
    $ncol = count($headers);

    $r = 1;
    $rowsXml = '<row r="1">';
    foreach ($headers as $i => $h) $rowsXml .= xlsx_str_cell(col_letter($i + 1) . $r, 1, $h);
    $rowsXml .= '</row>';
    foreach ($body as $row) {
        $r++;
        $rowsXml .= '<row r="' . $r . '">' . xlsx_str_cell('A' . $r, 2, $row[0]['text']);
        for ($i = 1, $n = count($row); $i < $n; $i++) {
            $s = $xf[$row[$i]['fill']] ?? 0;
            $rowsXml .= xlsx_str_cell(col_letter($i + 1) . $r, $s, $row[$i]['text']);
        }
        $rowsXml .= '</row>';
    }
    foreach ($totals as $row) {
        $r++;
        $rowsXml .= '<row r="' . $r . '">' . xlsx_str_cell('A' . $r, 6, (string)$row[0]);
        for ($i = 1, $n = count($row); $i < $n; $i++) {
            $rowsXml .= '<c r="' . col_letter($i + 1) . $r . '" s="6"><v>' . (int)$row[$i] . '</v></c>';
        }
        $rowsXml .= '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="1" width="24" customWidth="1"/>'
        . '<col min="2" max="' . max(2, $ncol) . '" width="16" customWidth="1"/></cols>'
        . '<sheetData>' . $rowsXml . '</sheetData></worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="7">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFB3E5A1"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF6F37A"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE4A2A2"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF305496"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8E8E8"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="2" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="6" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    return zip_container([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Results" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ]);
}

function poll_export_xlsx(string $id): void {
    [, $poll] = own_poll($id);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . poll_export_name($poll) . '.xlsx"');
    echo poll_results_xlsx($poll);
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
