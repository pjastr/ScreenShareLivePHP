<?php
defined('SSLIVE') || exit;

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        // Skrypty w podkatalogach (login/, panel/) wskazują katalog główny aplikacji.
        if (defined('IN_SUBDIR')) {
            $dir = str_replace('\\', '/', dirname($dir));
        }
        $base = rtrim($dir, '/') . '/';
    }
    return $base;
}

/**
 * Adres strony aplikacji. Logowanie: domena/login/, panel: domena/panel/?p=..., widzowie: domena/.
 */
function url(string $page = '', array $params = []): string
{
    if ($page === 'login') {
        return base_url() . 'login/' . ($params ? '?' . http_build_query($params) : '');
    }
    if ($page === 'home') {
        return base_url() . ($params ? '?' . http_build_query($params) : '');
    }
    $dir = in_array($page, ['register', '2fa'], true) ? 'login/' : 'panel/';
    $q = $page !== '' && $page !== 'share' ? ['p' => $page] + $params : $params;
    return base_url() . $dir . ($q ? '?' . http_build_query($q) : '');
}

function asset(string $path): string
{
    $file = ROOT . '/assets/' . $path;
    $v = is_file($file) ? substr(md5((string)filemtime($file) . APP_VERSION), 0, 8) : APP_VERSION;
    return base_url() . 'assets/' . $path . '?v=' . $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/* ---------- Flash ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Widoki ---------- */

function view(string $name, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require ROOT . '/app/views/' . $name . '.php';
    return (string)ob_get_clean();
}

function icon(string $name, string $class = ''): string
{
    static $icons = null;
    if ($icons === null) {
        $icons = require ROOT . '/app/lib/icons_data.php';
    }
    $d = $icons[$name] ?? '';
    if ($d === '') {
        return '';
    }
    $filled = $d[0] === 'F';
    $inner = substr($d, 1);
    $attrs = $filled ? 'fill="currentColor" stroke="none"' : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    return '<svg class="ic ' . e($class) . '" viewBox="0 0 24 24" width="20" height="20" ' . $attrs . ' aria-hidden="true">' . $inner . '</svg>';
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('sslive_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_url(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function format_bytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float)$b;
    while ($v >= 1024 && $i < 3) {
        $v /= 1024;
        $i++;
    }
    return round($v, $i ? 1 : 0) . ' ' . $u[$i];
}

function format_date(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $ts = strtotime($utc . ' UTC');
    return date('Y-m-d H:i', $ts);
}

function app_secret(): string
{
    return (string)($GLOBALS['CONFIG']['secret'] ?? 'sslive');
}

function valid_lang_code(string $c): bool
{
    return (bool)preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $c);
}
