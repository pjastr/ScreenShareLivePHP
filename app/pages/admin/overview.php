<?php
defined('SSLIVE') || exit;

if (is_post() && post('action') === 'toggle_registration') {
    Settings::set('registration_enabled', Settings::bool('registration_enabled') ? '0' : '1');
    flash('success', Settings::bool('registration_enabled') ? __('admin.reg_now_on') : __('admin.reg_now_off'));
    redirect(url('admin'));
}

$stats = [
    ['users', __('admin.stat.users'), (int)DB::value('SELECT COUNT(*) FROM {users}')],
    ['shield-lock', __('admin.stat.admins'), (int)DB::value("SELECT COUNT(*) FROM {users} WHERE `role` = 'admin'")],
    ['history', __('admin.stat.streams'), (int)DB::value('SELECT COUNT(*) FROM {streams}')],
    ['clock', __('admin.stat.hours'), round((int)DB::value('SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, `started_at`, COALESCE(`ended_at`, UTC_TIMESTAMP()))),0) FROM {streams}') / 3600, 1)],
];
$live = Live::active();
$twofa = (int)DB::value('SELECT COUNT(*) FROM {users} WHERE `totp_enabled` = 1');
$totalUsers = max(1, $stats[0][2]);
$regOn = Settings::bool('registration_enabled');
$dbVersion = '';
try {
    $dbVersion = (string)DB::value('SELECT VERSION()');
} catch (Throwable $e) {
}

ob_start(); ?>
<div class="page-head">
  <div>
    <h1><?= e(__('admin.nav.overview')) ?></h1>
    <p class="muted"><?= e(__('admin.overview_sub')) ?></p>
  </div>
</div>
<div class="stats">
  <?php foreach ($stats as [$ic, $label, $val]): ?>
  <div class="stat card<?= $ic === 'history' ? ' stat-link' : '' ?>">
    <span class="stat-ic"><?= icon($ic) ?></span>
    <div><div class="stat-val"><?= e((string)$val) ?></div><div class="muted small"><?php if ($ic === 'history'): ?><a href="<?= e(url('admin-streams')) ?>"><?= e($label) ?> →</a><?php else: ?><?= e($label) ?><?php endif; ?></div></div>
  </div>
  <?php endforeach; ?>
</div>
<section class="card live-now">
  <h2 class="card-title"><?= icon('broadcast') ?><?= e(__('admin.live_now')) ?></h2>
  <?php if ($live): ?>
  <div class="live-now-row">
    <div>
      <span class="live-pill connected"><i></i><?= e(__('live.state.live')) ?></span>
      <strong><?= e($live['title']) ?></strong>
      <div class="muted small"><?= icon('user') ?><?= e($live['username'] ?? '?') ?> · <?= e(__('live.started')) ?> <?= e(format_date($live['started_at'])) ?> · <?= e(__('live.until', ['time' => date('H:i', Live::ts($live['ends_at']))])) ?> · <?= icon('users') ?><?= Live::viewerCount((int)$live['id']) ?> / <?= (int)$live['max_viewers'] ?></div>
    </div>
    <div class="live-now-code"><span class="muted small"><?= e(__('live.code')) ?></span><code class="code-inline big"><?= e(chunk_split($live['code'], 3, ' ')) ?></code></div>
    <form method="post" action="<?= e(url('share')) ?>" data-confirm="<?= e(__('live.end_confirm_admin')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="end_stream"><input type="hidden" name="sid" value="<?= (int)$live['id'] ?>"><input type="hidden" name="back" value="admin">
      <button class="btn btn-danger" type="submit"><?= icon('player-stop') ?><?= e(__('live.end')) ?></button>
    </form>
  </div>
  <?php else: ?>
  <p class="muted"><?= e(__('admin.live_none')) ?> <a href="<?= e(url('share')) ?>"><?= e(__('live.start')) ?> →</a></p>
  <?php endif; ?>
</section>
<div class="grid-2">
  <section class="card">
    <h2 class="card-title"><?= icon('user-plus') ?><?= e(__('admin.registration')) ?></h2>
    <p class="muted"><?= e($regOn ? __('admin.reg_on_text') : __('admin.reg_off_text')) ?></p>
    <form method="post" class="toggle-row">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_registration">
      <span class="badge <?= $regOn ? 'badge-success' : 'badge-warn' ?>"><?= e($regOn ? __('common.enabled') : __('common.disabled')) ?></span>
      <button class="btn" type="submit"><?= e($regOn ? __('admin.reg_disable') : __('admin.reg_enable')) ?></button>
    </form>
    <hr class="sep">
    <h2 class="card-title"><?= icon('shield-check') ?><?= e(__('profile.2fa')) ?></h2>
    <div class="progress"><i style="width: <?= round($twofa / $totalUsers * 100) ?>%"></i></div>
    <p class="muted small"><?= e(__('admin.2fa_usage', ['n' => $twofa, 'total' => $stats[0][2]])) ?></p>
  </section>
  <section class="card">
    <h2 class="card-title"><?= icon('info-circle') ?><?= e(__('admin.system')) ?></h2>
    <dl class="kv">
      <dt><?= e(__('admin.version_files')) ?></dt><dd><?= e(APP_VERSION) ?></dd>
      <dt><?= e(__('dbupdate.schema')) ?></dt><dd><?= (int)Migrator::current() ?></dd>
      <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
      <dt>MySQL / MariaDB</dt><dd><?= e($dbVersion) ?></dd>
      <dt><?= e(__('admin.prefix')) ?></dt><dd><code><?= e(DB::prefix()) ?></code></dd>
      <dt>HTTPS</dt><dd><?= is_https() ? e(__('common.enabled')) : '<span class="badge badge-warn">' . e(__('admin.https_missing')) . '</span>' ?></dd>
      <dt><?= e(__('admin.viewer_url')) ?></dt><dd><a href="<?= e(url('home')) ?>" target="_blank" rel="noopener"><?= e(Live::viewerUrl()) ?></a></dd>
    </dl>
    <div class="row-actions">
      <a class="btn" href="<?= e(url('admin-update')) ?>"><?= icon('refresh') ?><?= e(__('admin.nav.update')) ?></a>
      <a class="btn" href="<?= e(url('admin-backup')) ?>"><?= icon('database-export') ?><?= e(__('admin.backup_download')) ?></a>
    </div>
  </section>
</div>
<?php
echo view('layout_app', ['title' => __('nav.admin'), 'content' => ob_get_clean(), 'active' => 'admin', 'admin' => true, 'extraCss' => ['css/live.css']]);
