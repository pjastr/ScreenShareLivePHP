<?php
/*
 * ScreenShare Live PHP – instalator (w stylu WordPress).
 * Otwórz ten plik w przeglądarce, podaj dane bazy MySQL i konto administratora – resztę zrobi instalator.
 * Licencja: MIT
 */
define('SSLIVE', true);
require __DIR__ . '/app/bootstrap.php';

ini_set('display_errors', '0');
send_security_headers();
start_session();

$lang = $_GET['lang'] ?? ($_SESSION['install_lang'] ?? (I18n::fromBrowser(['pl' => 1, 'en' => 1]) ?? 'pl'));
$lang = in_array($lang, ['pl', 'en'], true) ? $lang : 'pl';
$_SESSION['install_lang'] = $lang;
I18n::init($lang, false);

/** Szuka wolnego prefiksu ssliveXYZ_ (XYZ = losowe cyfry). */
function find_free_prefix(PDO $pdo): string
{
    $tried = [];
    for ($i = 0; $i < 2000 && count($tried) < 900; $i++) {
        $n = random_int(100, 999);
        if (isset($tried[$n])) {
            continue;
        }
        $tried[$n] = true;
        $prefix = 'sslive' . $n . '_';
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote(addcslashes($prefix, '_%\\') . '%'))->fetchColumn();
        if (!$exists) {
            return $prefix;
        }
    }
    throw new RuntimeException('No free table prefix available');
}

function config_php(array $db, string $prefix): string
{
    $export = var_export([
        'db' => [
            'host' => $db['host'], 'port' => (int)$db['port'], 'name' => $db['name'],
            'user' => $db['user'], 'pass' => $db['pass'],
        ],
        'prefix' => $prefix,
        'secret' => bin2hex(random_bytes(32)),
        'debug' => false,
        'installed_at' => gmdate('c'),
    ], true);
    return "<?php\n// Konfiguracja ScreenShare Live PHP – wygenerowana przez instalator. Nie udostępniaj tego pliku.\n"
        . "defined('SSLIVE') || exit;\n\nreturn " . $export . ";\n";
}

function install_page(string $content, int $step): never
{
    $steps = [__('install.step.check'), __('install.step.db'), __('install.step.admin'), __('install.step.done')];
    ob_start(); ?>
    <div class="steps-bar">
      <?php foreach ($steps as $i => $s): ?>
      <div class="sb-step <?= $i < $step ? 'done' : ($i === $step ? 'current' : '') ?>"><span><?= $i < $step ? icon('check') : $i + 1 ?></span><em><?= e($s) ?></em></div>
      <?php endforeach; ?>
    </div>
    <?= $content ?>
    <?php
    $html = ob_get_clean();
    echo '<!doctype html><html lang="' . e(I18n::lang()) . '" data-theme="system"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e(__('install.title')) . '</title>'
        . '<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="' . e(asset('css/app.css')) . '"><script src="' . e(asset('js/theme.js')) . '"></script></head>'
        . '<body class="auth-body"><div class="auth-bg" aria-hidden="true"></div><main class="auth-wrap auth-wide"><div class="brand brand-lg"><span class="brand-mark">' . icon('screen-share') . '</span><span>ScreenShare Live</span></div>'
        . '<div class="card auth-card">' . view('flashes') . $html . '</div>'
        . '<div class="auth-foot"><div class="lang-links">' . icon('language') . '<a href="?lang=pl" class="' . (I18n::lang() === 'pl' ? 'active' : '') . '">Polski</a><a href="?lang=en" class="' . (I18n::lang() === 'en' ? 'active' : '') . '">English</a></div>'
        . '<span class="muted small">v' . e(APP_VERSION) . '</span></div></main><script src="' . e(asset('js/icons.js')) . '"></script><script src="' . e(asset('js/app.js')) . '"></script></body></html>';
    exit;
}

/* ---------- Już zainstalowane? ---------- */
if (is_installed()) {
    $done = !empty($_SESSION['install_done']);
    unset($_SESSION['install_done'], $_SESSION['install_db'], $_SESSION['install_config']);
    ob_start(); ?>
    <div class="auth-icon <?= $done ? 'ok' : '' ?>"><?= icon($done ? 'circle-check' : 'info-circle') ?></div>
    <h1 class="auth-title"><?= e($done ? __('install.success') : __('install.already')) ?></h1>
    <p class="muted auth-sub"><?= e($done ? __('install.success_text') : __('install.already_text')) ?></p>
    <a class="btn btn-primary btn-block" href="login/"><?= e(__('auth.login')) ?></a>
    <?php install_page(ob_get_clean(), 4);
}

$step = $_GET['step'] ?? 'check';

if (is_post() && !csrf_valid($_POST['_csrf'] ?? null)) {
    flash('error', __('common.csrf_error'));
    redirect('install.php?step=' . urlencode((string)$step));
}

/* ---------- Krok 4: zapis config.php ręcznie (gdy brak uprawnień) ---------- */
if ($step === 'config' && !empty($_SESSION['install_config'])) {
    if (isset($_GET['download'])) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="config.php"');
        echo $_SESSION['install_config'];
        exit;
    }
    if (is_post()) {
        if (is_installed()) {
            $_SESSION['install_done'] = true;
            redirect('install.php');
        }
        flash('error', __('install.config_still_missing'));
        redirect('install.php?step=config');
    }
    ob_start(); ?>
    <h1 class="auth-title"><?= e(__('install.config_manual')) ?></h1>
    <p class="muted"><?= e(__('install.config_manual_text')) ?></p>
    <textarea class="code-area" readonly rows="14" id="cfg"><?= e($_SESSION['install_config']) ?></textarea>
    <div class="row-actions">
      <button type="button" class="btn" data-copy="#cfg"><?= icon('copy') ?><?= e(__('common.copy')) ?></button>
      <a class="btn" href="install.php?step=config&amp;download=1"><?= icon('download') ?><?= e(__('common.download')) ?></a>
    </div>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary btn-block" type="submit"><?= e(__('install.config_check')) ?></button></form>
    <?php install_page(ob_get_clean(), 3);
}

/* ---------- Krok 3: administrator ---------- */
if ($step === 'admin' && !empty($_SESSION['install_db'])) {
    $f = ['site_name' => 'ScreenShare Live', 'username' => 'admin', 'email' => '', 'registration' => '1'];
    if (is_post()) {
        $f = ['site_name' => mb_substr(post('site_name'), 0, 80) ?: 'ScreenShare Live', 'username' => post('username'), 'email' => post('email'), 'registration' => isset($_POST['registration']) ? '1' : '0'];
        $pass = (string)($_POST['password'] ?? '');
        $err = null;
        if ($pass !== (string)($_POST['password2'] ?? '')) {
            $err = __('auth.err.password_mismatch');
        } elseif ($e1 = Auth::validateUsername($f['username'])) {
            $err = $e1;
        } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $err = __('auth.err.email');
        } elseif ($e2 = Auth::validatePassword($pass)) {
            $err = $e2;
        }
        if ($err) {
            flash('error', $err);
        } else {
            try {
                $db = $_SESSION['install_db'];
                $pdo = DB::makePdo($db);
                $prefix = find_free_prefix($pdo);
                $config = config_php($db, $prefix);
                $GLOBALS['CONFIG'] = ['secret' => 'install'];
                DB::connect($db, $prefix);
                Migrator::migrate();
                Settings::set('site_name', $f['site_name']);
                Settings::set('registration_enabled', $f['registration']);
                Settings::set('default_lang', $lang);
                Settings::set('installed_at', gmdate('c'));
                Auth::createUser($f['username'], $f['email'], $pass, 'admin');
                DB::update('users', ['lang' => $lang], '`username` = ?', [$f['username']]);
                unset($_SESSION['install_db']);
                if (@file_put_contents(config_path(), $config) !== false) {
                    @chmod(config_path(), 0640);
                    $_SESSION['install_done'] = true;
                    redirect('install.php');
                }
                $_SESSION['install_config'] = $config;
                redirect('install.php?step=config');
            } catch (Throwable $e) {
                flash('error', __('install.failed') . ' ' . $e->getMessage());
            }
        }
    }
    ob_start(); ?>
    <h1 class="auth-title"><?= e(__('install.admin_title')) ?></h1>
    <p class="muted auth-sub"><?= e(__('install.admin_sub')) ?></p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label class="field"><span><?= e(__('admin.site_name')) ?></span><input type="text" name="site_name" value="<?= e($f['site_name']) ?>" maxlength="80" required></label>
      <div class="grid-2 tight">
        <label class="field"><span><?= e(__('auth.username')) ?></span><input type="text" name="username" value="<?= e($f['username']) ?>" required pattern="[A-Za-z0-9._\-]{3,32}" autocomplete="username"></label>
        <label class="field"><span><?= e(__('auth.email')) ?></span><input type="email" name="email" value="<?= e($f['email']) ?>" required autocomplete="email"></label>
        <label class="field"><span><?= e(__('auth.password')) ?></span><input type="password" name="password" required minlength="8" autocomplete="new-password" data-pw-meter><div class="pw-meter"><i></i></div></label>
        <label class="field"><span><?= e(__('auth.password_repeat')) ?></span><input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
      </div>
      <label class="switch-field">
        <input type="checkbox" name="registration" value="1" <?= $f['registration'] === '1' ? 'checked' : '' ?>>
        <span class="switch" aria-hidden="true"></span>
        <span><strong><?= e(__('admin.registration_enabled')) ?></strong><small class="hint"><?= e(__('install.registration_hint')) ?></small></span>
      </label>
      <p class="muted small"><?= icon('info-circle') ?> <?= e(__('install.prefix_info')) ?></p>
      <button class="btn btn-primary btn-block" type="submit"><?= e(__('install.install')) ?></button>
    </form>
    <p class="auth-alt"><a href="install.php?step=db"><?= icon('chevron-left') ?> <?= e(__('common.back')) ?></a></p>
    <?php install_page(ob_get_clean(), 2);
}

/* ---------- Krok 2: baza danych ---------- */
if ($step === 'db' || $step === 'admin') {
    $db = $_SESSION['install_db'] ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''];
    if (is_post()) {
        $db = ['host' => post('host', 'localhost'), 'port' => (int)post('port', '3306') ?: 3306, 'name' => post('name'), 'user' => post('user'), 'pass' => (string)($_POST['pass'] ?? '')];
        try {
            $pdo = DB::makePdo($db);
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $isMaria = stripos($ver, 'mariadb') !== false;
            $num = preg_replace('/[^0-9.].*$/', '', $ver);
            if ((!$isMaria && version_compare($num, '5.7.0', '<')) || ($isMaria && version_compare($num, '10.2.0', '<'))) {
                throw new RuntimeException(__('install.db_too_old', ['v' => $ver]));
            }
            $_SESSION['install_db'] = $db;
            if (post('do') === 'test') {
                $existing = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
                flash('success', __('install.db_ok', ['v' => $ver, 'n' => $existing]));
                redirect('install.php?step=db');
            }
            redirect('install.php?step=admin');
        } catch (Throwable $e) {
            flash('error', __('install.db_failed') . ' ' . ($e instanceof PDOException ? preg_replace('/^SQLSTATE\[[^\]]+\]\s*/', '', $e->getMessage()) : $e->getMessage()));
        }
    }
    ob_start(); ?>
    <h1 class="auth-title"><?= e(__('install.db_title')) ?></h1>
    <p class="muted auth-sub"><?= e(__('install.db_sub')) ?></p>
    <form method="post" class="form" autocomplete="off">
      <?= csrf_field() ?>
      <div class="grid-2 tight">
        <label class="field"><span><?= e(__('install.db_name')) ?></span><input type="text" name="name" value="<?= e($db['name']) ?>" required></label>
        <label class="field"><span><?= e(__('install.db_user')) ?></span><input type="text" name="user" value="<?= e($db['user']) ?>" required></label>
        <label class="field"><span><?= e(__('install.db_pass')) ?></span><input type="password" name="pass" value="<?= e($db['pass']) ?>"></label>
        <div class="grid-2 tight inner">
          <label class="field"><span><?= e(__('install.db_host')) ?></span><input type="text" name="host" value="<?= e($db['host']) ?>" required></label>
          <label class="field"><span><?= e(__('install.db_port')) ?></span><input type="number" name="port" value="<?= (int)$db['port'] ?>" min="1" max="65535"></label>
        </div>
      </div>
      <p class="muted small"><?= icon('info-circle') ?> <?= e(__('install.db_hint')) ?></p>
      <div class="grid-2 tight">
        <button class="btn btn-block" type="submit" name="do" value="test"><?= icon('database') ?><?= e(__('install.db_test')) ?></button>
        <button class="btn btn-primary btn-block" type="submit" name="do" value="next"><?= e(__('install.next')) ?><?= icon('chevron-right') ?></button>
      </div>
    </form>
    <p class="auth-alt"><a href="install.php"><?= icon('chevron-left') ?> <?= e(__('common.back')) ?></a></p>
    <?php install_page(ob_get_clean(), 1);
}

/* ---------- Krok 1: wymagania ---------- */
$checks = [
    [__('install.req.php', ['v' => APP_MIN_PHP]) . ' (' . PHP_VERSION . ')', version_compare(PHP_VERSION, APP_MIN_PHP, '>='), true],
    ['PDO MySQL', extension_loaded('pdo_mysql'), true],
    ['mbstring', extension_loaded('mbstring'), true],
    ['JSON', function_exists('json_encode'), true],
    [__('install.req.config_writable'), is_writable(ROOT), false],
    ['ZipArchive (' . __('install.req.for_updates') . ')', class_exists('ZipArchive'), false],
    ['cURL (' . __('install.req.for_updates') . ')', function_exists('curl_init'), false],
    [__('install.req.https'), is_https() || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true), false],
];
$ok = true;
foreach ($checks as [, $pass, $required]) {
    if ($required && !$pass) {
        $ok = false;
    }
}
ob_start(); ?>
<h1 class="auth-title"><?= e(__('install.welcome')) ?></h1>
<p class="muted auth-sub"><?= e(__('install.welcome_text')) ?></p>
<ul class="checklist">
  <?php foreach ($checks as [$label, $pass, $required]): ?>
  <li class="<?= $pass ? 'ok' : ($required ? 'bad' : 'warn') ?>"><?= icon($pass ? 'circle-check' : ($required ? 'circle-x' : 'alert-triangle')) ?><?= e($label) ?><?= !$required && !$pass ? ' <span class="muted small">— ' . e(__('install.req.optional')) . '</span>' : '' ?></li>
  <?php endforeach; ?>
</ul>
<?php if ($ok): ?>
<a class="btn btn-primary btn-block" href="install.php?step=db"><?= e(__('install.start')) ?></a>
<?php else: ?>
<div class="alert alert-error"><?= icon('alert-triangle') ?><span><?= e(__('install.req_failed')) ?></span></div>
<?php endif; ?>
<?php
install_page(ob_get_clean(), 0);
