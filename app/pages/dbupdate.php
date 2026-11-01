<?php
defined('SSLIVE') || exit;

$current = Migrator::current();
$latest = Migrator::latest();

if (is_post() && post('action') === 'migrate') {
    try {
        $done = Migrator::migrate();
        flash('success', __('dbupdate.done', ['n' => count($done)]));
        redirect(url('admin'));
    } catch (Throwable $e) {
        flash('error', __('dbupdate.failed') . ' ' . $e->getMessage());
        redirect(url('dbupdate'));
    }
}

if ($current >= $latest) {
    redirect(url('admin'));
}

ob_start(); ?>
<div class="auth-icon"><?= icon('database') ?></div>
<h1 class="auth-title"><?= e(__('dbupdate.title')) ?></h1>
<p class="muted auth-sub"><?= e(__('dbupdate.text')) ?></p>
<dl class="kv kv-center">
  <dt><?= e(__('admin.version_files')) ?></dt><dd><?= e(APP_VERSION) ?></dd>
  <dt><?= e(__('dbupdate.schema')) ?></dt><dd><?= (int)$current ?> → <?= (int)$latest ?></dd>
</dl>
<p class="small muted"><?= e(__('dbupdate.backup_hint')) ?> <a href="<?= e(url('admin-backup')) ?>"><?= e(__('admin.backup_download')) ?></a></p>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="migrate">
  <button class="btn btn-primary btn-block" type="submit"><?= icon('refresh') ?><?= e(__('dbupdate.button')) ?></button>
</form>
<?php
echo view('layout_auth', ['title' => __('dbupdate.title'), 'content' => ob_get_clean()]);
