<?php
declare(strict_types=1);

/** Email is optional. Configured = we have at least a from-address and an SMTP host. */
function mailer_configured(): bool {
    return trim((string)setting('smtp_host', '')) !== '' && trim((string)setting('smtp_from', '')) !== '';
}

/**
 * Best-effort HTML email. Returns true on success, false if not configured or it failed.
 * Minimal SMTP client (PLAIN/LOGIN auth, optional SSL/STARTTLS) so no Composer dependency is needed.
 */
function send_mail(string $to, string $subject, string $html): bool {
    if (!mailer_configured()) return false;

    // Subjects embed organizer-controlled titles; collapse any CR/LF so a crafted
    // title can't inject extra SMTP headers. One guard all callers route through.
    $subject = mail_header_safe($subject);

    $host   = (string)setting('smtp_host');
    $port   = (int)(setting('smtp_port', '587'));
    $user   = (string)setting('smtp_user', '');
    $pass   = (string)setting('smtp_pass', '');
    $secure = (string)setting('smtp_secure', 'tls'); // 'tls' | 'ssl' | 'none'
    $from   = (string)setting('smtp_from');
    $fromName = (string)setting('org_name', 'TimePool');

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return false;

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read): string { fwrite($fp, $c . "\r\n"); return $read(); };

    try {
        $read();
        $ehlo = "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $cmd($ehlo);
        if ($secure === 'tls') {
            if ((int)$cmd('STARTTLS') !== 220) { fclose($fp); return false; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
            $cmd($ehlo);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($user));
            if ((int)$cmd(base64_encode($pass)) !== 235) { fclose($fp); return false; }
        }
        if ((int)$cmd("MAIL FROM:<$from>") >= 400) { fclose($fp); return false; }
        if ((int)$cmd("RCPT TO:<$to>") >= 400) { fclose($fp); return false; }
        if ((int)$cmd('DATA') >= 400) { fclose($fp); return false; }

        $headers = [
            'From: ' . mime_name($fromName) . " <$from>",
            "To: <$to>",
            'Subject: ' . mime_encode($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Date: ' . date('r'),
        ];
        $body = str_replace("\r\n.", "\r\n..", $html); // dot-stuffing
        $resp = $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.");
        $cmd('QUIT');
        fclose($fp);
        return (int)$resp < 400;
    } catch (Throwable $e) {
        if (is_resource($fp)) fclose($fp);
        return false;
    }
}

/** Collapse CR/LF (and runs of them) to a single space so a value can't inject email headers. */
function mail_header_safe(string $s): string {
    return (string)preg_replace('/[\r\n]+/', ' ', $s);
}

function mime_encode(string $s): string {
    return preg_match('/[\x80-\xFF]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}
function mime_name(string $s): string {
    return preg_match('/[\x80-\xFF]/', $s) ? mime_encode($s) : '"' . str_replace('"', '', $s) . '"';
}
