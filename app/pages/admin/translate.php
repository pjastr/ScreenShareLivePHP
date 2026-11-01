<?php
defined('SSLIVE') || exit;

$code = (string)($_GET['code'] ?? '');
$lang = DB::row('SELECT * FROM {languages} WHERE `code` = ?', [$code]);
if (!$lang) {
    redirect(url('admin-languages'));
}
$keys = I18n::allKeys();
$builtin = I18n::builtinFile($code);
$overrides = I18n::dbOverrides($code);
$en = I18n::builtinFile('en');
$pl = I18n::builtinFile('pl');

if (is_post()) {
    $name = mb_substr(post('lang_name'), 0, 64);
    if ($name !== '') {
        DB::update('languages', ['name' => $name], '`code` = ?', [$code]);
    }
    $in = $_POST['t'] ?? [];
    $known = array_flip($keys);
    $changed = 0;
    if (is_array($in)) {
        foreach ($in as $k => $v) {
            if (!is_string($k) || !isset($known[$k]) || !is_string($v)) {
                continue;
            }
            $v = str_replace("\r\n", "\n", $v);
            $base = $builtin[$k] ?? null;
            if ($v === '' || ($base !== null && $v === $base)) {
                if (isset($overrides[$k])) {
                    DB::run('DELETE FROM {translations} WHERE `lang` = ? AND `tkey` = ?', [$code, $k]);
                    $changed++;
                }
                continue;
            }
            if (($overrides[$k] ?? null) !== $v) {
                DB::run('INSERT INTO {translations} (`lang`,`tkey`,`tvalue`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `tvalue` = VALUES(`tvalue`)', [$code, $k, mb_substr($v, 0, 2000)]);
                $changed++;
            }
        }
    }
    flash('success', __('admin.lang.saved_n', ['n' => $changed]));
    redirect(url('admin-translate', ['code' => $code]));
}

$refCode = $code === 'en' ? 'pl' : 'en';
$ref = $refCode === 'en' ? $en : $pl;
$missing = 0;
foreach ($keys as $k) {
    if (!isset($overrides[$k]) && !isset($builtin[$k])) {
        $missing++;
    }
}

ob_start(); ?>
<div class="page-head">
  <div>
    <a class="back-link" href="<?= e(url('admin-languages')) ?>"><?= icon('chevron-left') ?><?= e(__('admin.nav.languages')) ?></a>
    <h1><?= e(__('admin.lang.translate')) ?>: <?= e($lang['name']) ?> <code class="muted">(<?= e($code) ?>)</code></h1>
    <p class="muted"><?= e(__('admin.lang.translate_sub', ['missing' => $missing, 'total' => count($keys)])) ?></p>
  </div>
  <div class="page-actions">
    <div class="search"><?= icon('search') ?><input type="search" id="tr-search" placeholder="<?= e(__('common.search')) ?>"></div>
    <label class="check"><input type="checkbox" id="tr-missing"> <?= e(__('admin.lang.only_missing')) ?></label>
  </div>
</div>
<form method="post" class="tr-form" id="tr-form">
  <?= csrf_field() ?>
  <div class="card form">
    <label class="field"><span><?= e(__('admin.lang.name')) ?></span><input type="text" name="lang_name" value="<?= e($lang['name']) ?>" maxlength="64"></label>
  </div>
  <div class="card table-card">
    <table class="table tr-table">
      <thead><tr><th style="width:22%"><?= e(__('admin.lang.key')) ?></th><th style="width:34%"><?= e(__('admin.lang.reference', ['lang' => strtoupper($refCode)])) ?></th><th><?= e(__('admin.lang.translation')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($keys as $k):
          $val = $overrides[$k] ?? ($builtin[$k] ?? '');
          $isMissing = !isset($overrides[$k]) && !isset($builtin[$k]); ?>
        <tr data-key="<?= e(mb_strtolower($k . ' ' . ($ref[$k] ?? '') . ' ' . $val)) ?>" data-missing="<?= $isMissing ? 1 : 0 ?>">
          <td><code class="small"><?= e($k) ?></code><?= isset($overrides[$k]) ? ' <span class="badge badge-accent">' . e(__('admin.lang.custom')) . '</span>' : '' ?></td>
          <td class="small muted"><?= e($ref[$k] ?? ($en[$k] ?? '')) ?></td>
          <td><textarea name="t[<?= e($k) ?>]" rows="1" class="<?= $isMissing ? 'is-missing' : '' ?>" placeholder="<?= e($en[$k] ?? '') ?>"><?= e($val) ?></textarea></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="sticky-save"><button class="btn btn-primary" type="submit"><?= icon('check') ?><?= e(__('common.save')) ?></button></div>
</form>
<?php
echo view('layout_app', ['title' => __('admin.lang.translate'), 'content' => ob_get_clean(), 'active' => 'admin-languages', 'admin' => true]);
