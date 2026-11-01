<?php
defined('SSLIVE') || exit;

$ident = '';
if (is_post()) {
    $ident = post('login');
    $password = (string)($_POST['password'] ?? '');
    if ($ident === '' || $password === '') {
        flash('error', __('auth.err.fill_all'));
    } elseif (Auth::isLocked($ident)) {
        Auth::logLogin(Auth::findByLogin($ident), $ident, false, 'locked');
        flash('error', __('auth.err.locked', ['min' => Auth::LOCK_MINUTES]));
    } else {
        $u = Auth::findByLogin($ident);
        $ok = $u && password_verify($password, $u['password_hash']);
        if (!$ok) {
            // stała praca nawet gdy konto nie istnieje (utrudnia enumerację kont)
            if (!$u) {
                password_verify($password, password_hash('dummy-password', PASSWORD_DEFAULT));
            }
            Auth::recordAttempt($ident, false);
            Auth::logLogin($u, $ident, false, $u ? 'bad_password' : 'unknown_user');
            flash('error', __('auth.err.invalid'));
        } elseif ($u['status'] !== 'active') {
            Auth::logLogin($u, $ident, false, 'disabled');
            flash('error', __('auth.err.disabled'));
        } else {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], '`id` = ?', [(int)$u['id']]);
                $u = DB::row('SELECT * FROM {users} WHERE `id` = ?', [(int)$u['id']]);
            }
            if ((int)$u['totp_enabled'] === 1) {
                session_regenerate_id(true);
                $_SESSION['2fa_pending'] = (int)$u['id'];
                $_SESSION['2fa_at'] = time();
                $_SESSION['2fa_tries'] = 0;
                $_SESSION['2fa_ident'] = $ident;
                redirect(url('2fa'));
            }
            Auth::recordAttempt($ident, true);
            Auth::logLogin($u, $ident, true, 'ok');
            Auth::login($u);
            redirect(url('share'));
        }
    }
}

ob_start(); ?>
<h1 class="auth-title"><?= e(__('auth.login_title')) ?></h1>
<p class="muted auth-sub"><?= e(__('auth.login_sub')) ?></p>
<form method="post" class="form" autocomplete="on">
  <?= csrf_field() ?>
  <label class="field">
    <span><?= e(__('auth.login_or_email')) ?></span>
    <input type="text" name="login" value="<?= e($ident) ?>" required autofocus autocomplete="username">
  </label>
  <label class="field">
    <span><?= e(__('auth.password')) ?></span>
    <div class="pw-wrap">
      <input type="password" name="password" required autocomplete="current-password">
      <button type="button" class="pw-toggle" data-pw-toggle tabindex="-1" aria-label="<?= e(__('auth.show_password')) ?>"><?= icon('eye') ?></button>
    </div>
  </label>
  <button class="btn btn-primary btn-block" type="submit"><?= e(__('auth.login')) ?></button>
</form>
<?php if (Settings::bool('registration_enabled')): ?>
<p class="auth-alt"><?= e(__('auth.no_account')) ?> <a href="<?= e(url('register')) ?>"><?= e(__('auth.register')) ?></a></p>
<?php endif; ?>
<?php
echo view('layout_auth', ['title' => __('auth.login'), 'content' => ob_get_clean()]);
