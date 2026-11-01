<?php
defined('SSLIVE') || exit;

$me = Auth::id();

if (is_post()) {
    $action = post('action');
    $id = (int)post('id');
    $target = $id ? DB::row('SELECT * FROM {users} WHERE `id` = ?', [$id]) : null;
    $admins = (int)DB::value("SELECT COUNT(*) FROM {users} WHERE `role` = 'admin' AND `status` = 'active'");
    $back = url('admin-users', array_filter(['q' => $_GET['q'] ?? null]));

    if ($action === 'create') {
        try {
            Auth::createUser(post('username'), post('email'), (string)($_POST['password'] ?? ''), post('role') === 'admin' ? 'admin' : 'user');
            flash('success', __('admin.users.created'));
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }
    if (!$target) {
        flash('error', __('admin.users.not_found'));
        redirect($back);
    }
    $self = (int)$target['id'] === $me;
    $lastAdmin = $target['role'] === 'admin' && $target['status'] === 'active' && $admins <= 1;

    switch ($action) {
        case 'role':
            $role = post('role') === 'admin' ? 'admin' : 'user';
            if ($self || ($lastAdmin && $role !== 'admin')) {
                flash('error', __('admin.users.cant_self'));
            } else {
                DB::update('users', ['role' => $role], '`id` = ?', [$id]);
                flash('success', __('admin.users.role_changed', ['user' => $target['username'], 'role' => __('role.' . $role)]));
            }
            break;
        case 'status':
            $status = $target['status'] === 'active' ? 'disabled' : 'active';
            if ($self || ($lastAdmin && $status !== 'active')) {
                flash('error', __('admin.users.cant_self'));
            } else {
                DB::update('users', ['status' => $status], '`id` = ?', [$id]);
                flash('success', __($status === 'active' ? 'admin.users.enabled' : 'admin.users.disabled', ['user' => $target['username']]));
            }
            break;
        case 'reset2fa':
            DB::update('users', ['totp_secret' => null, 'totp_enabled' => 0, 'totp_last_step' => 0, 'backup_codes' => null], '`id` = ?', [$id]);
            flash('success', __('admin.users.2fa_reset', ['user' => $target['username']]));
            break;
        case 'password':
            $p = (string)($_POST['password'] ?? '');
            if ($err = Auth::validatePassword($p)) {
                flash('error', $err);
            } else {
                DB::update('users', ['password_hash' => password_hash($p, PASSWORD_DEFAULT)], '`id` = ?', [$id]);
                if ($self) {
                    Auth::rehashSession();
                }
                flash('success', __('admin.users.password_set', ['user' => $target['username']]));
            }
            break;
        case 'edit':
            $username = post('username');
            $email = Auth::normalizeIdent(post('email'));
            $data = [];
            $err = Auth::validateUsername($username);
            if (!$err && DB::value('SELECT COUNT(*) FROM {users} WHERE `username` = ? AND `id` <> ?', [$username, $id])) {
                $err = __('auth.err.username_taken');
            }
            if (!$err && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
                $err = __('auth.err.email');
            }
            if (!$err && DB::value('SELECT COUNT(*) FROM {users} WHERE `email` = ? AND `id` <> ?', [$email, $id])) {
                $err = __('auth.err.email_taken');
            }
            $data = ['username' => $username, 'email' => $email];
            if (!$self) {
                $role = post('role') === 'admin' ? 'admin' : 'user';
                $status = post('status') === 'disabled' ? 'disabled' : 'active';
                if ($lastAdmin && ($role !== 'admin' || $status !== 'active')) {
                    $err = $err ?: __('admin.users.cant_self');
                }
                $data['role'] = $role;
                $data['status'] = $status;
            }
            $newPass = (string)($_POST['password'] ?? '');
            if (!$err && $newPass !== '') {
                $err = Auth::validatePassword($newPass);
                $data['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
            }
            if (!empty($_POST['reset2fa'])) {
                $data += ['totp_secret' => null, 'totp_enabled' => 0, 'totp_last_step' => 0, 'backup_codes' => null];
            }
            if ($err) {
                flash('error', $err);
                break;
            }
            DB::update('users', $data, '`id` = ?', [$id]);
            if ($self) {
                Auth::rehashSession();
            }
            flash('success', __('admin.users.saved', ['user' => $username]));
            break;
        case 'delete':
            if ($self || $lastAdmin) {
                flash('error', __('admin.users.cant_self'));
            } else {
                $act = Live::active();
                if ($act && (int)$act['user_id'] === $id) {
                    Live::end((int)$act['id'], 'admin');
                }
                DB::run('UPDATE {streams} SET `user_id` = 0 WHERE `user_id` = ?', [$id]);
                DB::run('DELETE FROM {users} WHERE `id` = ?', [$id]);
                flash('success', __('admin.users.deleted', ['user' => $target['username']]));
            }
            break;
    }
    redirect($back);
}

$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE u.`username` LIKE ? OR u.`email` LIKE ?';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $params = [$like, $like];
}
$users = DB::all("SELECT u.*, (SELECT COUNT(*) FROM {streams} b WHERE b.`user_id` = u.`id`) AS streams
    FROM {users} u $where ORDER BY u.`role` = 'admin' DESC, u.`username` LIMIT 500", $params);

ob_start(); ?>
<div class="page-head">
  <div>
    <h1><?= e(__('admin.nav.users')) ?></h1>
    <p class="muted"><?= e(__('admin.users.sub')) ?></p>
  </div>
  <div class="page-actions">
    <form class="search" method="get">
      <input type="hidden" name="p" value="admin-users">
      <?= icon('search') ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('admin.users.search')) ?>">
    </form>
    <button type="button" class="btn btn-primary" data-modal-open="new-user"><?= icon('user-plus') ?><span><?= e(__('admin.users.add')) ?></span></button>
  </div>
</div>

<div class="card table-card">
  <table class="table">
    <thead><tr>
      <th><?= e(__('auth.username')) ?></th>
      <th><?= e(__('admin.users.role')) ?></th>
      <th><?= e(__('admin.users.status')) ?></th>
      <th>2FA</th>
      <th class="num"><?= e(__('admin.stat.streams')) ?></th>
      <th><?= e(__('admin.users.last_login')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($users as $r): $self = (int)$r['id'] === $me; ?>
      <tr>
        <td>
          <div class="user-cell">
            <span class="avatar avatar-sm"><?= e(mb_strtoupper(mb_substr($r['username'], 0, 1))) ?></span>
            <div><strong><?= e($r['username']) ?></strong><?= $self ? ' <span class="muted small">(' . e(__('admin.users.you')) . ')</span>' : '' ?><div class="muted small"><?= e($r['email']) ?></div></div>
          </div>
        </td>
        <td>
          <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <select name="role" data-autosubmit <?= $self ? 'disabled' : '' ?> aria-label="<?= e(__('admin.users.role')) ?>">
              <option value="user" <?= $r['role'] === 'user' ? 'selected' : '' ?>><?= e(__('role.user')) ?></option>
              <option value="admin" <?= $r['role'] === 'admin' ? 'selected' : '' ?>><?= e(__('role.admin')) ?></option>
            </select>
          </form>
        </td>
        <td><span class="badge <?= $r['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>"><?= e(__('status.' . $r['status'])) ?></span></td>
        <td><?= (int)$r['totp_enabled'] ? '<span class="badge badge-success">' . icon('shield-check') . e(__('common.on')) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td class="num"><?= (int)$r['streams'] ?></td>
        <td class="small"><?= e(format_date($r['last_login_at'])) ?></td>
        <td class="actions">
          <?php $editAttrs = 'data-modal-open="edit-user" data-user-id="' . (int)$r['id'] . '" data-user-name="' . e($r['username']) . '" data-user-email="' . e($r['email']) . '" data-user-role="' . e($r['role']) . '" data-user-status="' . e($r['status']) . '" data-user-self="' . ($self ? 1 : 0) . '" data-user-totp="' . (int)$r['totp_enabled'] . '"'; ?>
          <button type="button" class="btn-icon" <?= $editAttrs ?> title="<?= e(__('admin.users.edit')) ?>" aria-label="<?= e(__('admin.users.edit')) ?>"><?= icon('edit') ?></button>
          <div class="dropdown">
            <button type="button" class="btn-icon" data-dropdown aria-label="<?= e(__('common.more')) ?>"><?= icon('dots-vertical') ?></button>
            <div class="menu menu-right">
              <button type="button" class="menu-item" <?= $editAttrs ?>><?= icon('edit') ?><?= e(__('admin.users.edit')) ?></button>
              <a class="menu-item" href="<?= e(url('admin-logins', ['user' => $r['id']])) ?>"><?= icon('list-details') ?><?= e(__('logins.history')) ?></a>
              <button type="button" class="menu-item" data-modal-open="pw-user" data-user-id="<?= (int)$r['id'] ?>" data-user-name="<?= e($r['username']) ?>"><?= icon('key') ?><?= e(__('admin.users.set_password')) ?></button>
              <?php if ((int)$r['totp_enabled']): ?>
              <form method="post" data-confirm="<?= e(__('admin.users.reset2fa_confirm', ['user' => $r['username']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reset2fa"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="menu-item" type="submit"><?= icon('shield-lock') ?><?= e(__('admin.users.reset2fa')) ?></button></form>
              <?php endif; ?>
              <?php if (!$self): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="menu-item" type="submit"><?= icon($r['status'] === 'active' ? 'lock' : 'lock-open') ?><?= e(__($r['status'] === 'active' ? 'admin.users.disable' : 'admin.users.enable')) ?></button></form>
              <div class="menu-sep"></div>
              <form method="post" data-confirm="<?= e(__('admin.users.delete_confirm', ['user' => $r['username']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="menu-item danger" type="submit"><?= icon('trash') ?><?= e(__('common.delete')) ?></button></form>
              <?php endif; ?>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="7" class="muted center"><?= e(__('common.no_results')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<dialog class="modal" id="new-user">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="modal-head"><h2><?= e(__('admin.users.add')) ?></h2><button type="button" class="btn-icon" data-modal-close><?= icon('x') ?></button></div>
    <label class="field"><span><?= e(__('auth.username')) ?></span><input type="text" name="username" required pattern="[A-Za-z0-9._\-]{3,32}" autocomplete="off"></label>
    <label class="field"><span><?= e(__('auth.email')) ?></span><input type="email" name="email" required autocomplete="off"></label>
    <label class="field"><span><?= e(__('auth.password')) ?></span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label class="field"><span><?= e(__('admin.users.role')) ?></span>
      <select name="role"><option value="user"><?= e(__('role.user')) ?></option><option value="admin"><?= e(__('role.admin')) ?></option></select>
    </label>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-modal-close><?= e(__('common.cancel')) ?></button><button class="btn btn-primary" type="submit"><?= e(__('common.create')) ?></button></div>
  </form>
</dialog>

<dialog class="modal" id="edit-user">
  <form class="modal-box" method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="" data-fill="user-id">
    <div class="modal-head"><h2><?= e(__('admin.users.edit')) ?>: <span data-fill-text="user-name"></span></h2><button type="button" class="btn-icon" data-modal-close><?= icon('x') ?></button></div>
    <div class="grid-2 tight">
      <label class="field"><span><?= e(__('auth.username')) ?></span><input type="text" name="username" required pattern="[A-Za-z0-9._\-]{3,32}" data-fill="user-name"></label>
      <label class="field"><span><?= e(__('auth.email')) ?></span><input type="email" name="email" required maxlength="190" data-fill="user-email"></label>
      <label class="field"><span><?= e(__('admin.users.role')) ?></span>
        <select name="role" data-fill="user-role" data-disable-if="user-self"><option value="user"><?= e(__('role.user')) ?></option><option value="admin"><?= e(__('role.admin')) ?></option></select></label>
      <label class="field"><span><?= e(__('admin.users.status')) ?></span>
        <select name="status" data-fill="user-status" data-disable-if="user-self"><option value="active"><?= e(__('status.active')) ?></option><option value="disabled"><?= e(__('status.disabled')) ?></option></select></label>
    </div>
    <p class="muted small" data-show-if="user-self"><?= e(__('admin.users.self_note')) ?></p>
    <label class="field"><span><?= e(__('admin.users.new_password_optional')) ?></span><input type="text" name="password" minlength="8" autocomplete="off" data-fill-clear>
      <small class="hint"><?= e(__('admin.users.password_keep')) ?></small></label>
    <label class="check" data-show-if="user-totp"><input type="checkbox" name="reset2fa" value="1" data-fill-clear> <?= e(__('admin.users.reset2fa')) ?></label>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-modal-close><?= e(__('common.cancel')) ?></button><button class="btn btn-primary" type="submit"><?= e(__('common.save')) ?></button></div>
  </form>
</dialog>

<dialog class="modal" id="pw-user">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="" data-fill="user-id">
    <div class="modal-head"><h2><?= e(__('admin.users.set_password')) ?>: <span data-fill-text="user-name"></span></h2><button type="button" class="btn-icon" data-modal-close><?= icon('x') ?></button></div>
    <label class="field"><span><?= e(__('profile.new_password')) ?></span><input type="text" name="password" required minlength="8" autocomplete="off"></label>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-modal-close><?= e(__('common.cancel')) ?></button><button class="btn btn-primary" type="submit"><?= e(__('common.save')) ?></button></div>
  </form>
</dialog>
<?php
echo view('layout_app', ['title' => __('admin.nav.users'), 'content' => ob_get_clean(), 'active' => 'admin-users', 'admin' => true]);
