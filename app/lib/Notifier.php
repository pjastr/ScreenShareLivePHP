<?php
defined('SSLIVE') || exit;

/**
 * Powiadomienia e-mail o rozpoczęciu transmisji.
 * Tryb (Panel admina → Ustawienia): off | daily (pierwsza transmisja danego dnia) | every (każda).
 */
final class Notifier
{
    public static function mode(): string
    {
        $m = (string)Settings::get('notify_mode', 'off');
        return in_array($m, ['off', 'daily', 'every'], true) ? $m : 'off';
    }

    /** Adresaci: lista z ustawień albo (gdy pusta) wszyscy aktywni administratorzy. */
    public static function recipients(): array
    {
        $list = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)Settings::get('notify_to', '')) ?: []));
        if (!$list) {
            $list = array_column(DB::all("SELECT `email` FROM {users} WHERE `role` = 'admin' AND `status` = 'active'"), 'email');
        }
        return array_values(array_unique(array_filter($list, fn($a) => (bool)filter_var($a, FILTER_VALIDATE_EMAIL))));
    }

    public static function shouldNotify(): bool
    {
        $mode = self::mode();
        if ($mode === 'off') {
            return false;
        }
        if ($mode === 'daily') {
            return Settings::get('notify_last_day', '') !== date('Y-m-d');
        }
        return true;
    }

    public static function streamStarted(array $s): void
    {
        if (self::mode() === 'daily') {
            // znacznik ustawiony przed wysyłką – równoległy start nie wyśle drugiego maila
            $today = date('Y-m-d');
            $n = DB::run('UPDATE {settings} SET `value` = ? WHERE `name` = ? AND (`value` IS NULL OR `value` <> ?)', [$today, 'notify_last_day', $today])->rowCount();
            if ($n === 0) {
                return;
            }
        }
        @set_time_limit(60);
        $site = (string)Settings::get('site_name', 'ScreenShare Live');
        $teacher = (string)($s['username'] ?? '');
        $start = Live::ts($s['started_at']);
        $end = Live::ts($s['ends_at']);
        $panel = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url() . 'panel/?p=admin';
        $subject = __('mail.started.subject', ['site' => $site, 'title' => $s['title']]);
        $body = __('mail.started.body', [
            'site' => $site,
            'title' => $s['title'],
            'teacher' => $teacher,
            'start' => date('Y-m-d H:i', $start),
            'end' => date('H:i', $end),
            'minutes' => (int)round(($end - $start) / 60),
            'max' => (int)$s['max_viewers'],
            'panel' => $panel,
        ]) . "\n\n" . __(self::mode() === 'daily' ? 'mail.started.footer_daily' : 'mail.started.footer_every');
        try {
            Mailer::send(self::recipients(), $subject, $body);
            Settings::set('mail_last_status', 'ok ' . gmdate('c'));
        } catch (Throwable $e) {
            Settings::set('mail_last_status', 'error ' . gmdate('c') . ' ' . mb_substr($e->getMessage(), 0, 300));
            if (self::mode() === 'daily') {
                Settings::set('notify_last_day', ''); // spróbuj ponownie przy następnej transmisji
            }
        }
    }
}
