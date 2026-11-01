<?php
defined('SSLIVE') || exit;

/**
 * Tłumaczenia. Kolejność wyszukiwania: nadpisania z bazy (panel admina) -> plik app/lang/<kod>.php
 * -> angielski -> sam klucz. Dzięki temu admin może dodać dowolny język bez edycji plików.
 */
final class I18n
{
    private static string $lang = 'pl';
    private static array $dict = [];
    private static array $fileCache = [];

    public static function builtinFile(string $code): array
    {
        if (!isset(self::$fileCache[$code])) {
            $f = ROOT . '/app/lang/' . basename($code) . '.php';
            self::$fileCache[$code] = (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $code) && is_file($f)) ? (require $f) : [];
        }
        return self::$fileCache[$code];
    }

    /** Wszystkie klucze znane aplikacji (wzorcem jest angielski i polski plik). */
    public static function allKeys(): array
    {
        $keys = array_unique(array_merge(array_keys(self::builtinFile('en')), array_keys(self::builtinFile('pl'))));
        sort($keys);
        return $keys;
    }

    public static function dbOverrides(string $code): array
    {
        $out = [];
        try {
            foreach (DB::all('SELECT `tkey`,`tvalue` FROM {translations} WHERE `lang` = ?', [$code]) as $r) {
                $out[$r['tkey']] = $r['tvalue'];
            }
        } catch (Throwable $e) {
            // brak tabeli (np. przed migracją) – ignoruj
        }
        return $out;
    }

    public static function init(string $code, bool $useDb = true): void
    {
        self::$lang = $code;
        $dict = self::builtinFile('en');
        if ($useDb) {
            $dict = array_merge($dict, self::dbOverrides('en'));
        }
        if ($code !== 'en') {
            $dict = array_merge($dict, self::builtinFile($code));
            if ($useDb) {
                $dict = array_merge($dict, self::dbOverrides($code));
            }
        }
        self::$dict = $dict;
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function t(string $key, array $params = []): string
    {
        $s = self::$dict[$key] ?? $key;
        if ($params) {
            foreach ($params as $k => $v) {
                $s = str_replace('{' . $k . '}', (string)$v, $s);
            }
        }
        return $s;
    }

    public static function dict(): array
    {
        return self::$dict;
    }

    /** Aktywne języki [kod => nazwa]. */
    public static function languages(bool $onlyActive = true): array
    {
        $out = [];
        try {
            $rows = DB::all('SELECT * FROM {languages} ' . ($onlyActive ? 'WHERE `active` = 1 ' : '') . 'ORDER BY `builtin` DESC, `name`');
            foreach ($rows as $r) {
                $out[$r['code']] = $r['name'];
            }
        } catch (Throwable $e) {
            $out = ['pl' => 'Polski', 'en' => 'English'];
        }
        return $out ?: ['pl' => 'Polski', 'en' => 'English'];
    }

    /** Dopasowanie języka z nagłówka Accept-Language przeglądarki. */
    public static function fromBrowser(array $available): ?string
    {
        $h = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        foreach (explode(',', $h) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));
            if (isset($available[$code])) {
                return $code;
            }
            $short = substr($code, 0, 2);
            if (isset($available[$short])) {
                return $short;
            }
        }
        return null;
    }
}

function __(string $key, array $params = []): string
{
    return I18n::t($key, $params);
}
