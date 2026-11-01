<?php
defined('SSLIVE') || exit;

if (!Settings::bool('registration_enabled')) {
    ob_start(); ?>
    <div class="auth-icon"><?= icon('lock') ?></div>
    <h1 class="auth-title"><?= e(__('auth.register_closed')) ?></h1>
    <p class="muted auth-sub"><?= e(__('auth.register_closed_text')) ?></p>
    <a class="btn btn-primary btn-block" href="<?= e(url('login')) ?>"><?= e(__('auth.login')) ?></a>
    <?php
    echo view('layout_auth', ['title' => __('auth.register'), 'content' => ob_get_clean()]);
    exit;
}

$username = $email = '';
if (is_post()) {
    $username = post('username');
    $email = post('email');
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    if (post('website') !== '') {
        // pułapka na boty (honeypot)
        redirect(url('login'));
    }
    $since = gmdate('Y-m-d H:i:s', time() - 3600);
    $recent = (int)DB::value('SELECT COUNT(*) FROM {login_attempts} WHERE `ip` = ? AND `ident` = ? AND `created_at` > ?', [client_ip(), '#register', $since]);
    if ($recent >= 10) {
        flash('error', __('auth.err.too_many_registrations'));
    } elseif ($password !== $password2) {
        flash('error', __('auth.err.password_mismatch'));
    } else {
        try {
            $id = Auth::createUser($username, $email, $password, 'user');
            DB::insert('login_attempts', ['ip' => client_ip(), 'ident' => '#register', 'success' => 1, 'created_at' => DB::now()]);
            $u = DB::row('SELECT * FROM {users} WHERE `id` = ?', [$id]);
            if (!empty($_SESSION['lang'])) {
                DB::update('users', ['lang' => $_SESSION['lang']], '`id` = ?', [$id]);
            }
            Auth::login($u);
            flash('success', __('auth.registered'));
            redirect(url('share'));
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
    }
}

ob_start(); ?>
<h1 class="auth-title"><?= e(__('auth.register_title')) ?></h1>
<p class="muted auth-sub"><?= e(__('auth.register_sub')) ?></p>
<form method="post" class="form" autocomplete="on">
  <?= csrf_field() ?>
  <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
  <label class="field">
    <span><?= e(__('auth.username')) ?></span>
    <input type="text" name="username" value="<?= e($username) ?>" required minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" autocomplete="username" autofocus>
    <small class="hint"><?= e(__('auth.username_hint')) ?></small>
  </label>
  <label class="field">
    <span><?= e(__('auth.email')) ?></span>
    <input type="email" name="email" value="<?= e($email) ?>" required maxlength="190" autocomplete="email">
  </label>
  <label class="field">
    <span><?= e(__('auth.password')) ?></span>
    <div class="pw-wrap">
      <input type="password" name="password" required minlength="8" autocomplete="new-password" data-pw-meter>
      <button type="button" class="pw-toggle" data-pw-toggle tabindex="-1" aria-label="<?= e(__('auth.show_password')) ?>"><?= icon('eye') ?></button>
    </div>
    <div class="pw-meter"><i></i></div>
    <small class="hint"><?= e(__('auth.password_hint')) ?></small>
  </label>
  <label class="field">
    <span><?= e(__('auth.password_repeat')) ?></span>
    <input type="password" name="password2" required minlength="8" autocomplete="new-password">
  </label>
  <button class="btn btn-primary btn-block" type="submit"><?= e(__('auth.create_account')) ?></button>
</form>
<p class="auth-alt"><?= e(__('auth.have_account')) ?> <a href="<?= e(url('login')) ?>"><?= e(__('auth.login')) ?></a></p>
<?php
echo view('layout_auth', ['title' => __('auth.register'), 'content' => ob_get_clean()]);
