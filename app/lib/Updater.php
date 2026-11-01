<?php
defined('SSLIVE') || exit;

/**
 * Aktualizacja plików aplikacji: z wydania GitHub (Releases) albo z wgranej paczki ZIP.
 * Pliki konfiguracyjne (config.php) i katalog storage/ nigdy nie są nadpisywane.
 * Po podmianie plików zmiany schematu bazy wykonuje Migrator (strona "Aktualizacja bazy danych").
 */
final class Updater
{
    private const SKIP = ['config.php', 'storage', '.git', '.github', '.gitignore', 'tools'];

    public static function tmpDir(): string
    {
        $d = ROOT . '/storage/tmp';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function requirements(): array
    {
        return [
            'zip' => class_exists('ZipArchive'),
            'writable' => is_writable(ROOT) && is_writable(ROOT . '/app') && is_writable(ROOT . '/assets'),
            'http' => function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
            'storage' => is_writable(self::tmpDir()),
        ];
    }

    public static function http(string $url, ?string $saveTo = null): string
    {
        $headers = ['User-Agent: screensharelive-php-updater', 'Accept: application/vnd.github+json'];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fh = null;
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            if ($saveTo) {
                $fh = fopen($saveTo, 'wb');
                curl_setopt($ch, CURLOPT_FILE, $fh);
            } else {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            }
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($fh) {
                fclose($fh);
            }
            if ($body === false || $code >= 400) {
                throw new RuntimeException('HTTP ' . $code . ' ' . $err);
            }
            return $saveTo ? '' : (string)$body;
        }
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 120, 'follow_location' => 1]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed');
        }
        if ($saveTo) {
            file_put_contents($saveTo, $body);
            return '';
        }
        return $body;
    }

    /** Najnowsze wydanie z GitHub: ['version','zip','notes','url'] */
    public static function latestRelease(string $repo): array
    {
        try {
            $json = json_decode(self::http('https://api.github.com/repos/' . $repo . '/releases/latest'), true);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'HTTP 404')) {
                throw new RuntimeException(__('update.no_releases'));
            }
            throw $e;
        }
        if (!is_array($json) || empty($json['tag_name'])) {
            throw new RuntimeException(__('update.no_releases'));
        }
        $zip = $json['zipball_url'] ?? '';
        foreach (($json['assets'] ?? []) as $a) {
            // preferuj paczkę dołączoną do wydania (np. screensharelive-php-1.2.0.zip)
            if (str_ends_with((string)($a['name'] ?? ''), '.zip')) {
                $zip = $a['browser_download_url'];
                break;
            }
        }
        return [
            'version' => ltrim((string)$json['tag_name'], 'vV'),
            'zip' => $zip,
            'notes' => (string)($json['body'] ?? ''),
            'url' => (string)($json['html_url'] ?? ''),
            'name' => (string)($json['name'] ?? $json['tag_name']),
        ];
    }

    /** Instaluje paczkę ZIP. Zwraca wersję z paczki. */
    public static function installZip(string $zipFile): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension missing');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Cannot open ZIP');
        }
        // Bezpieczeństwo: odrzuć ścieżki wychodzące poza katalog
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (str_contains($n, '..') || str_starts_with($n, '/') || preg_match('#^[A-Za-z]:#', $n)) {
                $zip->close();
                throw new RuntimeException('Unsafe path in ZIP: ' . $n);
            }
        }
        $dest = self::tmpDir() . '/update_' . bin2hex(random_bytes(4));
        mkdir($dest, 0775, true);
        $zip->extractTo($dest);
        $zip->close();

        try {
            $root = self::findRoot($dest);
            if ($root === null) {
                throw new RuntimeException('This ZIP is not a screensharelive-php package');
            }
            $verFile = file_get_contents($root . '/app/version.php');
            if (!str_contains($verFile, "APP_NAME = 'screensharelive-php'") || !preg_match("/APP_VERSION = '([^']+)'/", $verFile, $m)) {
                throw new RuntimeException('This ZIP is not a screensharelive-php package');
            }
            if (preg_match("/APP_MIN_PHP = '([^']+)'/", $verFile, $mp) && version_compare(PHP_VERSION, $mp[1], '<')) {
                throw new RuntimeException('New version requires PHP ' . $mp[1]);
            }
            self::copyTree($root, ROOT);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            return $m[1];
        } finally {
            self::rmTree($dest);
        }
    }

    private static function findRoot(string $dir, int $depth = 0): ?string
    {
        if (is_file($dir . '/app/version.php') && is_file($dir . '/index.php')) {
            return $dir;
        }
        if ($depth > 2) {
            return null;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..' && is_dir($dir . '/' . $f)) {
                if ($r = self::findRoot($dir . '/' . $f, $depth + 1)) {
                    return $r;
                }
            }
        }
        return null;
    }

    private static function copyTree(string $src, string $dst, string $rel = ''): void
    {
        foreach (scandir($src) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $r = ltrim($rel . '/' . $f, '/');
            if ($rel === '' && in_array($f, self::SKIP, true)) {
                continue;
            }
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            if (is_dir($s)) {
                if (!is_dir($d) && !mkdir($d, 0775, true)) {
                    throw new RuntimeException('Cannot create directory: ' . $r);
                }
                self::copyTree($s, $d, $r);
            } else {
                if (!@copy($s, $d)) {
                    throw new RuntimeException('Cannot write file: ' . $r);
                }
            }
        }
    }

    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? self::rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
