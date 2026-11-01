<?php
defined('SSLIVE') || exit;

$langs = I18n::languages();
if (is_post() && post('action') === 'mail_test') {
    try {
        $me = Auth::user();
        Mailer::send([$me['email']], __('mail.test.subject', ['site' => Settings::get('site_name', 'ScreenShare Live')]), __('mail.test.body', ['user' => $me['username']]));
        Settings::set('mail_last_status', 'ok ' . gmdate('c'));
        flash('success', __('admin.mail.test_ok', ['to' => $me['email']]));
    } catch (Throwable $e) {
        Settings::set('mail_last_status', 'error ' . gmdate('c') . ' ' . mb_substr($e->getMessage(), 0, 300));
        flash('error', __('admin.mail.test_failed') . ' ' . $e->getMessage());
    }
    redirect(url('admin-settings') . '#mail');
}
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
    $codec = post('live_codec');
    Settings::set('live_codec', in_array($codec, Live::codecs(), true) ? $codec : 'vp9');
    $ice = array_filter(preg_split('/[\s,]+/', post('live_ice_servers')) ?: [], fn($u) => (bool)preg_match('#^stuns?:[A-Za-z0-9.\-]+(:\d+)?$#', $u));
    Settings::set('live_ice_servers', implode("\n", array_slice(array_values($ice), 0, 6)));
    $turn = array_filter(preg_split('/[\s,]+/', post('live_turn_url')) ?: [], fn($u) => (bool)preg_match('#^turns?:[^\s]+$#', $u));
    Settings::set('live_turn_url', implode("\n", array_slice(array_values($turn), 0, 4)));
    Settings::set('live_turn_user', mb_substr(post('live_turn_user'), 0, 190));
    if (isset($_POST['live_turn_pass']) && $_POST['live_turn_pass'] !== '********') {
        Settings::set('live_turn_pass', mb_substr((string)$_POST['live_turn_pass'], 0, 190));
    }
    // Powiadomienia e-mail
    $nm = post('notify_mode');
    Settings::set('notify_mode', in_array($nm, ['off', 'daily', 'every'], true) ? $nm : 'off');
    $to = array_filter(array_map('trim', preg_split('/[\s,;]+/', post('notify_to')) ?: []), fn($a) => (bool)filter_var($a, FILTER_VALIDATE_EMAIL));
    Settings::set('notify_to', implode(', ', array_slice(array_values(array_unique($to)), 0, 20)));
    Settings::set('mail_method', post('mail_method') === 'smtp' ? 'smtp' : 'mail');
    $from = post('mail_from');
    Settings::set('mail_from', filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '');
    Settings::set('mail_from_name', mb_substr(post('mail_from_name'), 0, 80));
    Settings::set('smtp_host', preg_replace('/[^A-Za-z0-9.\-]/', '', post('smtp_host')));
    Settings::set('smtp_port', (string)max(1, min(65535, (int)post('smtp_port', '587'))));
    $sec = post('smtp_secure');
    Settings::set('smtp_secure', in_array($sec, ['tls', 'ssl', 'none'], true) ? $sec : 'tls');
    Settings::set('smtp_user', mb_substr(post('smtp_user'), 0, 190));
    if (isset($_POST['smtp_pass']) && $_POST['smtp_pass'] !== '********') {
        Settings::set('smtp_pass', mb_substr((string)$_POST['smtp_pass'], 0, 190));
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
  <label class="field"><span><?= e(__('admin.live.codec')) ?></span>
    <select name="live_codec">
      <?php foreach (Live::codecs() as $c): ?><option value="<?= e($c) ?>" <?= Live::codec() === $c ? 'selected' : '' ?>><?= e(__('admin.live.codec.' . $c)) ?></option><?php endforeach; ?>
    </select>
    <small class="hint"><?= e(__('admin.live.codec_hint')) ?></small></label>
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

  <hr class="sep">
  <h2 class="card-title" id="mail"><?= icon('mail') ?><?= e(__('admin.mail.title')) ?></h2>
  <div class="field"><span><?= e(__('admin.mail.notify')) ?></span>
    <div class="radio-list">
      <?php foreach (['off', 'daily', 'every'] as $opt): ?>
      <label><input type="radio" name="notify_mode" value="<?= $opt ?>" <?= Notifier::mode() === $opt ? 'checked' : '' ?>> <?= e(__('admin.mail.notify.' . $opt)) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <label class="field"><span><?= e(__('admin.mail.to')) ?></span>
    <input type="text" name="notify_to" value="<?= e((string)Settings::get('notify_to', '')) ?>" placeholder="<?= e(implode(', ', array_column(DB::all("SELECT `email` FROM {users} WHERE `role` = 'admin' AND `status` = 'active' LIMIT 5"), 'email'))) ?>">
    <small class="hint"><?= e(__('admin.mail.to_hint')) ?></small></label>
  <div class="field"><span><?= e(__('admin.mail.method')) ?></span>
    <div class="radio-list">
      <?php foreach (['mail', 'smtp'] as $opt): ?>
      <label><input type="radio" name="mail_method" value="<?= $opt ?>" <?= Settings::get('mail_method', 'mail') === $opt ? 'checked' : '' ?>> <?= e(__('admin.mail.method.' . $opt)) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="grid-2 tight">
    <label class="field"><span><?= e(__('admin.mail.from')) ?></span><input type="email" name="mail_from" value="<?= e((string)Settings::get('mail_from', '')) ?>" placeholder="<?= e(Mailer::fromAddress()) ?>"></label>
    <label class="field"><span><?= e(__('admin.mail.from_name')) ?></span><input type="text" name="mail_from_name" maxlength="80" value="<?= e((string)Settings::get('mail_from_name', '')) ?>" placeholder="<?= e((string)Settings::get('site_name', 'ScreenShare Live')) ?>"></label>
  </div>
  <details class="notes" <?= Settings::get('mail_method', 'mail') === 'smtp' ? 'open' : '' ?>><summary><?= e(__('admin.mail.smtp')) ?></summary>
    <div class="grid-2 tight">
      <label class="field"><span><?= e(__('admin.mail.smtp_host')) ?></span><input type="text" name="smtp_host" value="<?= e((string)Settings::get('smtp_host', '')) ?>" placeholder="smtp.example.org"></label>
      <div class="grid-2 tight inner">
        <label class="field"><span><?= e(__('admin.mail.smtp_port')) ?></span><input type="number" name="smtp_port" min="1" max="65535" value="<?= (int)Settings::get('smtp_port', '587') ?>"></label>
        <label class="field"><span><?= e(__('admin.mail.smtp_secure')) ?></span>
          <select name="smtp_secure"><?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => __('admin.mail.smtp_none')] as $k => $lbl): ?><option value="<?= $k ?>" <?= Settings::get('smtp_secure', 'tls') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></label>
      </div>
      <label class="field"><span><?= e(__('admin.mail.smtp_user')) ?></span><input type="text" name="smtp_user" value="<?= e((string)Settings::get('smtp_user', '')) ?>" autocomplete="off"></label>
      <label class="field"><span><?= e(__('admin.mail.smtp_pass')) ?></span><input type="password" name="smtp_pass" value="<?= Settings::get('smtp_pass', '') !== '' ? '********' : '' ?>" autocomplete="new-password"></label>
    </div>
    <p class="small muted"><?= e(__('admin.mail.smtp_hint')) ?></p>
  </details>
  <?php $ms = (string)Settings::get('mail_last_status', ''); if ($ms !== ''): $ok = str_starts_with($ms, 'ok'); $parts = explode(' ', $ms, 3); ?>
  <p class="small <?= $ok ? 'muted' : 'warn-text' ?>"><?= icon($ok ? 'circle-check' : 'alert-triangle') ?> <?= e(__($ok ? 'admin.mail.last_ok' : 'admin.mail.last_error', ['date' => format_date(gmdate('Y-m-d H:i:s', strtotime($parts[1] ?? 'now'))), 'error' => $parts[2] ?? ''])) ?></p>
  <?php endif; ?>

  <div class="row-actions"><button class="btn btn-primary" type="submit"><?= e(__('common.save')) ?></button></div>
</form>
<form method="post" class="card form form-wide">
  <?= csrf_field() ?><input type="hidden" name="action" value="mail_test">
  <div class="toggle-row"><span class="muted"><?= e(__('admin.mail.test_text', ['to' => Auth::user()['email']])) ?></span>
  <button class="btn" type="submit"><?= icon('mail') ?><?= e(__('admin.mail.test')) ?></button></div>
</form>
<?php
echo view('layout_app', ['title' => __('admin.nav.settings'), 'content' => ob_get_clean(), 'active' => 'admin-settings', 'admin' => true, 'extraCss' => ['css/live.css']]);
