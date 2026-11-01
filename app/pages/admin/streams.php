<?php
defined('SSLIVE') || exit;

if (is_post() && post('action') === 'clear') {
    DB::run('DELETE FROM {viewers} WHERE `stream_id` IN (SELECT `id` FROM {streams} WHERE `active_slot` IS NULL)');
    DB::run('DELETE FROM {streams} WHERE `active_slot` IS NULL');
    flash('success', __('admin.streams.cleared'));
    redirect(url('admin-streams'));
}

$rows = DB::all('SELECT s.*, u.`username` FROM {streams} s LEFT JOIN {users} u ON u.`id` = s.`user_id` ORDER BY s.`id` DESC LIMIT 300');

ob_start(); ?>
<div class="page-head">
  <div><h1><?= e(__('admin.nav.streams')) ?></h1><p class="muted"><?= e(__('admin.streams.sub')) ?></p></div>
  <?php if ($rows): ?>
  <div class="page-actions">
    <form method="post" data-confirm="<?= e(__('admin.streams.clear_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="clear">
      <button class="btn" type="submit"><?= icon('trash') ?><?= e(__('admin.streams.clear')) ?></button></form>
  </div>
  <?php endif; ?>
</div>
<div class="card table-card">
  <table class="table">
    <thead><tr>
      <th><?= e(__('live.title')) ?></th>
      <th><?= e(__('admin.streams.teacher')) ?></th>
      <th><?= e(__('live.started')) ?></th>
      <th class="num"><?= e(__('admin.streams.duration')) ?></th>
      <th><?= e(__('admin.streams.codec')) ?></th>
      <th class="num"><?= e(__('admin.streams.peak')) ?></th>
      <th class="num"><?= e(__('admin.streams.total')) ?></th>
      <th><?= e(__('admin.streams.end')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $live = (int)($r['active_slot'] ?? 0) === 1;
        $dur = max(0, ($live ? time() : Live::ts($r['ended_at'])) - Live::ts($r['started_at'])); ?>
      <tr>
        <td><?= $live ? '<span class="live-pill connected sm"><i></i>' . e(__('live.state.live')) . '</span> ' : '' ?><?= e($r['title']) ?></td>
        <td><?= e($r['username'] ?? __('admin.streams.deleted_user')) ?></td>
        <td><?= e(format_date($r['started_at'])) ?></td>
        <td class="num"><?= e(__('live.minutes', ['n' => (int)round($dur / 60)])) ?></td>
        <td class="small"><?= e(strtoupper((string)($r['codec'] ?? '')) ?: '—') ?><?= !empty($r['quality']) ? ' · <span class="muted">' . e(__('live.q.' . $r['quality'])) . '</span>' : '' ?></td>
        <td class="num"><?= (int)$r['peak_viewers'] ?></td>
        <td class="num"><?= (int)$r['total_viewers'] ?></td>
        <td class="muted small"><?= $live ? '—' : e(__('live.reason.' . ($r['end_reason'] ?: 'manual'))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted center"><?= e(__('admin.streams.none')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php
echo view('layout_app', ['title' => __('admin.nav.streams'), 'content' => ob_get_clean(), 'active' => 'admin-streams', 'admin' => true, 'extraCss' => ['css/live.css']]);
