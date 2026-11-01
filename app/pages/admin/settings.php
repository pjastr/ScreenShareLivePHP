<?php
defined('SSLIVE') || exit;

$langs = I18n::languages();
if (is_post()) {
    $name = mb_substr(post('site_name'), 0, 80);
    Settings::set('site_name', $name !== '' ? $name : 'ScreenShare Live');
    Settings::set('registration_enabled', isset($_POST['registration_enabled']) ? '1' : '0');
    $req = post('require_2fa');
    Settings::set('require_2fa', in_array($req, ['none', 'admins', 'all'], true) ? $req : 'none');
    $dl = post('default_lang');
    if (isset($langs[$dl])) {
        Settings::set('default_lang', $dl);
    }
    $repo = post('update_repo');
    Settings::set('update_repo', preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : APP_UPDATE_REPO);
    // Transmisje
    Settings::set('live_default_minutes', (string)max(Live::MIN_MINUTES, min(Live::MAX_MINUTES, (int)post('live_default_minutes', '90'))));
    Settings::set('live_max_viewers', (string)max(1, min(Live::MAX_VIEWERS, (int)post('live_max_viewers', (string)Live::MAX_VIEWERS))));
    $nm = post('live_name_mode');
    Settings::set('live_name_mode', in_array($nm, ['off', 'optional', 'required'], true) ? $nm : 'optional');
    $lq = post('live_quality');
    Settings::set('live_quality', isset(Live::qualities()[$lq]) ? $lq : 'balanced');
    Settings::set('live_fallback', isset($_POST['live_fallback']) ? '1' : '0');
    $ice = array_filter(preg_split('/[\s,]+/', post('live_ice_servers')) ?: [], fn($u) => (bool)preg_match('#^stuns?:[A-Za-z0-9.\-]+(:\d+)?$#', $u));
    Settings::set('live_ice_servers', implode("\n", array_slice(array_values($ice), 0, 6)));
    $turn = array_filter(preg_split('/[\s,]+/', post('live_turn_url')) ?: [], fn($u) => (bool)preg_match('#^turns?:[^\s]+$#', $u));
    Settings::set('live_turn_url', implode("\n", array_slice(array_values($turn), 0, 4)));
    Settings::set('live_turn_user', mb_substr(post('live_turn_user'), 0, 190));
    if (isset($_POST['live_turn_pass']) && $_POST['live_turn_pass'] !== '********') {
        Settings::set('live_turn_pass', mb_substr((string)$_POST['live_turn_pass'], 0, 190));
    }
    flash('success', __('common.saved'));
    redirect(url('admin-settings'));
}

$req = Settings::get('require_2fa', 'none');
ob_start(); ?>
<div class="page-head"><div><h1><?= e(__('admin.nav.settings')) ?></h1><p class="muted"><?= e(__('admin.settings_sub')) ?></p></div></div>
<form method="post" class="card form form-wide">
  <?= csrf_field() ?>
  <label class="field"><span><?= e(__('admin.site_name')) ?></span><input type="text" name="site_name" maxlength="80" value="<?= e(Settings::get('site_name', 'ScreenShare Live')) ?>" required></label>

  <label class="switch-field">
    <input type="checkbox" name="registration_enabled" value="1" <?= Settings::bool('registration_enabled') ? 'checked' : '' ?>>
    <span class="switch" aria-hidden="true"></span>
    <span><strong><?= e(__('admin.registration_enabled')) ?></strong><small class="hint"><?= e(__('admin.registration_hint')) ?></small></span>
  </label>

  <div class="field"><span><?= e(__('admin.require_2fa')) ?></span>
    <div class="radio-list">
      <?php foreach (['none', 'admins', 'all'] as $opt): ?>
      <label><input type="radio" name="require_2fa" value="<?= $opt ?>" <?= $req === $opt ? 'checked' : '' ?>> <?= e(__('admin.require_2fa.' . $opt)) ?></label>
      <?php endforeach; ?>
    </div>
  </div>

  <label class="field"><span><?= e(__('admin.default_lang')) ?></span>
    <select name="default_lang">
      <?php foreach ($langs as $c => $n): ?><option value="<?= e($c) ?>" <?= Settings::get('default_lang') === $c ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
    </select>
  </label>

  <label class="field"><span><?= e(__('admin.update_repo')) ?></span><input type="text" name="update_repo" value="<?= e(Settings::get('update_repo', APP_UPDATE_REPO)) ?>" pattern="[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+">
    <small class="hint"><?= e(__('admin.update_repo_hint')) ?></small></label>

  <hr class="sep">
  <h2 class="card-title" id="live"><?= icon('broadcast') ?><?= e(__('admin.live.title')) ?></h2>
  <div class="grid-2 tight">
    <label class="field"><span><?= e(__('admin.live.default_minutes')) ?></span>
      <input type="number" name="live_default_minutes" min="<?= Live::MIN_MINUTES ?>" max="<?= Live::MAX_MINUTES ?>" value="<?= Live::defaultMinutes() ?>">
      <small class="hint"><?= e(__('admin.live.max_minutes_hint', ['n' => Live::MAX_MINUTES])) ?></small></label>
    <label class="field"><span><?= e(__('admin.live.max_viewers')) ?></span>
      <input type="number" name="live_max_viewers" min="1" max="<?= Live::MAX_VIEWERS ?>" value="<?= Live::maxViewers() ?>">
      <small class="hint"><?= e(__('admin.live.max_viewers_hint', ['n' => Live::MAX_VIEWERS])) ?></small></label>
  </div>
  <div class="field"><span><?= e(__('admin.live.name_mode')) ?></span>
    <div class="radio-list">
      <?php foreach (['off', 'optional', 'required'] as $opt): ?>
      <label><input type="radio" name="live_name_mode" value="<?= $opt ?>" <?= Settings::get('live_name_mode', 'optional') === $opt ? 'checked' : '' ?>> <?= e(__('admin.live.name_mode.' . $opt)) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <label class="field"><span><?= e(__('admin.live.quality')) ?></span>
    <select name="live_quality">
      <?php foreach (array_keys(Live::qualities()) as $q): ?><option value="<?= e($q) ?>" <?= Settings::get('live_quality', 'balanced') === $q ? 'selected' : '' ?>><?= e(__('live.q.' . $q)) ?> – <?= e(__('live.q.' . $q . '_hint')) ?></option><?php endforeach; ?>
    </select></label>
  <label class="switch-field">
    <input type="checkbox" name="live_fallback" value="1" <?= Settings::bool('live_fallback') ? 'checked' : '' ?>>
    <span class="switch" aria-hidden="true"></span>
    <span><strong><?= e(__('admin.live.fallback')) ?></strong><small class="hint"><?= e(__('admin.live.fallback_hint')) ?></small></span>
  </label>
  <label class="field"><span><?= e(__('admin.live.stun')) ?></span>
    <textarea name="live_ice_servers" rows="2" class="code-area" spellcheck="false"><?= e((string)Settings::get('live_ice_servers', '')) ?></textarea>
    <small class="hint"><?= e(__('admin.live.stun_hint')) ?></small></label>
  <details class="notes" <?= Settings::get('live_turn_url', '') !== '' ? 'open' : '' ?>><summary><?= e(__('admin.live.turn')) ?></summary>
    <p class="small muted"><?= e(__('admin.live.turn_hint')) ?></p>
    <label class="field"><span><?= e(__('admin.live.turn_url')) ?></span><input type="text" name="live_turn_url" value="<?= e(str_replace("\n", ' ', (string)Settings::get('live_turn_url', ''))) ?>" placeholder="turn:turn.example.org:3478"></label>
    <div class="grid-2 tight">
      <label class="field"><span><?= e(__('admin.live.turn_user')) ?></span><input type="text" name="live_turn_user" value="<?= e((string)Settings::get('live_turn_user', '')) ?>" autocomplete="off"></label>
      <label class="field"><span><?= e(__('admin.live.turn_pass')) ?></span><input type="password" name="live_turn_pass" value="<?= Settings::get('live_turn_pass', '') !== '' ? '********' : '' ?>" autocomplete="new-password"></label>
    </div>
  </details>

  <div class="row-actions"><button class="btn btn-primary" type="submit"><?= e(__('common.save')) ?></button></div>
</form>
<?php
echo view('layout_app', ['title' => __('admin.nav.settings'), 'content' => ob_get_clean(), 'active' => 'admin-settings', 'admin' => true]);
