<?php
defined('SSLIVE') || exit;

$keys = I18n::allKeys();
$total = count($keys);

/** Pełny słownik języka: plik + nadpisania z bazy (bez uzupełnień z angielskiego). */
function lang_full(string $code): array
{
    return array_merge(I18n::builtinFile($code), I18n::dbOverrides($code));
}

// Eksport JSON
if (($_GET['export'] ?? '') !== '') {
    $code = (string)$_GET['export'];
    $row = DB::row('SELECT * FROM {languages} WHERE `code` = ?', [$code]);
    if ($row) {
        $data = ['code' => $row['code'], 'name' => $row['name'], 'app' => APP_NAME, 'version' => APP_VERSION, 'strings' => lang_full($code)];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="sslive-lang-' . $code . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (is_post()) {
    $action = post('action');
    $code = post('code');
    $default = Settings::get('default_lang', 'pl');
    switch ($action) {
        case 'add':
            $name = mb_substr(post('name'), 0, 64);
            $code = strtolower($code);
            if (!valid_lang_code($code) || $name === '') {
                flash('error', __('admin.lang.invalid'));
            } elseif (DB::value('SELECT COUNT(*) FROM {languages} WHERE `code` = ?', [$code])) {
                flash('error', __('admin.lang.exists'));
            } else {
                DB::insert('languages', ['code' => $code, 'name' => $name, 'active' => 0, 'builtin' => 0]);
                $from = post('copy_from');
                if ($from !== '' && DB::value('SELECT COUNT(*) FROM {languages} WHERE `code` = ?', [$from])) {
                    foreach (lang_full($from) as $k => $v) {
                        DB::run('INSERT INTO {translations} (`lang`,`tkey`,`tvalue`) VALUES (?,?,?)', [$code, $k, $v]);
                    }
                }
                flash('success', __('admin.lang.added'));
                redirect(url('admin-translate', ['code' => $code]));
            }
            break;
        case 'toggle':
            $row = DB::row('SELECT * FROM {languages} WHERE `code` = ?', [$code]);
            if ($row && !($row['active'] && $code === $default)) {
                DB::update('languages', ['active' => $row['active'] ? 0 : 1], '`code` = ?', [$code]);
                flash('success', __('common.saved'));
            } else {
                flash('error', __('admin.lang.cant_default'));
            }
            break;
        case 'default':
            DB::update('languages', ['active' => 1], '`code` = ?', [$code]);
            Settings::set('default_lang', $code);
            flash('success', __('common.saved'));
            break;
        case 'delete':
            $row = DB::row('SELECT * FROM {languages} WHERE `code` = ?', [$code]);
            if (!$row || $row['builtin'] || $code === $default) {
                flash('error', __('admin.lang.cant_delete'));
            } else {
                DB::run('DELETE FROM {translations} WHERE `lang` = ?', [$code]);
                DB::run('DELETE FROM {languages} WHERE `code` = ?', [$code]);
                DB::run('UPDATE {users} SET `lang` = NULL WHERE `lang` = ?', [$code]);
                flash('success', __('admin.lang.deleted'));
            }
            break;
        case 'import':
            $f = $_FILES['file'] ?? null;
            $data = ($f && ($f['error'] ?? 1) === UPLOAD_ERR_OK) ? json_decode((string)file_get_contents($f['tmp_name']), true) : null;
            if (!is_array($data) || !valid_lang_code((string)($data['code'] ?? '')) || !is_array($data['strings'] ?? null)) {
                flash('error', __('admin.lang.import_invalid'));
                break;
            }
            $code = (string)$data['code'];
            if (!DB::value('SELECT COUNT(*) FROM {languages} WHERE `code` = ?', [$code])) {
                DB::insert('languages', ['code' => $code, 'name' => mb_substr((string)($data['name'] ?? $code), 0, 64), 'active' => 0, 'builtin' => 0]);
            }
            $builtin = I18n::builtinFile($code);
            $known = array_flip($keys);
            $n = 0;
            foreach ($data['strings'] as $k => $v) {
                if (!is_string($k) || !is_string($v) || !isset($known[$k])) {
                    continue;
                }
                if (isset($builtin[$k]) && $builtin[$k] === $v) {
                    DB::run('DELETE FROM {translations} WHERE `lang` = ? AND `tkey` = ?', [$code, $k]);
                    continue;
                }
                DB::run('INSERT INTO {translations} (`lang`,`tkey`,`tvalue`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `tvalue` = VALUES(`tvalue`)', [$code, $k, mb_substr($v, 0, 2000)]);
                $n++;
            }
            flash('success', __('admin.lang.imported', ['n' => $n]));
            break;
    }
    redirect(url('admin-languages'));
}

$rows = DB::all('SELECT * FROM {languages} ORDER BY `builtin` DESC, `name`');
$default = Settings::get('default_lang', 'pl');

ob_start(); ?>
<div class="page-head">
  <div><h1><?= e(__('admin.nav.languages')) ?></h1><p class="muted"><?= e(__('admin.lang.sub')) ?></p></div>
  <div class="page-actions">
    <button type="button" class="btn" data-modal-open="import-lang"><?= icon('upload') ?><span><?= e(__('admin.lang.import')) ?></span></button>
    <button type="button" class="btn btn-primary" data-modal-open="add-lang"><?= icon('plus') ?><span><?= e(__('admin.lang.add')) ?></span></button>
  </div>
</div>
<div class="card table-card">
  <table class="table">
    <thead><tr><th><?= e(__('admin.lang.name')) ?></th><th><?= e(__('admin.lang.code')) ?></th><th><?= e(__('admin.lang.progress')) ?></th><th><?= e(__('admin.users.status')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $full = lang_full($r['code']);
        $done = count(array_intersect_key($full, array_flip($keys)));
        $pct = $total ? floor($done / $total * 100) : 0; ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong>
          <?php if ($r['code'] === $default): ?> <span class="badge badge-accent"><?= e(__('admin.lang.default')) ?></span><?php endif; ?>
          <?php if ($r['builtin']): ?> <span class="badge"><?= e(__('admin.lang.builtin')) ?></span><?php endif; ?>
        </td>
        <td><code><?= e($r['code']) ?></code></td>
        <td class="progress-cell"><div class="progress"><i style="width: <?= $pct ?>%"></i></div><span class="small muted"><?= $done ?>/<?= $total ?></span></td>
        <td><span class="badge <?= $r['active'] ? 'badge-success' : '' ?>"><?= e($r['active'] ? __('common.enabled') : __('common.disabled')) ?></span></td>
        <td class="actions">
          <a class="btn btn-sm" href="<?= e(url('admin-translate', ['code' => $r['code']])) ?>"><?= icon('edit') ?><?= e(__('admin.lang.translate')) ?></a>
          <div class="dropdown">
            <button type="button" class="btn-icon" data-dropdown><?= icon('dots-vertical') ?></button>
            <div class="menu menu-right">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="code" value="<?= e($r['code']) ?>">
                <button class="menu-item" type="submit"><?= icon($r['active'] ? 'eye-off' : 'eye') ?><?= e($r['active'] ? __('admin.lang.deactivate') : __('admin.lang.activate')) ?></button></form>
              <?php if ($r['code'] !== $default): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="default"><input type="hidden" name="code" value="<?= e($r['code']) ?>">
                <button class="menu-item" type="submit"><?= icon('star') ?><?= e(__('admin.lang.make_default')) ?></button></form>
              <?php endif; ?>
              <a class="menu-item" href="<?= e(url('admin-languages', ['export' => $r['code']])) ?>"><?= icon('download') ?><?= e(__('admin.lang.export')) ?></a>
              <?php if (!$r['builtin'] && $r['code'] !== $default): ?>
              <div class="menu-sep"></div>
              <form method="post" data-confirm="<?= e(__('admin.lang.delete_confirm', ['name' => $r['name']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="code" value="<?= e($r['code']) ?>">
                <button class="menu-item danger" type="submit"><?= icon('trash') ?><?= e(__('common.delete')) ?></button></form>
              <?php endif; ?>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted small"><?= e(__('admin.lang.fallback_hint')) ?></p>

<dialog class="modal" id="add-lang">
  <form class="modal-box" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-head"><h2><?= e(__('admin.lang.add')) ?></h2><button type="button" class="btn-icon" data-modal-close><?= icon('x') ?></button></div>
    <label class="field"><span><?= e(__('admin.lang.name')) ?></span><input type="text" name="name" required maxlength="64" placeholder="Deutsch"></label>
    <label class="field"><span><?= e(__('admin.lang.code')) ?></span><input type="text" name="code" required maxlength="12" pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8})?" placeholder="de"><small class="hint"><?= e(__('admin.lang.code_hint')) ?></small></label>
    <label class="field"><span><?= e(__('admin.lang.copy_from')) ?></span>
      <select name="copy_from"><option value=""><?= e(__('admin.lang.copy_none')) ?></option>
        <?php foreach ($rows as $r): ?><option value="<?= e($r['code']) ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select></label>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-modal-close><?= e(__('common.cancel')) ?></button><button class="btn btn-primary" type="submit"><?= e(__('common.create')) ?></button></div>
  </form>
</dialog>
<dialog class="modal" id="import-lang">
  <form class="modal-box" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <div class="modal-head"><h2><?= e(__('admin.lang.import')) ?></h2><button type="button" class="btn-icon" data-modal-close><?= icon('x') ?></button></div>
    <p class="muted small"><?= e(__('admin.lang.import_hint')) ?></p>
    <label class="field"><span>JSON</span><input type="file" name="file" accept=".json,application/json" required></label>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-modal-close><?= e(__('common.cancel')) ?></button><button class="btn btn-primary" type="submit"><?= e(__('admin.lang.import')) ?></button></div>
  </form>
</dialog>
<?php
echo view('layout_app', ['title' => __('admin.nav.languages'), 'content' => ob_get_clean(), 'active' => 'admin-languages', 'admin' => true]);
