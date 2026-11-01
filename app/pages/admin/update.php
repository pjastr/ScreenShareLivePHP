<?php
defined('SSLIVE') || exit;

require_once ROOT . '/app/lib/Updater.php';

$repo = Settings::get('update_repo', APP_UPDATE_REPO);
$req = Updater::requirements();
$canAuto = $req['zip'] && $req['writable'] && $req['storage'];
$release = $_SESSION['update_check'] ?? null;

if (is_post()) {
    $action = post('action');
    @set_time_limit(300);
    try {
        if ($action === 'check') {
            $rel = Updater::latestRelease($repo);
            $rel['checked'] = time();
            $_SESSION['update_check'] = $rel;
            flash(version_compare($rel['version'], APP_VERSION, '>') ? 'info' : 'success',
                version_compare($rel['version'], APP_VERSION, '>') ? __('update.available', ['v' => $rel['version']]) : __('update.up_to_date'));
        } elseif ($action === 'install_remote' && $canAuto && $release) {
            $tmp = Updater::tmpDir() . '/download_' . bin2hex(random_bytes(4)) . '.zip';
            try {
                Updater::http($release['zip'], $tmp);
                $v = Updater::installZip($tmp);
            } finally {
                @unlink($tmp);
            }
            unset($_SESSION['update_check']);
            flash('success', __('update.files_installed', ['v' => $v]));
            redirect(url('dbupdate'));
        } elseif ($action === 'install_zip' && $canAuto) {
            $f = $_FILES['package'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw new RuntimeException(__('update.upload_failed'));
            }
            $v = Updater::installZip($f['tmp_name']);
            flash('success', __('update.files_installed', ['v' => $v]));
            redirect(url('dbupdate'));
        }
    } catch (Throwable $e) {
        flash('error', __('update.error') . ' ' . $e->getMessage());
    }
    redirect(url('admin-update'));
}

$hasNew = $release && version_compare($release['version'], APP_VERSION, '>');

ob_start(); ?>
<div class="page-head"><div><h1><?= e(__('admin.nav.update')) ?></h1><p class="muted"><?= e(__('update.sub')) ?></p></div></div>

<div class="grid-2">
  <section class="card">
    <h2 class="card-title"><?= icon('cloud-download') ?><?= e(__('update.online')) ?></h2>
    <dl class="kv">
      <dt><?= e(__('admin.version_files')) ?></dt><dd><strong><?= e(APP_VERSION) ?></strong></dd>
      <dt><?= e(__('dbupdate.schema')) ?></dt><dd><?= (int)Migrator::current() ?></dd>
      <dt><?= e(__('update.repo')) ?></dt><dd><a href="https://github.com/<?= e($repo) ?>/releases" target="_blank" rel="noopener"><?= icon('brand-github') ?> <?= e($repo) ?></a></dd>
      <?php if ($release): ?>
      <dt><?= e(__('update.latest')) ?></dt><dd><strong><?= e($release['version']) ?></strong> <span class="muted small">(<?= e(date('H:i', (int)$release['checked'])) ?>)</span></dd>
      <?php endif; ?>
    </dl>
    <?php if ($hasNew && $release['notes'] !== ''): ?>
      <details class="notes"><summary><?= e(__('update.notes')) ?></summary><pre><?= e(mb_substr($release['notes'], 0, 5000)) ?></pre></details>
    <?php endif; ?>
    <div class="row-actions">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check">
        <button class="btn" type="submit"><?= icon('refresh') ?><?= e(__('update.check')) ?></button></form>
      <?php if ($hasNew && $canAuto): ?>
      <form method="post" data-confirm="<?= e(__('update.confirm', ['v' => $release['version']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="install_remote">
        <button class="btn btn-primary" type="submit"><?= icon('download') ?><?= e(__('update.install', ['v' => $release['version']])) ?></button></form>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <h2 class="card-title"><?= icon('file-zip') ?><?= e(__('update.zip')) ?></h2>
    <p class="muted"><?= e(__('update.zip_text')) ?></p>
    <?php if ($canAuto): ?>
    <form method="post" enctype="multipart/form-data" class="form" data-confirm="<?= e(__('update.zip_confirm')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="install_zip">
      <label class="field"><span><?= e(__('update.package')) ?></span><input type="file" name="package" accept=".zip,application/zip" required></label>
      <button class="btn btn-primary" type="submit"><?= icon('upload') ?><?= e(__('update.zip_install')) ?></button>
    </form>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2 class="card-title"><?= icon('checks') ?><?= e(__('update.requirements')) ?></h2>
  <ul class="checklist">
    <?php foreach (['zip' => 'update.req.zip', 'writable' => 'update.req.writable', 'storage' => 'update.req.storage', 'http' => 'update.req.http'] as $k => $label): ?>
    <li class="<?= $req[$k] ? 'ok' : 'bad' ?>"><?= icon($req[$k] ? 'circle-check' : 'circle-x') ?><?= e(__($label)) ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="muted small"><?= e(__('update.manual')) ?></p>
  <div class="row-actions"><a class="btn" href="<?= e(url('admin-backup')) ?>"><?= icon('database-export') ?><?= e(__('admin.backup_download')) ?></a></div>
</section>
<?php
echo view('layout_app', ['title' => __('admin.nav.update'), 'content' => ob_get_clean(), 'active' => 'admin-update', 'admin' => true]);
