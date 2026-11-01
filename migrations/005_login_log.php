<?php
defined('SSLIVE') || exit;

/*
 * Migracja 005 (wersja 1.5.0) – dziennik logowań (jak w tablica-php 1.3.0).
 */
return function (): void {
    DB::run("CREATE TABLE IF NOT EXISTS {login_log} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NULL,
        `username` VARCHAR(64) NULL,
        `ident` VARCHAR(190) NOT NULL DEFAULT '',
        `event` VARCHAR(16) NOT NULL DEFAULT 'login',
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        `reason` VARCHAR(24) NOT NULL DEFAULT '',
        `ip` VARCHAR(45) NOT NULL DEFAULT '',
        `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL,
        KEY `idx_created` (`created_at`),
        KEY `idx_user` (`user_id`, `created_at`),
        KEY `idx_ip` (`ip`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    DB::run('INSERT IGNORE INTO {settings} (`name`,`value`) VALUES (?,?)', ['login_log_days', '90']);
};
