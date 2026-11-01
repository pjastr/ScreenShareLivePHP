<?php
defined('SSLIVE') || exit;

/**
 * Automatyczne migracje schematu bazy (jak "Wymagana aktualizacja bazy danych" w WordPress).
 * Pliki migrations/NNN_opis.php są wykonywane rosnąco; numer ostatniej zapisuje się w ustawieniu db_version.
 */
final class Migrator
{
    public static function dir(): string
    {
        return ROOT . '/migrations';
    }

    /** @return array<int,string> wersja => ścieżka */
    public static function available(): array
    {
        $list = [];
        foreach (glob(self::dir() . '/*.php') ?: [] as $file) {
            if (preg_match('/^(\d+)_[a-z0-9_]+\.php$/i', basename($file), $m)) {
                $list[(int)$m[1]] = $file;
            }
        }
        ksort($list);
        return $list;
    }

    public static function latest(): int
    {
        $a = self::available();
        return $a ? (int)array_key_last($a) : 0;
    }

    public static function current(): int
    {
        if (!DB::tableExists('settings')) {
            return 0;
        }
        return (int)(DB::value('SELECT `value` FROM {settings} WHERE `name` = ?', ['db_version']) ?? 0);
    }

    public static function needsUpdate(): bool
    {
        return self::current() < self::latest();
    }

    /** Wykonuje brakujące migracje. Zwraca listę wykonanych wersji. */
    public static function migrate(): array
    {
        $done = [];
        $current = self::current();
        foreach (self::available() as $version => $file) {
            if ($version <= $current) {
                continue;
            }
            $fn = require $file;
            if (!is_callable($fn)) {
                throw new RuntimeException('Invalid migration file: ' . basename($file));
            }
            $fn();
            DB::run('INSERT INTO {settings} (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', ['db_version', (string)$version]);
            $done[] = $version;
        }
        DB::run('INSERT INTO {settings} (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', ['app_version', APP_VERSION]);
        return $done;
    }
}
