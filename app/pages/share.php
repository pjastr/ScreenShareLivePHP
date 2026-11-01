<?php
defined('SSLIVE') || exit;

$u = Auth::user();

// Administrator może zakończyć cudzą transmisję
if (is_post() && post('action') === 'end_stream') {
    $s = Live::find((int)post('sid'));
    if ($s && Live::isLive($s) && (Auth::isAdmin() || (int)$s['user_id'] === (int)$u['id'])) {
        Live::end((int)$s['id'], (int)$s['user_id'] === (int)$u['id'] ? 'manual' : 'admin');
        flash('success', __('live.ended_ok'));
    }
    redirect(url(post('back') === 'admin' ? 'admin' : 'share'));
}

$active = Live::active();
$own = $active && (int)$active['user_id'] === (int)$u['id'];
$maxV = Live::maxViewers();
$quality = Settings::get('live_quality', 'balanced');
$minutesOpts = array_values(array_filter([15, 30, 45, 60, 90, 120, 150, 180], fn($m) => $m <= Live::MAX_MINUTES));
$defMin = Live::defaultMinutes();
if (!in_array($defMin, $minutesOpts, true)) {
    $minutesOpts[] = $defMin;
    sort($minutesOpts);
}

$cfg = [
    'mode' => $active ? ($own ? 'own' : 'other') : 'setup',
    'stream' => $own ? Live::publicInfo($active) : null,
    'code' => $own ? $active['code'] : null,
    'maxViewers' => $own ? (int)$active['max_viewers'] : $maxV,
    'pubRecent' => $own && Live::ts($active['pub_seen_at']) > time() - 6,
    'ice' => Live::iceServers(),
    'qualities' => Live::qualities(),
    'quality' => $quality,
    'viewerUrl' => Live::viewerUrl(),
    'maxMinutes' => Live::MAX_MINUTES,
];

$recent = DB::all('SELECT * FROM {streams} WHERE `user_id` = ? AND `active_slot` IS NULL ORDER BY `id` DESC LIMIT 5', [(int)$u['id']]);

ob_start(); ?>
<script type="application/json" id="share-config"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>

<?php if ($active && !$own): ?>
<div class="page-head"><div><h1><?= e(__('nav.share')) ?></h1><p class="muted"><?= e(__('live.share_sub')) ?></p></div></div>
<section class="card busy-card">
  <div class="busy-head">
    <span class="live-pill connected"><i></i><?= e(__('live.state.live')) ?></span>
    <h2><?= e($active['title']) ?></h2>
  </div>
  <p class="muted"><?= e(__('live.other_running', ['user' => $active['username'] ?? '?', 'time' => date('H:i', Live::ts($active['ends_at']))])) ?></p>
  <?php if (Auth::isAdmin()): ?>
  <dl class="kv">
    <dt><?= e(__('live.code')) ?></dt><dd><code class="code-inline"><?= e(chunk_split($active['code'], 3, ' ')) ?></code></dd>
    <dt><?= e(__('live.viewers')) ?></dt><dd><?= Live::viewerCount((int)$active['id']) ?> / <?= (int)$active['max_viewers'] ?></dd>
    <dt><?= e(__('live.started')) ?></dt><dd><?= e(format_date($active['started_at'])) ?></dd>
  </dl>
  <form method="post" data-confirm="<?= e(__('live.end_confirm_admin')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="end_stream"><input type="hidden" name="sid" value="<?= (int)$active['id'] ?>">
    <button class="btn btn-danger" type="submit"><?= icon('player-stop') ?><?= e(__('live.end')) ?></button>
  </form>
  <?php else: ?>
  <p class="small muted"><?= icon('info-circle') ?> <?= e(__('live.other_wait')) ?></p>
  <?php endif; ?>
</section>

<?php else: ?>
<!-- Formularz rozpoczęcia -->
<div id="setup" <?= $own ? 'hidden' : '' ?>>
  <div class="page-head"><div><h1><?= e(__('nav.share')) ?></h1><p class="muted"><?= e(__('live.share_sub')) ?></p></div></div>
  <div class="setup-grid">
    <form class="card form" id="setup-form">
      <h2 class="card-title"><?= icon('screen-share') ?><?= e(__('live.new')) ?></h2>
      <label class="field"><span><?= e(__('live.title')) ?></span>
        <input type="text" name="title" maxlength="190" placeholder="<?= e(__('live.title_ph')) ?>"></label>
      <div class="grid-2 tight">
        <label class="field"><span><?= e(__('live.duration')) ?></span>
          <select name="minutes">
            <?php foreach ($minutesOpts as $m): ?>
            <option value="<?= $m ?>" <?= $m === $defMin ? 'selected' : '' ?>><?= e(__('live.minutes', ['n' => $m])) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="hint"><?= e(__('live.duration_hint', ['n' => Live::MAX_MINUTES])) ?></small></label>
        <label class="field"><span><?= e(__('live.max_viewers')) ?></span>
          <input type="number" name="max_viewers" min="1" max="<?= $maxV ?>" value="<?= $maxV ?>">
          <small class="hint"><?= e(__('live.max_viewers_hint', ['n' => $maxV])) ?></small></label>
      </div>
      <div class="field"><span><?= e(__('live.quality')) ?></span>
        <div class="seg-cards">
          <?php foreach (array_keys(Live::qualities()) as $q): ?>
          <label class="seg-card"><input type="radio" name="quality" value="<?= e($q) ?>" <?= $q === $quality ? 'checked' : '' ?>>
            <span><strong><?= e(__('live.q.' . $q)) ?></strong><small class="muted"><?= e(__('live.q.' . $q . '_hint')) ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <label class="switch-field">
        <input type="checkbox" name="audio" value="1">
        <span class="switch" aria-hidden="true"></span>
        <span><strong><?= e(__('live.audio')) ?></strong><small class="hint"><?= e(__('live.audio_hint')) ?></small></span>
      </label>
      <div class="alert alert-warning" id="warn-secure" hidden><?= icon('alert-triangle') ?><span><?= e(__('live.err.https')) ?></span></div>
      <div class="alert alert-warning" id="warn-support" hidden><?= icon('alert-triangle') ?><span><?= e(__('live.err.unsupported')) ?></span></div>
      <button class="btn btn-primary btn-block btn-lg" type="submit" id="btn-start"><?= icon('screen-share') ?><?= e(__('live.start')) ?></button>
    </form>
    <aside class="card how-card">
      <h2 class="card-title"><?= icon('help') ?><?= e(__('live.how.title')) ?></h2>
      <ol class="how-list">
        <li><?= e(__('live.how.1')) ?></li>
        <li><?= e(__('live.how.2', ['url' => Live::viewerUrl()])) ?></li>
        <li><?= e(__('live.how.3')) ?></li>
        <li><?= e(__('live.how.4', ['n' => Live::MAX_MINUTES])) ?></li>
      </ol>
      <?php if ($recent): ?>
      <hr class="sep">
      <h3 class="card-title small-title"><?= icon('history') ?><?= e(__('live.recent')) ?></h3>
      <ul class="recent-list">
        <?php foreach ($recent as $r): $dur = max(0, Live::ts($r['ended_at']) - Live::ts($r['started_at'])); ?>
        <li><span><?= e($r['title']) ?></span><span class="muted small"><?= e(format_date($r['started_at'])) ?> · <?= e(__('live.minutes', ['n' => (int)round($dur / 60)])) ?> · <?= icon('users') ?><?= (int)$r['peak_viewers'] ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </aside>
  </div>
</div>

<!-- Panel trwającej transmisji -->
<div id="live" hidden>
  <div class="live-head">
    <div class="lh-title">
      <span class="live-pill" id="pub-pill"><i></i><span id="pub-pill-text"><?= e(__('live.state.live')) ?></span></span>
      <h1 id="live-title"><?= e($own ? $active['title'] : '') ?></h1>
    </div>
    <div class="lh-actions">
      <span class="pb-chip big" title="<?= e(__('live.remaining')) ?>"><?= icon('hourglass') ?><span id="remain">—</span></span>
      <button class="btn" type="button" id="btn-extend"><?= icon('clock-plus') ?><?= e(__('live.extend')) ?></button>
      <button class="btn btn-danger" type="button" id="btn-end"><?= icon('player-stop') ?><?= e(__('live.end')) ?></button>
    </div>
  </div>
  <div class="live-grid">
    <section class="card preview-card">
      <div class="preview">
        <video id="preview" muted autoplay playsinline></video>
        <div class="preview-empty" id="paused">
          <div class="ov-icon"><?= icon('screen-share-off') ?></div>
          <h2 id="paused-title"><?= e(__('live.paused')) ?></h2>
          <p class="muted" id="paused-text"><?= e(__('live.paused_text')) ?></p>
          <button class="btn btn-primary" type="button" id="btn-resume"><?= icon('screen-share') ?><?= e(__('live.resume')) ?></button>
        </div>
      </div>
      <div class="preview-bar">
        <span class="muted small" id="share-info"></span>
        <div class="row-actions">
          <button class="btn btn-sm" type="button" id="btn-switch"><?= icon('device-desktop') ?><?= e(__('live.switch')) ?></button>
          <button class="btn btn-sm" type="button" id="btn-stop-share"><?= icon('screen-share-off') ?><?= e(__('live.pause')) ?></button>
        </div>
      </div>
    </section>
    <aside class="live-side">
      <section class="card code-card">
        <div class="muted small upper"><?= e(__('live.code_for_students')) ?></div>
        <div class="code-display" id="code">— — —</div>
        <div class="code-url"><span id="code-url"><?= e(Live::viewerUrl()) ?></span></div>
        <div class="row-actions">
          <button class="btn btn-sm btn-primary" type="button" id="btn-present"><?= icon('qrcode') ?><?= e(__('live.present')) ?></button>
          <button class="btn btn-sm" type="button" id="btn-copy-link"><?= icon('link') ?><?= e(__('live.copy_link')) ?></button>
          <button class="btn btn-sm btn-ghost" type="button" id="btn-new-code" title="<?= e(__('live.new_code_hint')) ?>"><?= icon('refresh') ?><?= e(__('live.new_code')) ?></button>
        </div>
      </section>
      <section class="card viewers-card">
        <h2 class="card-title"><?= icon('users') ?><?= e(__('live.viewers')) ?> <span class="badge" id="vcount">0</span></h2>
        <ul class="viewer-list" id="viewers"><li class="muted small empty-v"><?= e(__('live.no_viewers')) ?></li></ul>
      </section>
    </aside>
  </div>
</div>

<dialog class="modal present-modal" id="present">
  <div class="modal-box present-box">
    <button type="button" class="btn-icon present-close" data-modal-close aria-label="<?= e(__('common.close')) ?>"><?= icon('x') ?></button>
    <p class="present-lead"><?= e(__('live.present_lead')) ?></p>
    <div class="present-url" id="present-url"><?= e(Live::viewerUrl()) ?></div>
    <div class="present-code" id="present-code"></div>
    <div class="present-qr" id="present-qr"></div>
  </div>
</dialog>
<?php endif; ?>
<?php
echo view('layout_app', ['title' => __('nav.share'), 'content' => ob_get_clean(), 'active' => 'share', 'extraCss' => ['css/live.css'], 'extraJs' => ['vendor/qrcode.js', 'js/share.js']]);
