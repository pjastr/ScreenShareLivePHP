<?php
defined('SSLIVE') || exit;

final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (DB::all('SELECT `name`,`value` FROM {settings}') as $r) {
                self::$cache[$r['name']] = $r['value'];
            }
        }
        return self::$cache;
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $all = self::all();
        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    public static function bool(string $name): bool
    {
        return self::get($name, '0') === '1';
    }

    public static function set(string $name, ?string $value): void
    {
        DB::run('INSERT INTO {settings} (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
        if (self::$cache !== null) {
            self::$cache[$name] = $value;
        }
    }
}
