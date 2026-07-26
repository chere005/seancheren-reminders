<?php
/**
 * Sending mail. PHP's mail() hands the message to the host's sendmail unauthenticated,
 * which is how a verification code ends up silently discarded — nothing bounces, it
 * just never arrives. So if lib/config.php names an SMTP server we log in and send it
 * ourselves, over TLS, and only fall back to mail() when it doesn't.
 *
 * Config keys (all optional; without smtp_host this file changes nothing):
 *   smtp_host, smtp_port (587 STARTTLS or 465 implicit TLS), smtp_user, smtp_pass,
 *   mail_from, mail_from_name.
 */

/** Send one plain-text message. Returns true if it was accepted for delivery. */
function mail_send(array $cfg, string $to, string $subject, string $body): bool
{
    $from = (string) ($cfg['mail_from'] ?? 'no-reply@seancheren.com');
    $name = (string) ($cfg['mail_from_name'] ?? 'seancheren.com');
    if (!empty($cfg['smtp_host'])) {
        $err = '';
        if (smtp_send($cfg, $to, $subject, $body, $from, $name, $err)) { return true; }
        mail_log($cfg, "SMTP failed for $to: $err");
        return false;
    }
    $headers = implode("\r\n", [
        'From: ' . $name . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
        'Date: ' . date('r'),
    ]);
    $ok = @mail($to, $subject, $body, $headers, '-f' . $from);
    if (!$ok) { mail_log($cfg, "mail() refused $to"); }
    return $ok;
}

/** One line in data/mail.log — the only trace of a send that didn't happen. */
function mail_log(array $cfg, string $line): void
{
    @file_put_contents(rtrim($cfg['data_dir'], '/') . '/mail.log',
                       date('c') . ' ' . $line . "\n", FILE_APPEND);
}

/**
 * A minimal SMTP conversation: greet, STARTTLS if we're on the submission port,
 * AUTH LOGIN, then the message. Hand-rolled because the suite has no dependencies
 * and this is the whole protocol we need — no attachments, one recipient.
 */
function smtp_send(array $cfg, string $to, string $subject, string $body,
                   string $from, string $name, string &$err = ''): bool
{
    $host = (string) $cfg['smtp_host'];
    $port = (int) ($cfg['smtp_port'] ?? 587);
    $user = (string) ($cfg['smtp_user'] ?? '');
    $pass = (string) ($cfg['smtp_pass'] ?? '');
    $tls  = $port === 465;                       // 465 is TLS from the first byte

    $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true]]);
    $fp  = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $host . ':' . $port,
                                 $eno, $estr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "connect: $estr"; return false; }
    stream_set_timeout($fp, 10);

    // Read one reply (handling the multi-line "250-" form) and check its code.
    $expect = function (string $want) use ($fp, &$err): bool {
        $line = '';
        do {
            $line = fgets($fp, 1024);
            if ($line === false) { $err = 'timed out'; return false; }
        } while (strlen($line) > 3 && $line[3] === '-');
        if (strncmp($line, $want, strlen($want)) !== 0) { $err = trim($line); return false; }
        return true;
    };
    $say = function (string $cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };

    $helo = (string) ($cfg['smtp_helo'] ?? '');
    if ($helo === '') { $helo = substr($from, strpos($from, '@') + 1) ?: 'localhost'; }

    $ok = $expect('220');
    if ($ok) { $say('EHLO ' . $helo); $ok = $expect('250'); }
    if ($ok && !$tls) {                          // 587: upgrade, then greet again
        $say('STARTTLS');
        $ok = $expect('220')
           && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$ok && $err === '') { $err = 'STARTTLS refused'; }
        if ($ok) { $say('EHLO ' . $helo); $ok = $expect('250'); }
    }
    if ($ok && $user !== '') {
        $say('AUTH LOGIN');
        $ok = $expect('334');
        if ($ok) { $say(base64_encode($user)); $ok = $expect('334'); }
        if ($ok) { $say(base64_encode($pass)); $ok = $expect('235'); }
        if (!$ok && $err !== '') { $err = 'auth: ' . $err; }
    }
    if ($ok) { $say('MAIL FROM:<' . $from . '>'); $ok = $expect('250'); }
    if ($ok) { $say('RCPT TO:<' . $to . '>');     $ok = $expect('250'); }
    if ($ok) { $say('DATA');                      $ok = $expect('354'); }
    if ($ok) {
        $msg = 'From: ' . $name . ' <' . $from . '>' . "\r\n"
             . 'To: <' . $to . '>' . "\r\n"
             . 'Subject: ' . $subject . "\r\n"
             . 'Date: ' . date('r') . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $helo . '>' . "\r\n"
             . 'MIME-Version: 1.0' . "\r\n"
             . 'Content-Type: text/plain; charset=utf-8' . "\r\n"
             . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n"
             // A lone dot on a line ends DATA, so any real one is doubled.
             . preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body));
        fwrite($fp, $msg . "\r\n.\r\n");
        $ok = $expect('250');
    }
    $say('QUIT');
    fclose($fp);
    return $ok;
}
