<?php
defined('SSLIVE') || exit;
/** @var string $title @var string $content */
echo view('head', ['title' => $title ?? '', 'extraCss' => $extraCss ?? []]);
$langs = I18n::languages();
?>
<body class="auth-body">
<div class="auth-bg" aria-hidden="true"></div>
<main class="auth-wrap">
  <a class="brand brand-lg" href="<?= e(url('home')) ?>">
    <span class="brand-mark"><?= icon('screen-share') ?></span>
    <span><?= e(Settings::get('site_name', 'ScreenShare Live')) ?></span>
  </a>
  <div class="card auth-card">
    <?= view('flashes') ?>
    <?= $content ?>
  </div>
  <div class="auth-foot">
    <?php if (count($langs) > 1): ?>
    <div class="lang-links">
      <?= icon('language') ?>
      <?php foreach ($langs as $code => $name): ?>
        <a href="?<?= e(http_build_query(array_merge($_GET, ['lang' => $code]))) ?>" class="<?= $code === I18n::lang() ? 'active' : '' ?>"><?= e($name) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <button type="button" class="btn-icon" data-theme-toggle title="<?= e(__('common.theme')) ?>"><?= icon('moon', 'ic-moon') ?><?= icon('sun', 'ic-sun') ?></button>
  </div>
</main>
<script src="<?= e(asset('js/icons.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (($extraJs ?? []) as $js): ?>
<script src="<?= e(asset($js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
