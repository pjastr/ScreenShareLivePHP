<?php
defined('SSLIVE') || exit;

$uid = (int)($_SESSION['2fa_pending'] ?? 0);
if ($uid <= 0 || time() - (int)($_SESSION['2fa_at'] ?? 0) > 300) {
    unset($_SESSION['2fa_pending'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
    if ($uid > 0) {
        flash('error', __('auth.2fa_expired'));
    }
    redirect(url('login'));
}
$u = DB::row('SELECT * FROM {users} WHERE `id` = ?', [$uid]);
if (!$u || $u['status'] !== 'active' || (int)$u['totp_enabled'] !== 1) {
    unset($_SESSION['2fa_pending']);
    redirect(url('login'));
}

$useBackup = isset($_GET['backup']);
if (is_post()) {
    $code = post('code');
    $ok = false;
    if (isset($_POST['backup'])) {
        $ok = Auth::useBackupCode($u, $code);
        if ($ok) {
            flash('warning', __('auth.backup_used', ['n' => Auth::backupCodesLeft(DB::row('SELECT * FROM {users} WHERE `id` = ?', [$uid]))]));
        }
    } else {
        $step = Totp::verify((string)$u['totp_secret'], $code, (int)$u['totp_last_step']);
        if ($step !== null) {
            DB::update('users', ['totp_last_step' => $step], '`id` = ?', [$uid]);
            $ok = true;
        }
    }
    if ($ok) {
        Auth::recordAttempt((string)($_SESSION['2fa_ident'] ?? $u['username']), true);
        Auth::logLogin($u, (string)($_SESSION['2fa_ident'] ?? $u['username']), true, isset($_POST['backup']) ? 'ok_backup' : 'ok_2fa');
        Auth::login($u);
        redirect(url('share'));
    }
    $_SESSION['2fa_tries'] = (int)($_SESSION['2fa_tries'] ?? 0) + 1;
    Auth::recordAttempt((string)($_SESSION['2fa_ident'] ?? $u['username']), false);
    Auth::logLogin($u, (string)($_SESSION['2fa_ident'] ?? $u['username']), false, $_SESSION['2fa_tries'] >= 5 ? 'too_many_2fa' : 'bad_2fa');
    if ($_SESSION['2fa_tries'] >= 5) {
        unset($_SESSION['2fa_pending'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
        flash('error', __('auth.2fa_too_many'));
        redirect(url('login'));
    }
    flash('error', __('auth.2fa_invalid'));
    redirect(url('2fa', $useBackup || isset($_POST['backup']) ? ['backup' => 1] : []));
}

ob_start(); ?>
<div class="auth-icon"><?= icon('shield-lock') ?></div>
<h1 class="auth-title"><?= e(__('auth.2fa_title')) ?></h1>
<?php if ($useBackup): ?>
<p class="muted auth-sub"><?= e(__('auth.2fa_backup_sub')) ?></p>
<form method="post" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="backup" value="1">
  <label class="field">
    <span><?= e(__('auth.backup_code')) ?></span>
    <input type="text" name="code" required autofocus placeholder="XXXX-XXXX" class="code-input" maxlength="12">
  </label>
  <button class="btn btn-primary btn-block" type="submit"><?= e(__('auth.verify')) ?></button>
</form>
<p class="auth-alt"><a href="<?= e(url('2fa')) ?>"><?= e(__('auth.use_app_code')) ?></a></p>
<?php else: ?>
<p class="muted auth-sub"><?= e(__('auth.2fa_sub')) ?></p>
<form method="post" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <label class="field">
    <span><?= e(__('auth.2fa_code')) ?></span>
    <input type="text" name="code" required autofocus inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" class="code-input" autocomplete="one-time-code">
  </label>
  <button class="btn btn-primary btn-block" type="submit"><?= e(__('auth.verify')) ?></button>
</form>
<p class="auth-alt"><a href="<?= e(url('2fa', ['backup' => 1])) ?>"><?= e(__('auth.use_backup')) ?></a></p>
<?php endif; ?>
<p class="auth-alt"><a href="<?= e(url('logout')) ?>" data-post-link><?= e(__('auth.cancel_login')) ?></a></p>
<?php
echo view('layout_auth', ['title' => __('auth.2fa_title'), 'content' => ob_get_clean()]);
