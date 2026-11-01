<?php
defined('SSLIVE') || exit;

define('ROOT', dirname(__DIR__));

require ROOT . '/app/version.php';
require ROOT . '/app/lib/helpers.php';
require ROOT . '/app/lib/DB.php';
require ROOT . '/app/lib/Settings.php';
require ROOT . '/app/lib/I18n.php';
require ROOT . '/app/lib/Migrator.php';
require ROOT . '/app/lib/Totp.php';
require ROOT . '/app/lib/Auth.php';
require ROOT . '/app/lib/Live.php';
require ROOT . '/app/lib/Mailer.php';
require ROOT . '/app/lib/Notifier.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

if (version_compare(PHP_VERSION, APP_MIN_PHP, '<')) {
    http_response_code(500);
    exit('screensharelive-php requires PHP ' . APP_MIN_PHP . ' or newer (current: ' . PHP_VERSION . ').');
}

function config_path(): string
{
    return ROOT . '/config.php';
}

function is_installed(): bool
{
    return is_file(config_path());
}

function fatal_page(string $title, string $message, int $code = 500): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '"></head><body class="auth-body"><main class="auth-wrap"><div class="card auth-card">'
        . '<h1 class="auth-title">' . e($title) . '</h1><p class="muted">' . $message . '</p></div></main></body></html>';
    exit;
}

/**
 * Uruchomienie aplikacji: konfiguracja, baza, sesja, język.
 */
function app_boot(): void
{
    $debug = false;
    if (!is_installed()) {
        if (PHP_SAPI !== 'cli') {
            redirect(base_url() . 'install.php');
        }
        exit("Not installed\n");
    }
    $config = require config_path();
    $GLOBALS['CONFIG'] = $config;
    $debug = !empty($config['debug']);
    ini_set('display_errors', $debug ? '1' : '0');
    error_reporting(E_ALL);

    try {
        DB::connect($config['db'], $config['prefix']);
    } catch (Throwable $e) {
        fatal_page('Database error', 'Cannot connect to the database. Check <code>config.php</code>.' . ($debug ? '<br><small>' . e($e->getMessage()) . '</small>' : ''));
    }

    send_security_headers();
    start_session();

    // Język
    $dbReady = DB::tableExists('settings');
    $languages = $dbReady ? I18n::languages() : ['pl' => 'Polski', 'en' => 'English'];
    if (isset($_GET['lang']) && is_string($_GET['lang']) && isset($languages[$_GET['lang']])) {
        $_SESSION['lang'] = $_GET['lang'];
    }
    $lang = null;
    if ($dbReady && DB::tableExists('users') && ($u = Auth::user()) && !empty($u['lang']) && isset($languages[$u['lang']])) {
        $lang = $u['lang'];
        if (isset($_GET['lang']) && $_GET['lang'] !== $u['lang'] && isset($languages[$_GET['lang']])) {
            DB::update('users', ['lang' => $_GET['lang']], '`id` = ?', [(int)$u['id']]);
            $lang = $_GET['lang'];
        }
    }
    $lang = $lang
        ?? (isset($_SESSION['lang'], $languages[$_SESSION['lang']]) ? $_SESSION['lang'] : null)
        ?? I18n::fromBrowser($languages)
        ?? ($dbReady ? Settings::get('default_lang', 'pl') : 'pl');
    if (!isset($languages[$lang])) {
        $lang = array_key_first($languages);
    }
    I18n::init($lang, $dbReady);

    // Porządki transmisji (koniec po czasie / po utracie nauczyciela) – bez potrzeby crona
    if ($dbReady && !Migrator::needsUpdate() && DB::tableExists('streams')) {
        try {
            Live::sweep();
        } catch (Throwable $e) {
            // porządki nie mogą zablokować działania aplikacji
        }
    }
}
