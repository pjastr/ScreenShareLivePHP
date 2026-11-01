<?php
defined('SSLIVE') || exit;
$icons = ['success' => 'circle-check', 'error' => 'alert-triangle', 'warning' => 'alert-triangle', 'info' => 'info-circle'];
foreach (take_flashes() as $f): ?>
<div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= icon($icons[$f['type']] ?? 'info-circle') ?><span><?= e($f['msg']) ?></span></div>
<?php endforeach;
