<?php
/** SMTP helpers extracted verbatim from checkwinner.php for one-off sends. */
function logline($msg)
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents(LOG_DIR . '/checkwinner-' . date('Y-m') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo $line . (php_sapi_name() === 'cli' ? PHP_EOL : "<br>\n");
}

/**
 * Read one (possibly multiline) SMTP server response.
 */
function smtp_read($fp)
{
    $resp = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $resp .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return $resp;
}

/**
 * Send $cmd (or nothing, to read the greeting) and require a reply code prefix.
 * Returns null on success, an error string on failure.
 */
function smtp_cmd($fp, $cmd, $expect, $maskLog = null)
{
    if ($cmd !== null) {
        fwrite($fp, $cmd . "\r\n");
    }
    $resp = smtp_read($fp);
    if (strpos($resp, (string) $expect) !== 0) {
        $what = ($cmd === null) ? 'greeting' : ($maskLog !== null ? $maskLog : $cmd);
        return "SMTP '$what' failed, expected $expect, got: " . trim($resp);
    }
    return null;
}

/**
 * Deliver a fully-built MIME message via authenticated SMTP.
 * Returns true on success, an error string on failure.
 */
function smtp_send($from, $recipients, $message)
{
    $pass   = str_replace(' ', '', SMTP_PASSWORD);
    $useSsl = (SMTP_SECURE === 'ssl');
    $remote = ($useSsl ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT;
    $ctx    = stream_context_create(['ssl' => [
        'verify_peer'      => VERIFY_SSL,
        'verify_peer_name' => VERIFY_SSL,
    ]]);

    $fp = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return 'connect to ' . SMTP_HOST . ':' . SMTP_PORT . " failed: $errstr ($errno)";
    }
    stream_set_timeout($fp, 30);

    $hostname = gethostname() ?: 'localhost';
    $steps = [];
    $steps[] = [null, 220, null];
    $steps[] = ["EHLO $hostname", 250, null];
    if (!$useSsl) {
        $steps[] = ['STARTTLS', 220, null];
    }

    foreach ($steps as $s) {
        if (($err = smtp_cmd($fp, $s[0], $s[1], $s[2])) !== null) {
            fclose($fp);
            return $err;
        }
    }

    if (!$useSsl) {
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return 'STARTTLS negotiation failed';
        }
        if (($err = smtp_cmd($fp, "EHLO $hostname", 250)) !== null) {
            fclose($fp);
            return $err;
        }
    }

    $steps = [
        ['AUTH LOGIN', 334, null],
        [base64_encode(SMTP_USER), 334, 'AUTH LOGIN username'],
        [base64_encode($pass), 235, 'AUTH LOGIN password'],
        ['MAIL FROM:<' . $from . '>', 250, null],
    ];
    foreach ($recipients as $rcpt) {
        $steps[] = ['RCPT TO:<' . trim($rcpt) . '>', 250, null];
    }
    $steps[] = ['DATA', 354, null];

    foreach ($steps as $s) {
        if (($err = smtp_cmd($fp, $s[0], $s[1], $s[2])) !== null) {
            fclose($fp);
            return $err;
        }
    }

    // Normalise line endings and dot-stuff the body (RFC 5321 4.5.2).
    $message = preg_replace('/\r?\n/', "\r\n", $message);
    $message = preg_replace('/^\./m', '..', $message);
    fwrite($fp, $message . "\r\n.\r\n");
    if (($err = smtp_cmd($fp, null, 250)) !== null) {
        fclose($fp);
        return $err;
    }

    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

function send_html_email($to, $subject, $html)
{
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $recipients     = array_filter(array_map('trim', explode(',', $to)));

    if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'Reply-To: ' . MAIL_FROM,
            'Message-ID: <' . uniqid('checkwinner.', true) . '@depthlogistics.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $result = smtp_send(MAIL_FROM, $recipients, implode("\r\n", $headers) . "\r\n\r\n" . $html);
        if ($result !== true) {
            logline('SMTP error: ' . $result);
            return false;
        }
        return true;
    }

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM,
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
    return mail($to, $encodedSubject, $html, $headers, '-f' . MAIL_FROM);
}
