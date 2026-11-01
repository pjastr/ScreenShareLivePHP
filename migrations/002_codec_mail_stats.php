<?php
defined('SSLIVE') || exit;

/*
 * Migracja 002 (wersja 1.2.0):
 * - kodek i jakość zapisywane przy transmisji (historia),
 * - statystyki odbioru widza (widoczne u nauczyciela),
 * - znacznik czasu klatki trybu zapasowego (wiek klatki w statystykach).
 * (Ustawienia e-mail z wersji 1.2–1.3 usuwa migracja 004.)
 */
return function (): void {
    $has = fn(string $table, string $col): bool => (bool)DB::all('SHOW COLUMNS FROM {' . $table . '} LIKE ' . DB::pdo()->quote($col));

    if (!$has('streams', 'codec')) {
        DB::run("ALTER TABLE {streams} ADD COLUMN `codec` VARCHAR(8) NULL AFTER `audio`, ADD COLUMN `quality` VARCHAR(12) NULL AFTER `codec`");
    }
    if (!$has('viewers', 'stats')) {
        DB::run("ALTER TABLE {viewers} ADD COLUMN `stats` VARCHAR(255) NULL AFTER `state`");
    }
    if (!$has('frames', 'ts_ms')) {
        DB::run("ALTER TABLE {frames} ADD COLUMN `ts_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `seq`, ADD COLUMN `bytes_total` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ts_ms`");
    }
};
