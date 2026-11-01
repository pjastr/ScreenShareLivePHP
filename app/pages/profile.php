<?php
defined('SSLIVE') || exit;

$u = Auth::user();
$uid = (int)$u['id'];
$newCodes = null;

if (is_post()) {
    $action = post('action');
    $pass = (string)($_POST['current_password'] ?? '');
    switch ($action) {
        case 'prefs':
            $lang = post('lang');
            $theme = post('theme');
            $langs = I18n::languages();
            DB::update('users', [
                'lang' => isset($langs[$lang]) ? $lang : null,
                'theme' => in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system',
            ], '`id` = ?', [$uid]);
            flash('success', __('common.saved'));
            redirect(url('profile', isset($langs[$lang]) ? ['lang' => $lang] : []));

        case 'email':
            $email = Auth::normalizeIdent(post('email'));
            if (!password_verify($pass, $u['password_hash'])) {
                flash('error', __('profile.err.current_password'));
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', __('auth.err.email'));
            } elseif (DB::value('SELECT COUNT(*) FROM {users} WHERE `email` = ? AND `id` <> ?', [$email, $uid])) {
                flash('error', __('auth.err.email_taken'));
            } else {
                DB::update('users', ['email' => $email], '`id` = ?', [$uid]);
                flash('success', __('common.saved'));
            }
            redirect(url('profile'));

        case 'password':
            $new = (string)($_POST['new_password'] ?? '');
            if (!password_verify($pass, $u['password_hash'])) {
                flash('error', __('profile.err.current_password'));
            } elseif ($new !== (string)($_POST['new_password2'] ?? '')) {
                flash('error', __('auth.err.password_mismatch'));
            } elseif ($err = Auth::validatePassword($new)) {
                flash('error', $err);
            } else {
                DB::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], '`id` = ?', [$uid]);
                Auth::rehashSession();
                flash('success', __('profile.password_changed'));
            }
            redirect(url('profile'));

        case '2fa_start':
            $_SESSION['totp_setup'] = Totp::generateSecret();
            redirect(url('profile') . '#security');

        case '2fa_cancel':
            unset($_SESSION['totp_setup']);
            redirect(url('profile') . '#security');

        case '2fa_confirm':
            $secret = (string)($_SESSION['totp_setup'] ?? '');
            $step = $secret !== '' ? Totp::verify($secret, post('code')) : null;
            if ($step === null) {
                flash('error', __('auth.2fa_invalid'));
                redirect(url('profile') . '#security');
            }
            DB::update('users', ['totp_secret' => $secret, 'totp_enabled' => 1, 'totp_last_step' => $step], '`id` = ?', [$uid]);
            unset($_SESSION['totp_setup']);
            $newCodes = Auth::generateBackupCodes($uid);
            flash('success', __('profile.2fa_enabled'));
            break;

        case '2fa_codes':
            if (!password_verify($pass, $u['password_hash'])) {
                flash('error', __('profile.err.current_password'));
                redirect(url('profile') . '#security');
            }
            $newCodes = Auth::generateBackupCodes($uid);
            flash('success', __('profile.codes_regenerated'));
            break;

        case '2fa_disable':
            $step = Totp::verify((string)$u['totp_secret'], post('code'), (int)$u['totp_last_step']);
            if (!password_verify($pass, $u['password_hash'])) {
                flash('error', __('profile.err.current_password'));
            } elseif ($step === null) {
                flash('error', __('auth.2fa_invalid'));
            } elseif (Auth::mustSetup2fa(array_merge($u, ['totp_enabled' => 0]))) {
                flash('error', __('profile.2fa_required_cant_disable'));
            } else {
                DB::update('users', ['totp_secret' => null, 'totp_enabled' => 0, 'totp_last_step' => 0, 'backup_codes' => null], '`id` = ?', [$uid]);
                flash('success', __('profile.2fa_disabled'));
            }
            redirect(url('profile') . '#security');
    }
    Auth::refresh();
    $u = Auth::user();
}

$langs = I18n::languages();
$setupSecret = $_SESSION['totp_setup'] ?? null;
$siteName = Settings::get('site_name', 'ScreenShare Live');
$streamsCount = (int)DB::value('SELECT COUNT(*) FROM {streams} WHERE `user_id` = ?', [$uid]);

ob_start(); ?>
<div class="page-head">
  <div>
    <h1><?= e(__('profile.title')) ?></h1>
    <p class="muted"><?= e(__('profile.sub')) ?></p>
  </div>
</div>

<div class="profile-grid">
  <section class="card">
    <h2 class="card-title"><?= icon('user') ?><?= e(__('profile.account')) ?></h2>
    <dl class="kv">
      <dt><?= e(__('auth.username')) ?></dt><dd><?= e($u['username']) ?></dd>
      <dt><?= e(__('admin.users.role')) ?></dt><dd><span class="badge <?= $u['role'] === 'admin' ? 'badge-accent' : '' ?>"><?= e(__('role.' . $u['role'])) ?></span></dd>
      <dt><?= e(__('profile.member_since')) ?></dt><dd><?= e(format_date($u['created_at'])) ?></dd>
      <dt><?= e(__('admin.stat.streams')) ?></dt><dd><?= $streamsCount ?></dd>
    </dl>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="email">
      <label class="field"><span><?= e(__('auth.email')) ?></span><input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
      <label class="field"><span><?= e(__('profile.current_password')) ?></span><input type="password" name="current_password" required autocomplete="current-password"></label>
      <button class="btn" type="submit"><?= e(__('profile.change_email')) ?></button>
    </form>
  </section>

  <section class="card">
    <h2 class="card-title"><?= icon('adjustments') ?><?= e(__('profile.prefs')) ?></h2>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="prefs">
      <label class="field"><span><?= e(__('common.language')) ?></span>
        <select name="lang">
          <option value=""><?= e(__('profile.lang_auto')) ?></option>
          <?php foreach ($langs as $code => $name): ?>
          <option value="<?= e($code) ?>" <?= $u['lang'] === $code ? 'selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span><?= e(__('common.theme')) ?></span>
        <select name="theme">
          <?php foreach (['system', 'light', 'dark'] as $t): ?>
          <option value="<?= $t ?>" <?= $u['theme'] === $t ? 'selected' : '' ?>><?= e(__('theme.' . $t)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
    </form>
    <hr class="sep">
    <h2 class="card-title"><?= icon('key') ?><?= e(__('profile.change_password')) ?></h2>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <label class="field"><span><?= e(__('profile.current_password')) ?></span><input type="password" name="current_password" required autocomplete="current-password"></label>
      <label class="field"><span><?= e(__('profile.new_password')) ?></span><input type="password" name="new_password" required minlength="8" autocomplete="new-password" data-pw-meter><div class="pw-meter"><i></i></div></label>
      <label class="field"><span><?= e(__('auth.password_repeat')) ?></span><input type="password" name="new_password2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn" type="submit"><?= e(__('profile.change_password')) ?></button>
    </form>
  </section>

  <section class="card card-wide" id="security">
    <h2 class="card-title"><?= icon('shield-lock') ?><?= e(__('profile.2fa')) ?>
      <?php if ((int)$u['totp_enabled'] === 1): ?><span class="badge badge-success"><?= icon('shield-check') ?><?= e(__('profile.2fa_on')) ?></span>
      <?php else: ?><span class="badge badge-warn"><?= e(__('profile.2fa_off')) ?></span><?php endif; ?>
    </h2>

    <?php if ($newCodes): ?>
      <div class="alert alert-warning"><?= icon('alert-triangle') ?><span><?= e(__('profile.codes_save_now')) ?></span></div>
      <div class="codes-grid" id="backup-codes">
        <?php foreach ($newCodes as $c): ?><code><?= e($c) ?></code><?php endforeach; ?>
      </div>
      <div class="row-actions">
        <button type="button" class="btn" data-copy="#backup-codes"><?= icon('copy') ?><?= e(__('common.copy')) ?></button>
        <button type="button" class="btn" data-download-text="#backup-codes" data-filename="<?= e($siteName) ?>-backup-codes.txt"><?= icon('download') ?><?= e(__('common.download')) ?></button>
      </div>
    <?php elseif ((int)$u['totp_enabled'] === 1): ?>
      <p class="muted"><?= e(__('profile.2fa_on_text')) ?></p>
      <p><?= e(__('profile.codes_left', ['n' => Auth::backupCodesLeft($u)])) ?></p>
      <div class="split">
        <form method="post" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="2fa_codes">
          <h3><?= e(__('profile.regen_codes')) ?></h3>
          <label class="field"><span><?= e(__('profile.current_password')) ?></span><input type="password" name="current_password" required autocomplete="current-password"></label>
          <button class="btn" type="submit"><?= icon('refresh') ?><?= e(__('profile.regen_codes')) ?></button>
        </form>
        <?php if (!Auth::mustSetup2fa(array_merge($u, ['totp_enabled' => 0]))): ?>
        <form method="post" class="form" data-confirm="<?= e(__('profile.2fa_disable_confirm')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="2fa_disable">
          <h3><?= e(__('profile.2fa_disable')) ?></h3>
          <label class="field"><span><?= e(__('profile.current_password')) ?></span><input type="password" name="current_password" required autocomplete="current-password"></label>
          <label class="field"><span><?= e(__('auth.2fa_code')) ?></span><input type="text" name="code" required inputmode="numeric" maxlength="7" class="code-input" autocomplete="one-time-code"></label>
          <button class="btn btn-danger" type="submit"><?= e(__('profile.2fa_disable')) ?></button>
        </form>
        <?php endif; ?>
      </div>
    <?php elseif ($setupSecret): ?>
      <?php $uri = Totp::uri($setupSecret, $u['username'], $siteName); ?>
      <ol class="steps">
        <li><?= e(__('profile.2fa_step1')) ?></li>
        <li><?= e(__('profile.2fa_step2')) ?></li>
        <li><?= e(__('profile.2fa_step3')) ?></li>
      </ol>
      <div class="totp-setup">
        <div class="qr" data-qr="<?= e($uri) ?>"></div>
        <div>
          <p class="muted small"><?= e(__('profile.2fa_manual')) ?></p>
          <code class="secret"><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></code>
          <form method="post" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="2fa_confirm">
            <label class="field"><span><?= e(__('auth.2fa_code')) ?></span><input type="text" name="code" required inputmode="numeric" maxlength="7" class="code-input" placeholder="123456" autocomplete="one-time-code" autofocus></label>
            <div class="row-actions">
              <button class="btn btn-primary" type="submit"><?= e(__('profile.2fa_activate')) ?></button>
            </div>
          </form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_cancel"><button class="btn btn-ghost" type="submit"><?= e(__('common.cancel')) ?></button></form>
        </div>
      </div>
    <?php else: ?>
      <p class="muted"><?= e(__('profile.2fa_off_text')) ?></p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="2fa_start">
        <button class="btn btn-primary" type="submit"><?= icon('shield-lock') ?><?= e(__('profile.2fa_enable')) ?></button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php
echo view('layout_app', ['title' => __('profile.title'), 'content' => ob_get_clean(), 'active' => 'profile', 'extraJs' => ['vendor/qrcode.js']]);
