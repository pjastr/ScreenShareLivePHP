<?php
defined('SSLIVE') || exit;
/** @var string $title @var string $content @var string|null $active @var bool|null $admin */
echo view('head', ['title' => $title ?? '', 'extraCss' => $extraCss ?? []]);
$u = Auth::user();
$langs = I18n::languages();
$active = $active ?? '';
$adminNav = [
    'admin' => ['layout-dashboard', __('admin.nav.overview')],
    'admin-users' => ['users', __('admin.nav.users')],
    'admin-settings' => ['adjustments', __('admin.nav.settings')],
    'admin-streams' => ['history', __('admin.nav.streams')],
    'admin-logins' => ['list-details', __('admin.nav.logins')],
    'admin-languages' => ['language', __('admin.nav.languages')],
    'admin-update' => ['refresh', __('admin.nav.update')],
];
?>
<body class="app-body">
<header class="topbar">
  <a class="brand" href="<?= e(url('share')) ?>">
    <span class="brand-mark"><?= icon('screen-share') ?></span>
    <span class="brand-name"><?= e(Settings::get('site_name', 'ScreenShare Live')) ?></span>
  </a>
  <nav class="topnav">
    <a href="<?= e(url('share')) ?>" class="<?= $active === 'share' ? 'active' : '' ?>"><?= icon('screen-share') ?><span><?= e(__('nav.share')) ?></span></a>
    <a href="<?= e(url('home')) ?>" target="_blank" rel="noopener"><?= icon('external-link') ?><span><?= e(__('nav.viewer_page')) ?></span></a>
    <?php if (Auth::isAdmin()): ?>
    <a href="<?= e(url('admin')) ?>" class="<?= !empty($admin) ? 'active' : '' ?>"><?= icon('shield-lock') ?><span><?= e(__('nav.admin')) ?></span></a>
    <?php endif; ?>
  </nav>
  <div class="topbar-right">
    <button type="button" class="btn-icon" data-theme-toggle title="<?= e(__('common.theme')) ?>"><?= icon('moon', 'ic-moon') ?><?= icon('sun', 'ic-sun') ?></button>
    <?php if (count($langs) > 1): ?>
    <div class="dropdown">
      <button type="button" class="btn-icon" data-dropdown title="<?= e(__('common.language')) ?>"><?= icon('language') ?><span class="lang-code"><?= e(strtoupper(I18n::lang())) ?></span></button>
      <div class="menu menu-right">
        <?php foreach ($langs as $code => $name): ?>
        <a class="menu-item <?= $code === I18n::lang() ? 'active' : '' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['lang' => $code]))) ?>"><?= e($name) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="dropdown">
      <button type="button" class="avatar-btn" data-dropdown aria-label="<?= e(__('nav.account')) ?>">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['username'], 0, 1))) ?></span>
      </button>
      <div class="menu menu-right">
        <div class="menu-head">
          <strong><?= e($u['username']) ?></strong>
          <span class="muted small"><?= e($u['email']) ?></span>
          <span class="badge <?= $u['role'] === 'admin' ? 'badge-accent' : '' ?>"><?= e(__('role.' . $u['role'])) ?></span>
        </div>
        <a class="menu-item" href="<?= e(url('profile')) ?>"><?= icon('user') ?><?= e(__('nav.profile')) ?></a>
        <?php if (Auth::isAdmin()): ?>
        <a class="menu-item" href="<?= e(url('admin')) ?>"><?= icon('shield-lock') ?><?= e(__('nav.admin')) ?></a>
        <?php endif; ?>
        <form method="post" action="<?= e(url('logout')) ?>">
          <?= csrf_field() ?>
          <button class="menu-item" type="submit"><?= icon('logout') ?><?= e(__('nav.logout')) ?></button>
        </form>
      </div>
    </div>
  </div>
</header>
<?php if (!empty($admin)): ?>
<div class="admin-shell">
  <aside class="admin-side">
    <div class="side-title"><?= e(__('nav.admin')) ?></div>
    <?php foreach ($adminNav as $p => [$ic, $label]): ?>
      <a href="<?= e(url($p)) ?>" class="<?= $active === $p ? 'active' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </aside>
  <main class="admin-main">
    <?= view('flashes') ?>
    <?= $content ?>
  </main>
</div>
<?php else: ?>
<main class="page">
  <?= view('flashes') ?>
  <?= $content ?>
</main>
<?php endif; ?>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script src="<?= e(asset('js/icons.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (($extraJs ?? []) as $js): ?>
<script src="<?= e(asset($js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
