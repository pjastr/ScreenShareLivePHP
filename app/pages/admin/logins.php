<?php
defined('SSLIVE') || exit;

/*
 * Statystyki i dziennik logowań: użytkownik, data, godzina, IP, wynik, przeglądarka.
 * Daty wyświetlane są w strefie czasowej serwera (dane w bazie są w UTC).
 */

/** Krótki opis przeglądarki i systemu z nagłówka User-Agent. */
function ua_short(string $ua): string
{
    if ($ua === '') {
        return '—';
    }
    $b = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => '',
    };
    $o = match (true) {
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };
    $s = trim($b . ($b && $o ? ' · ' : '') . $o);
    return $s !== '' ? $s : mb_substr($ua, 0, 40);
}

/** Data UTC z bazy -> DateTime w strefie serwera. */
function log_dt(string $utc): DateTime
{
    return (new DateTime('@' . strtotime($utc . ' UTC')))->setTimezone(app_tz());
}

/** Granica dnia (Y-m-d w strefie serwera) -> UTC dla zapytań. */
function day_utc(string $ymd, bool $end): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return null;
    }
    try {
        $d = new DateTime($ymd . ($end ? ' 23:59:59' : ' 00:00:00'), app_tz());
    } catch (Throwable $e) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $d->getTimestamp());
}

// Ustawienie: jak długo przechowywać dziennik / czyszczenie
if (is_post()) {
    if (post('action') === 'retention') {
        $days = (int)post('days', '90');
        Settings::set('login_log_days', (string)(in_array($days, [30, 90, 180, 365], true) ? $days : 90));
        DB::run('DELETE FROM {login_log} WHERE `created_at` < ?', [gmdate('Y-m-d H:i:s', time() - (int)Settings::get('login_log_days') * 86400)]);
        flash('success', __('common.saved'));
    } elseif (post('action') === 'clear') {
        DB::run('DELETE FROM {login_log}');
        flash('success', __('logins.cleared'));
    }
    redirect(url('admin-logins'));
}

// Filtry
$f = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'user' => (int)($_GET['user'] ?? 0),
    'status' => in_array($_GET['status'] ?? '', ['ok', 'fail'], true) ? $_GET['status'] : '',
    'event' => in_array($_GET['event'] ?? '', ['login', 'admin'], true) ? $_GET['event'] : '',
    'from' => (string)($_GET['from'] ?? ''),
    'to' => (string)($_GET['to'] ?? ''),
];
$where = [];
$params = [];
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = '(l.`username` LIKE ? OR l.`ident` LIKE ? OR l.`ip` LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($f['user'] > 0) {
    $where[] = 'l.`user_id` = ?';
    $params[] = $f['user'];
}
if ($f['status'] !== '') {
    $where[] = 'l.`success` = ?';
    $params[] = $f['status'] === 'ok' ? 1 : 0;
}
if ($f['event'] !== '') {
    $where[] = 'l.`event` = ?';
    $params[] = $f['event'];
}
if ($from = day_utc($f['from'], false)) {
    $where[] = 'l.`created_at` >= ?';
    $params[] = $from;
}
if ($to = day_utc($f['to'], true)) {
    $where[] = 'l.`created_at` <= ?';
    $params[] = $to;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Eksport CSV (z bieżącymi filtrami)
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="logowania-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM dla Excela
    fputcsv($out, [__('logins.user'), __('logins.date'), __('logins.time'), 'IP', __('logins.result'), __('logins.event'), __('logins.browser'), 'User-Agent'], ';');
    $st = DB::run("SELECT l.* FROM {login_log} l $whereSql ORDER BY l.`id` DESC LIMIT 50000", $params);
    while ($r = $st->fetch()) {
        $dt = log_dt($r['created_at']);
        fputcsv($out, [
            $r['username'] ?? $r['ident'], $dt->format('Y-m-d'), $dt->format('H:i:s'), $r['ip'],
            __('logins.reason.' . $r['reason']), __('logins.event.' . $r['event']), ua_short($r['user_agent']), $r['user_agent'],
        ], ';');
    }
    fclose($out);
    exit;
}

// Podsumowanie
$d1 = gmdate('Y-m-d H:i:s', time() - 86400);
$d7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
$sum = DB::row("SELECT
    COALESCE(SUM(`success` = 1 AND `event` = 'login' AND `created_at` >= ?),0) AS ok24,
    COALESCE(SUM(`success` = 0 AND `created_at` >= ?),0) AS fail24,
    COUNT(DISTINCT CASE WHEN `success` = 1 AND `created_at` >= ? THEN `user_id` END) AS users7,
    COUNT(DISTINCT CASE WHEN `created_at` >= ? THEN `ip` END) AS ips7
    FROM {login_log}", [$d1, $d1, $d7, $d7]);

// Wykres 14 dni (dni w strefie serwera)
$days = [];
$today = new DateTime('today', app_tz());
for ($i = 13; $i >= 0; $i--) {
    $d = (clone $today)->modify("-$i days");
    $days[$d->format('Y-m-d')] = [0, 0];
}
$since = gmdate('Y-m-d H:i:s', (clone $today)->modify('-13 days')->getTimestamp());
foreach (DB::all('SELECT `created_at`, `success` FROM {login_log} WHERE `created_at` >= ?', [$since]) as $r) {
    $k = log_dt($r['created_at'])->format('Y-m-d');
    if (isset($days[$k])) {
        $days[$k][$r['success'] ? 0 : 1]++;
    }
}
$maxDay = max(1, max(array_map(fn($v) => $v[0] + $v[1], $days)));

// Lista
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)DB::value("SELECT COUNT(*) FROM {login_log} l $whereSql", $params);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$rows = DB::all("SELECT l.* FROM {login_log} l $whereSql ORDER BY l.`id` DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$filterUser = $f['user'] ? DB::row('SELECT `username` FROM {users} WHERE `id` = ?', [$f['user']]) : null;
$qs = array_filter(['p' => 'admin-logins'] + $f, fn($v) => $v !== '' && $v !== 0);

ob_start(); ?>
<div class="page-head">
  <div>
    <h1><?= e(__('admin.nav.logins')) ?></h1>
    <p class="muted"><?= e(__('logins.sub', ['tz' => date_default_timezone_get()])) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="?<?= e(http_build_query($qs + ['export' => 'csv'])) ?>"><?= icon('download') ?><span><?= e(__('logins.export')) ?></span></a>
  </div>
</div>

<div class="stats">
  <div class="stat card"><span class="stat-ic"><?= icon('circle-check') ?></span><div><div class="stat-val"><?= (int)$sum['ok24'] ?></div><div class="muted small"><?= e(__('logins.ok24')) ?></div></div></div>
  <div class="stat card"><span class="stat-ic stat-ic-warn"><?= icon('alert-triangle') ?></span><div><div class="stat-val"><?= (int)$sum['fail24'] ?></div><div class="muted small"><?= e(__('logins.fail24')) ?></div></div></div>
  <div class="stat card"><span class="stat-ic"><?= icon('users') ?></span><div><div class="stat-val"><?= (int)$sum['users7'] ?></div><div class="muted small"><?= e(__('logins.users7')) ?></div></div></div>
  <div class="stat card"><span class="stat-ic"><?= icon('world') ?></span><div><div class="stat-val"><?= (int)$sum['ips7'] ?></div><div class="muted small"><?= e(__('logins.ips7')) ?></div></div></div>
</div>

<section class="card">
  <h2 class="card-title"><?= icon('calendar') ?><?= e(__('logins.chart')) ?></h2>
  <div class="day-chart">
    <?php foreach ($days as $d => [$ok, $fail]): $dt = new DateTime($d); ?>
    <div class="day-col" title="<?= e($d . ': ' . __('logins.chart_tip', ['ok' => $ok, 'fail' => $fail])) ?>">
      <div class="day-bars">
        <i class="bar-fail" style="height: <?= round($fail / $maxDay * 100) ?>%"></i>
        <i class="bar-ok" style="height: <?= round($ok / $maxDay * 100) ?>%"></i>
      </div>
      <span class="day-val"><?= $ok + $fail ?: '' ?></span>
      <span class="day-lbl"><?= e($dt->format('d.m')) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="legend"><span><i style="background: var(--success)"></i><?= e(__('logins.ok')) ?></span><span><i style="background: var(--danger)"></i><?= e(__('logins.fail')) ?></span></div>
</section>

<form class="card filters" method="get">
  <input type="hidden" name="p" value="admin-logins">
  <?php if ($f['user']): ?><input type="hidden" name="user" value="<?= (int)$f['user'] ?>"><?php endif; ?>
  <label class="field"><span><?= e(__('logins.search')) ?></span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(__('logins.search_ph')) ?>"></label>
  <label class="field"><span><?= e(__('logins.result')) ?></span>
    <select name="status"><option value=""><?= e(__('logins.all')) ?></option><option value="ok" <?= $f['status'] === 'ok' ? 'selected' : '' ?>><?= e(__('logins.ok')) ?></option><option value="fail" <?= $f['status'] === 'fail' ? 'selected' : '' ?>><?= e(__('logins.fail')) ?></option></select></label>
  <label class="field"><span><?= e(__('logins.event')) ?></span>
    <select name="event"><option value=""><?= e(__('logins.all')) ?></option><option value="login" <?= $f['event'] === 'login' ? 'selected' : '' ?>><?= e(__('logins.event.login')) ?></option><option value="admin" <?= $f['event'] === 'admin' ? 'selected' : '' ?>><?= e(__('logins.event.admin')) ?></option></select></label>
  <label class="field"><span><?= e(__('logins.from')) ?></span><input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label class="field"><span><?= e(__('logins.to')) ?></span><input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit"><?= icon('search') ?><?= e(__('logins.filter')) ?></button>
    <?php if (count($qs) > 1): ?><a class="btn btn-ghost" href="<?= e(url('admin-logins')) ?>"><?= icon('x') ?><?= e(__('logins.reset')) ?></a><?php endif; ?>
  </div>
</form>

<?php if ($filterUser): ?>
<div class="alert alert-info"><?= icon('user') ?><span><?= e(__('logins.only_user', ['user' => $filterUser['username']])) ?> <a href="<?= e(url('admin-logins')) ?>"><?= e(__('logins.show_all')) ?></a></span></div>
<?php endif; ?>

<div class="card table-card">
  <table class="table">
    <thead><tr>
      <th><?= e(__('logins.user')) ?></th><th><?= e(__('logins.date')) ?></th><th><?= e(__('logins.time')) ?></th><th>IP</th>
      <th><?= e(__('logins.result')) ?></th><th><?= e(__('logins.event')) ?></th><th><?= e(__('logins.browser')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $dt = log_dt($r['created_at']); ?>
      <tr>
        <td>
          <?php if ($r['user_id']): ?>
            <a href="<?= e(url('admin-logins', ['user' => $r['user_id']])) ?>"><strong><?= e($r['username']) ?></strong></a>
            <?php if ($r['ident'] !== '' && $r['ident'] !== $r['username']): ?><div class="muted small"><?= e($r['ident']) ?></div><?php endif; ?>
          <?php else: ?>
            <span class="muted"><?= e($r['ident']) ?></span><div class="muted small"><?= e(__('logins.unknown_user')) ?></div>
          <?php endif; ?>
        </td>
        <td class="nowrap"><?= e($dt->format('Y-m-d')) ?></td>
        <td class="nowrap"><?= e($dt->format('H:i:s')) ?></td>
        <td class="nowrap"><a href="<?= e(url('admin-logins', ['q' => $r['ip']])) ?>" title="<?= e(__('logins.filter_ip')) ?>"><code><?= e($r['ip']) ?></code></a></td>
        <td><span class="badge <?= $r['success'] ? 'badge-success' : 'badge-danger' ?>"><?= icon($r['success'] ? 'circle-check' : 'circle-x') ?><?= e(__('logins.reason.' . $r['reason'])) ?></span></td>
        <td class="small"><?= e(__('logins.event.' . $r['event'])) ?></td>
        <td class="small muted" title="<?= e($r['user_agent']) ?>"><?= e(ua_short($r['user_agent'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted center"><?= e(__('logins.empty')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<nav class="pager">
  <span class="muted small"><?= e(__('logins.page', ['page' => $page, 'pages' => $pages, 'n' => $total])) ?></span>
  <?php if ($page > 1): ?><a class="btn btn-sm" href="?<?= e(http_build_query($qs + ['page' => $page - 1])) ?>"><?= icon('chevron-left') ?></a><?php endif; ?>
  <?php if ($page < $pages): ?><a class="btn btn-sm" href="?<?= e(http_build_query($qs + ['page' => $page + 1])) ?>"><?= icon('chevron-right') ?></a><?php endif; ?>
</nav>
<?php endif; ?>

<section class="card">
  <h2 class="card-title"><?= icon('clock') ?><?= e(__('logins.retention')) ?></h2>
  <p class="muted small"><?= e(__('logins.retention_text')) ?></p>
  <div class="row-actions">
    <form method="post" class="inline-form row-actions retention-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="retention">
      <select name="days" aria-label="<?= e(__('logins.retention')) ?>">
        <?php foreach ([30, 90, 180, 365] as $d): ?><option value="<?= $d ?>" <?= (int)Settings::get('login_log_days', '90') === $d ? 'selected' : '' ?>><?= e(__('logins.days', ['n' => $d])) ?></option><?php endforeach; ?>
      </select>
      <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
    </form>
    <form method="post" class="inline-form" data-confirm="<?= e(__('logins.clear_confirm')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="clear">
      <button class="btn btn-ghost" type="submit"><?= icon('trash') ?><?= e(__('logins.clear')) ?></button>
    </form>
  </div>
</section>
<?php
echo view('layout_app', ['title' => __('admin.nav.logins'), 'content' => ob_get_clean(), 'active' => 'admin-logins', 'admin' => true]);
