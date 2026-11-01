<?php
defined('SSLIVE') || exit;

/*
 * Migracja 001 – schemat początkowy.
 * Każdy plik migracji zwraca funkcję wykonywaną raz. Numer w nazwie pliku = wersja schematu.
 * Nazwy tabel w nawiasach klamrowych są automatycznie poprzedzane prefiksem instalacji.
 */
return function (): void {
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    DB::run("CREATE TABLE IF NOT EXISTS {settings} (
        `name` VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` MEDIUMTEXT NULL
    ) $opts");

    DB::run("CREATE TABLE IF NOT EXISTS {users} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(64) NOT NULL,
        `email` VARCHAR(190) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `role` VARCHAR(16) NOT NULL DEFAULT 'user',
        `status` VARCHAR(16) NOT NULL DEFAULT 'active',
        `totp_secret` VARCHAR(64) NULL,
        `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `totp_last_step` BIGINT NOT NULL DEFAULT 0,
        `backup_codes` TEXT NULL,
        `lang` VARCHAR(12) NULL,
        `theme` VARCHAR(12) NOT NULL DEFAULT 'system',
        `created_at` DATETIME NOT NULL,
        `last_login_at` DATETIME NULL,
        `last_login_ip` VARCHAR(45) NULL,
        UNIQUE KEY `uniq_username` (`username`),
        UNIQUE KEY `uniq_email` (`email`)
    ) $opts");

    // Transmisje. `active_slot` = 1 tylko dla trwającej transmisji; indeks UNIQUE gwarantuje,
    // że w danej chwili może trwać najwyżej jedna transmisja (także przy równoczesnych żądaniach).
    DB::run("CREATE TABLE IF NOT EXISTS {streams} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(190) NOT NULL,
        `code` CHAR(6) NOT NULL,
        `status` VARCHAR(12) NOT NULL DEFAULT 'live',
        `active_slot` TINYINT UNSIGNED NULL,
        `max_viewers` TINYINT UNSIGNED NOT NULL DEFAULT 25,
        `peak_viewers` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `total_viewers` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `audio` TINYINT(1) NOT NULL DEFAULT 0,
        `pub_token` CHAR(32) NULL,
        `pub_seen_at` DATETIME NULL,
        `started_at` DATETIME NOT NULL,
        `ends_at` DATETIME NOT NULL,
        `ended_at` DATETIME NULL,
        `end_reason` VARCHAR(16) NULL,
        UNIQUE KEY `uniq_active` (`active_slot`),
        KEY `idx_user` (`user_id`, `started_at`),
        KEY `idx_started` (`started_at`)
    ) $opts");

    DB::run("CREATE TABLE IF NOT EXISTS {viewers} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `stream_id` INT UNSIGNED NOT NULL,
        `token` CHAR(32) NOT NULL,
        `name` VARCHAR(40) NOT NULL DEFAULT '',
        `ip` VARCHAR(45) NOT NULL DEFAULT '',
        `mode` VARCHAR(8) NOT NULL DEFAULT 'webrtc',
        `state` VARCHAR(12) NOT NULL DEFAULT 'waiting',
        `status` VARCHAR(8) NOT NULL DEFAULT 'active',
        `joined_at` DATETIME NOT NULL,
        `last_seen_at` DATETIME NOT NULL,
        UNIQUE KEY `uniq_token` (`token`),
        KEY `idx_stream` (`stream_id`, `status`, `last_seen_at`)
    ) $opts");

    // Wiadomości sygnalizacyjne WebRTC (offer/answer/ICE). dir: 0 = do widza, 1 = do nauczyciela.
    DB::run("CREATE TABLE IF NOT EXISTS {signals} (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `stream_id` INT UNSIGNED NOT NULL,
        `viewer_id` INT UNSIGNED NOT NULL,
        `dir` TINYINT UNSIGNED NOT NULL,
        `type` VARCHAR(12) NOT NULL,
        `payload` MEDIUMTEXT NULL,
        `created_at` DATETIME NOT NULL,
        KEY `idx_target` (`stream_id`, `dir`, `viewer_id`, `id`)
    ) $opts");

    // Ostatnia klatka obrazu dla widzów w trybie zapasowym (gdy WebRTC jest zablokowane).
    DB::run("CREATE TABLE IF NOT EXISTS {frames} (
        `stream_id` INT UNSIGNED NOT NULL PRIMARY KEY,
        `seq` INT UNSIGNED NOT NULL DEFAULT 0,
        `mime` VARCHAR(20) NOT NULL,
        `data` MEDIUMBLOB NOT NULL,
        `updated_at` DATETIME NOT NULL
    ) $opts");

    DB::run("CREATE TABLE IF NOT EXISTS {languages} (
        `code` VARCHAR(12) NOT NULL PRIMARY KEY,
        `name` VARCHAR(64) NOT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `builtin` TINYINT(1) NOT NULL DEFAULT 0
    ) $opts");

    DB::run("CREATE TABLE IF NOT EXISTS {translations} (
        `lang` VARCHAR(12) NOT NULL,
        `tkey` VARCHAR(128) NOT NULL,
        `tvalue` TEXT NOT NULL,
        PRIMARY KEY (`lang`, `tkey`)
    ) $opts");

    DB::run("CREATE TABLE IF NOT EXISTS {login_attempts} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `ip` VARCHAR(45) NOT NULL,
        `ident` VARCHAR(190) NOT NULL,
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        KEY `idx_ip` (`ip`, `created_at`),
        KEY `idx_ident` (`ident`, `created_at`)
    ) $opts");

    DB::run("INSERT IGNORE INTO {languages} (`code`,`name`,`active`,`builtin`) VALUES ('pl','Polski',1,1),('en','English',1,1)");

    $defaults = [
        'site_name' => 'ScreenShare Live',
        'registration_enabled' => '1',
        'require_2fa' => 'none',
        'default_lang' => 'pl',
        'live_default_minutes' => '90',
        'live_max_viewers' => '25',
        'live_name_mode' => 'optional',
        'live_quality' => 'balanced',
        'live_ice_servers' => "stun:stun.l.google.com:19302",
        'live_turn_url' => '',
        'live_turn_user' => '',
        'live_turn_pass' => '',
        'live_fallback' => '1',
        'update_repo' => APP_UPDATE_REPO,
    ];
    foreach ($defaults as $k => $v) {
        DB::run('INSERT IGNORE INTO {settings} (`name`,`value`) VALUES (?,?)', [$k, $v]);
    }
};
