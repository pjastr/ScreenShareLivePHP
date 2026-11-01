<?php
defined('SSLIVE') || exit;

require_once ROOT . '/app/lib/Backup.php';

if (is_post() && post('action') === 'download') {
    Backup::stream();
}

ob_start(); ?>
<div class="page-head"><div>
  <a class="back-link" href="<?= e(url('admin-update')) ?>"><?= icon('chevron-left') ?><?= e(__('admin.nav.update')) ?></a>
  <h1><?= e(__('admin.backup_download')) ?></h1><p class="muted"><?= e(__('admin.backup_sub')) ?></p></div></div>
<section class="card">
  <p><?= e(__('admin.backup_text', ['prefix' => DB::prefix()])) ?></p>
  <ul class="list-plain small muted">
    <?php foreach (DB::ownTables() as $t): ?><li><code><?= e($t) ?></code></li><?php endforeach; ?>
  </ul>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="download">
    <button class="btn btn-primary" type="submit"><?= icon('database-export') ?><?= e(__('admin.backup_download')) ?> (.sql)</button>
  </form>
</section>
<?php
echo view(Migrator::needsUpdate() ? 'layout_auth' : 'layout_app', ['title' => __('admin.backup_download'), 'content' => ob_get_clean(), 'active' => 'admin-update', 'admin' => true]);
