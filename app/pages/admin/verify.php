<?php
defined('SSLIVE') || exit;

$u = Auth::user();
if ((int)$u['totp_enabled'] !== 1) {
    flash('warning', __('admin.verify.need_2fa'));
    redirect(url('profile') . '#security');
}

/** Bezpieczny adres powrotu – tylko w obrębie aplikacji. */
function admin_return_url(): string
{
    $r = (string)($_SESSION['admin_return'] ?? '');
    unset($_SESSION['admin_return']);
    $prefix = base_url() . 'panel/';
    return (str_starts_with($r, $prefix) && !str_contains($r, "\n")) ? $r : url('admin');
}

if (Auth::adminVerified()) {
    redirect(admin_return_url());
}

$useBackup = isset($_GET['backup']);
if (is_post()) {
    $code = post('code');
    $ok = false;
    if (isset($_POST['backup'])) {
        $ok = Auth::useBackupCode($u, $code);
        if ($ok) {
            flash('warning', __('auth.backup_used', ['n' => Auth::backupCodesLeft(DB::row('SELECT * FROM {users} WHERE `id` = ?', [(int)$u['id']]))]));
        }
    } else {
        $step = Totp::verify((string)$u['totp_secret'], $code, (int)$u['totp_last_step']);
        if ($step !== null) {
            DB::update('users', ['totp_last_step' => $step], '`id` = ?', [(int)$u['id']]);
            $ok = true;
        } elseif (Totp::verify((string)$u['totp_secret'], $code, 0) !== null) {
            // poprawny, ale już użyty kod (np. ten sam co przy logowaniu)
            flash('error', __('admin.verify.reused'));
            redirect(url('admin-verify'));
        }
    }
    if ($ok) {
        $_SESSION['admin_verify_tries'] = 0;
        Auth::markAdminVerified();
        redirect(admin_return_url());
    }
    $_SESSION['admin_verify_tries'] = (int)($_SESSION['admin_verify_tries'] ?? 0) + 1;
    if ($_SESSION['admin_verify_tries'] >= 5) {
        Auth::logout();
        start_session();
        flash('error', __('admin.verify.too_many'));
        redirect(url('login'));
    }
    flash('error', __('auth.2fa_invalid'));
    redirect(url('admin-verify', isset($_POST['backup']) ? ['backup' => 1] : []));
}

ob_start(); ?>
<div class="auth-icon"><?= icon('shield-lock') ?></div>
<h1 class="auth-title"><?= e(__('admin.verify.title')) ?></h1>
<?php if ($useBackup): ?>
<p class="muted auth-sub"><?= e(__('auth.2fa_backup_sub')) ?></p>
<form method="post" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="backup" value="1">
  <label class="field"><span><?= e(__('auth.backup_code')) ?></span>
    <input type="text" name="code" required autofocus placeholder="XXXX-XXXX" class="code-input" maxlength="12"></label>
  <button class="btn btn-primary btn-block" type="submit"><?= e(__('auth.verify')) ?></button>
</form>
<p class="auth-alt"><a href="<?= e(url('admin-verify')) ?>"><?= e(__('auth.use_app_code')) ?></a></p>
<?php else: ?>
<p class="muted auth-sub"><?= e(__('admin.verify.sub')) ?></p>
<form method="post" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <label class="field"><span><?= e(__('auth.2fa_code')) ?></span>
    <input type="text" name="code" required autofocus inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456" class="code-input" autocomplete="one-time-code"></label>
  <button class="btn btn-primary btn-block" type="submit"><?= e(__('auth.verify')) ?></button>
</form>
<p class="auth-alt"><a href="<?= e(url('admin-verify', ['backup' => 1])) ?>"><?= e(__('auth.use_backup')) ?></a></p>
<?php endif; ?>
<p class="auth-alt small"><?= e(__('admin.verify.note', ['min' => (int)(Auth::ADMIN_IDLE / 60)])) ?></p>
<p class="auth-alt"><a href="<?= e(url('share')) ?>"><?= icon('chevron-left') ?> <?= e(__('nav.share')) ?></a></p>
<?php
echo view('layout_auth', ['title' => __('admin.verify.title'), 'content' => ob_get_clean()]);
