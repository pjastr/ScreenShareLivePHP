<?php
defined('SSLIVE') || exit;

/*
 * Migracja 004 (wersja 1.4.0): usunięcie powiadomień e-mail.
 * Kasuje zapisane ustawienia poczty (w tym hasło SMTP) z wersji 1.2.0–1.3.0.
 */
return function (): void {
    DB::run("DELETE FROM {settings} WHERE `name` IN ('notify_mode','notify_to','notify_last_day','mail_method','mail_from','mail_from_name',
        'smtp_host','smtp_port','smtp_secure','smtp_user','smtp_pass','mail_last_status')");
};
