<?php
/*
 * ScreenShare Live PHP – udostępnianie ekranu na żywo podczas zajęć (PHP + MySQL).
 * Strona główna (domena/): widzowie wpisują kod i oglądają transmisję – bez logowania.
 * Logowanie nauczycieli: domena/login/, panel: domena/panel/.
 * Licencja: MIT (zob. LICENSE)
 */
define('SSLIVE', true);
require __DIR__ . '/app/bootstrap.php';
app_boot();

if (Migrator::needsUpdate()) {
    http_response_code(503);
    echo view('layout_auth', [
        'title' => __('maint.title'),
        'content' => '<h1 class="auth-title">' . e(__('maint.title')) . '</h1><p class="muted">' . e(__('maint.text')) . '</p>',
    ]);
    exit;
}

$stream = Live::active();
$nameMode = Settings::get('live_name_mode', 'optional'); // off | optional | required
$acc = $_SESSION['live_access'] ?? null;
// Dostęp przyznany kodem obowiązuje dla tej transmisji, dopóki nauczyciel nie zmieni kodu.
$hasAccess = $stream && is_array($acc) && (int)$acc['sid'] === (int)$stream['id'] && hash_equals((string)$stream['code'], (string)($acc['code'] ?? ''));

$f = ['code' => preg_replace('/\D/', '', (string)($_GET['code'] ?? '')), 'name' => (string)($_SESSION['live_name'] ?? '')];

/** Sprawdza kod; zwraca komunikat błędu albo null (dostęp przyznany). */
function try_code(?array $stream, string $code, string $name, string $nameMode): ?string
{
    if (!$stream) {
        return __('live.view.no_stream');
    }
    if (Live::codeLocked()) {
        return __('live.view.locked');
    }
    if ($nameMode === 'required' && $name === '') {
        return __('live.view.name_required');
    }
    if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string)$stream['code'], $code)) {
        Live::recordCodeAttempt(false);
        return __('live.view.bad_code');
    }
    Live::recordCodeAttempt(true);
    session_regenerate_id(true);
    $_SESSION['live_access'] = ['sid' => (int)$stream['id'], 'code' => $code, 'name' => $nameMode === 'off' ? '' : $name];
    $_SESSION['live_name'] = $name;
    return null;
}

if (is_post()) {
    if (!csrf_valid($_POST['_csrf'] ?? null)) {
        flash('error', __('common.csrf_error'));
        redirect(url('home'));
    }
    if (post('action') === 'leave') {
        unset($_SESSION['live_access']);
        redirect(url('home'));
    }
    $code = preg_replace('/\D/', '', post('code'));
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', post('name'))), 0, 40);
    $err = try_code($stream, $code, $name, $nameMode);
    if ($err) {
        flash('error', $err);
        redirect(url('home', $code !== '' ? ['code' => $code] : []));
    }
    redirect(url('home'));
}

// Link z kodem (np. z kodu QR): jeśli imię nie jest wymagane, wpuść od razu.
if (!$hasAccess && $stream && $f['code'] !== '' && $nameMode !== 'required' && !Live::codeLocked()) {
    if (try_code($stream, $f['code'], $f['name'], $nameMode) === null) {
        redirect(url('home'));
    }
    flash('error', __('live.view.bad_code'));
    redirect(url('home'));
}

if ($hasAccess) {
    $liveCfg = [
        'sid' => (int)$stream['id'],
        'stream' => Live::publicInfo($stream, true),
        'ice' => Live::iceServers(),
        'fallback' => Settings::bool('live_fallback'),
        'name' => (string)($acc['name'] ?? ''),
    ];
    echo view('layout_live', ['title' => $stream['title'], 'liveCfg' => $liveCfg]);
    exit;
}

ob_start();
if (!$stream): ?>
  <div class="auth-icon"><?= icon('broadcast-off') ?></div>
  <h1 class="auth-title"><?= e(__('live.view.no_stream')) ?></h1>
  <p class="muted auth-sub"><?= e(__('live.view.no_stream_text')) ?></p>
  <div class="wait-dot" data-live-wait><span></span><?= e(__('live.view.waiting')) ?></div>
<?php else: ?>
  <div class="auth-icon live-on"><?= icon('broadcast') ?></div>
  <h1 class="auth-title"><?= e(__('live.view.title')) ?></h1>
  <p class="muted auth-sub"><?= e(__('live.view.enter_code')) ?></p>
  <form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?>
    <label class="field"><span><?= e(__('live.code')) ?></span>
      <input type="text" name="code" class="code-input code-big" value="<?= e($f['code']) ?>" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus placeholder="000000" aria-label="<?= e(__('live.code')) ?>">
    </label>
    <?php if ($nameMode !== 'off'): ?>
    <label class="field"><span><?= e(__('live.view.name')) ?><?= $nameMode === 'optional' ? ' <small class="muted">(' . e(__('live.view.optional')) . ')</small>' : '' ?></span>
      <input type="text" name="name" value="<?= e($f['name']) ?>" maxlength="40" <?= $nameMode === 'required' ? 'required' : '' ?> autocomplete="given-name">
    </label>
    <?php endif; ?>
    <button class="btn btn-primary btn-block" type="submit"><?= icon('player-play') ?><?= e(__('live.view.watch')) ?></button>
  </form>
<?php endif; ?>
  <?php if (Auth::user()): ?>
  <p class="auth-alt"><a href="<?= e(url('share')) ?>"><?= icon('user') ?> <?= e(__('live.view.to_panel')) ?></a></p>
  <?php endif; ?>
<?php
echo view('layout_auth', ['title' => __('live.view.title'), 'content' => ob_get_clean(), 'extraJs' => ['js/home.js'], 'extraCss' => ['css/live.css']]);
