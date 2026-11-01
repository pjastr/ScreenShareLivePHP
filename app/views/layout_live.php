<?php
defined('SSLIVE') || exit;
/** @var string $title @var array $liveCfg */
echo view('head', ['title' => $title ?? '', 'extraCss' => ['css/live.css']]);
$s = $liveCfg['stream'];
?>
<body class="live-body">
<script type="application/json" id="live-config"><?= json_encode($liveCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<div class="player" id="player">
  <header class="player-bar">
    <div class="pb-left">
      <span class="live-pill" id="pill"><i></i><span id="pill-text"><?= e(__('live.state.connecting')) ?></span></span>
      <div class="pb-title">
        <strong title="<?= e($s['title']) ?>"><?= e($s['title']) ?></strong>
      </div>
    </div>
    <div class="pb-right">
      <span class="pb-chip" title="<?= e(__('live.remaining')) ?>"><?= icon('hourglass') ?><span id="remain">—</span></span>
      <span class="pb-chip" title="<?= e(__('live.viewers')) ?>"><?= icon('users') ?><span id="vcount">—</span></span>
      <div class="dropdown">
        <button type="button" class="btn-icon" data-dropdown id="btn-q" title="<?= e(__('live.view.quality')) ?>"><?= icon('adjustments') ?><span class="q-label" id="q-label"></span></button>
        <div class="menu menu-right" id="q-menu">
          <div class="menu-head"><strong><?= e(__('live.view.quality')) ?></strong><span class="muted small"><?= e(__('live.view.quality_hint')) ?></span></div>
          <?php foreach (['high', 'medium', 'low'] as $lv): ?>
          <button type="button" class="menu-item" data-vq="<?= $lv ?>"><?= icon('check', 'mi-check') ?><span><?= e(__('live.vq.' . $lv)) ?><small class="muted"><?= e(__('live.vq.' . $lv . '_hint')) ?></small></span></button>
          <?php endforeach; ?>
          <div class="menu-sep"></div>
          <button type="button" class="menu-item" id="btn-fit"><?= icon('aspect-ratio') ?><span id="fit-label"><?= e(__('live.view.actual_size')) ?></span></button>
          <button type="button" class="menu-item" id="btn-stats"><?= icon('chart-bar') ?><span><?= e(__('live.stats')) ?></span></button>
        </div>
      </div>
      <button type="button" class="btn-icon" id="btn-sound" hidden title="<?= e(__('live.view.sound')) ?>"><?= icon('volume-off') ?></button>
      <button type="button" class="btn-icon" id="btn-fs" title="<?= e(__('live.view.fullscreen')) ?>"><?= icon('maximize') ?></button>
      <button type="button" class="btn-icon" data-theme-toggle title="<?= e(__('common.theme')) ?>"><?= icon('moon', 'ic-moon') ?><?= icon('sun', 'ic-sun') ?></button>
      <form method="post" action="<?= e(url('home')) ?>" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="leave">
        <button type="submit" class="btn-icon" title="<?= e(__('live.view.leave')) ?>"><?= icon('door-exit') ?></button>
      </form>
    </div>
  </header>
  <main class="stage" id="stage">
    <video id="video" playsinline autoplay muted></video>
    <img id="frame" alt="" hidden>
    <div class="stats-box" id="stats" hidden></div>
    <div class="overlay" id="overlay">
      <div class="ov-box">
        <div class="ov-icon" id="ov-icon"><?= icon('loader-2', 'spin') ?></div>
        <h2 id="ov-title"><?= e(__('live.state.connecting')) ?></h2>
        <p class="muted" id="ov-text"></p>
        <div class="row-actions center" id="ov-actions"></div>
      </div>
    </div>
    <button type="button" class="tap-sound" id="tap-sound" hidden><?= icon('volume') ?><?= e(__('live.view.tap_sound')) ?></button>
  </main>
  <div class="mode-note" id="mode-note" hidden>
    <?= icon('wifi-off') ?><span><?= e(__('live.view.fallback_note')) ?></span>
    <button type="button" class="btn btn-sm" id="btn-retry"><?= icon('refresh') ?><?= e(__('live.view.retry_rtc')) ?></button>
  </div>
</div>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script src="<?= e(asset('js/icons.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/live.js')) ?>"></script>
</body>
</html>
