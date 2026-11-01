<?php
defined('SSLIVE') || exit;

/**
 * Wysyłka e-maili (tekst UTF-8) bez zewnętrznych bibliotek:
 * - "mail": funkcja mail() serwera (typowy hosting),
 * - "smtp": własny klient SMTP (STARTTLS / SSL / bez szyfrowania, AUTH LOGIN/PLAIN).
 */
final class Mailer
{
    private const TIMEOUT = 12;

    /** @param string[] $to */
    public static function send(array $to, string $subject, string $body): void
    {
        $to = array_values(array_unique(array_filter(array_map('trim', $to), fn($a) => (bool)filter_var($a, FILTER_VALIDATE_EMAIL))));
        if (!$to) {
            throw new RuntimeException(__('mail.err.no_recipients'));
        }
        $from = self::fromAddress();
        $fromName = (string)Settings::get('mail_from_name', '') ?: (string)Settings::get('site_name', 'ScreenShare Live');
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if (Settings::get('mail_method', 'mail') === 'smtp') {
            self::smtp($to, $from, $fromName, $subject, $body);
        } else {
            self::phpMail($to, $from, $fromName, $subject, $body);
        }
    }

    public static function fromAddress(): string
    {
        $from = trim((string)Settings::get('mail_from', ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $host = preg_replace('/^www\./', '', $host);
            $from = 'no-reply@' . ($host ?: 'localhost');
        }
        return $from;
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function headers(string $from, string $fromName, array $extra = []): array
    {
        return array_merge([
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: ScreenShare Live PHP',
        ], $extra);
    }

    private static function phpMail(array $to, string $from, string $fromName, string $subject, string $body): void
    {
        if (!function_exists('mail')) {
            throw new RuntimeException(__('mail.err.no_mail'));
        }
        $headers = implode("\r\n", self::headers($from, $fromName));
        $encoded = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
        $ok = @mail(implode(', ', $to), self::encodeHeader($subject), $encoded, $headers, '-f' . $from);
        if (!$ok) {
            $ok = @mail(implode(', ', $to), self::encodeHeader($subject), $encoded, $headers);
        }
        if (!$ok) {
            throw new RuntimeException(__('mail.err.mail_failed'));
        }
    }

    /* ---------- SMTP ---------- */

    private static function smtp(array $to, string $from, string $fromName, string $subject, string $body): void
    {
        $host = trim((string)Settings::get('smtp_host', ''));
        $port = (int)Settings::get('smtp_port', '587') ?: 587;
        $secure = (string)Settings::get('smtp_secure', 'tls');
        $user = (string)Settings::get('smtp_user', '');
        $pass = (string)Settings::get('smtp_pass', '');
        if ($host === '') {
            throw new RuntimeException(__('mail.err.no_host'));
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('SMTP: ' . $host . ':' . $port . ' – ' . ($errstr ?: 'connection failed'));
        }
        stream_set_timeout($fp, self::TIMEOUT);
        try {
            self::expect($fp, [220]);
            $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            $caps = self::cmd($fp, 'EHLO ' . $ehlo, [250]);
            if ($secure === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($fp, true, $method)) {
                    throw new RuntimeException('SMTP: STARTTLS failed');
                }
                $caps = self::cmd($fp, 'EHLO ' . $ehlo, [250]);
            }
            if ($user !== '') {
                if (stripos($caps, 'AUTH') !== false && preg_match('/AUTH[ =][^\n]*PLAIN/i', $caps) && !preg_match('/AUTH[ =][^\n]*LOGIN/i', $caps)) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
                } else {
                    self::cmd($fp, 'AUTH LOGIN', [334]);
                    self::cmd($fp, base64_encode($user), [334]);
                    self::cmd($fp, base64_encode($pass), [235], true);
                }
            }
            self::cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            foreach ($to as $rcpt) {
                self::cmd($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            self::cmd($fp, 'DATA', [354]);
            $headers = self::headers($from, $fromName, [
                'To: ' . implode(', ', $to),
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            ]);
            $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
            // base64 nie zawiera linii zaczynających się od kropki, ale na wszelki wypadek
            $data = preg_replace('/^\./m', '..', $data);
            fwrite($fp, $data . "\r\n.\r\n");
            self::expect($fp, [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }

    private static function cmd($fp, string $line, array $codes, bool $secret = false): string
    {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $codes, $secret ? '***' : $line);
    }

    private static function expect($fp, array $codes, string $sent = ''): string
    {
        $resp = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            $what = $sent !== '' ? ' (' . (str_starts_with($sent, 'AUTH') || $sent === '***' ? 'AUTH' : explode(' ', $sent)[0]) . ')' : '';
            throw new RuntimeException('SMTP' . $what . ': ' . ($resp !== '' ? trim($resp) : 'no response'));
        }
        return $resp;
    }
}
