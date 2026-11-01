<?php
defined('SSLIVE') || exit;

/*
 * Migracja 003 (wersja 1.3.0): serwery STUN zmieniane przez nauczyciela w trakcie transmisji.
 * `ice` – lista adresów STUN dla tej transmisji (JSON; NULL = ustawienia administratora),
 * `ice_rev` – licznik zmian (widzowie w trybie zapasowym ponawiają wtedy połączenie WebRTC).
 */
return function (): void {
    if (!DB::all("SHOW COLUMNS FROM {streams} LIKE 'ice'")) {
        DB::run('ALTER TABLE {streams} ADD COLUMN `ice` TEXT NULL AFTER `quality`, ADD COLUMN `ice_rev` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ice`');
    }
};
