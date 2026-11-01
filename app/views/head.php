<?php
defined('SSLIVE') || exit;
/** @var string $title */
$u = class_exists('Auth') && DB::tableExists('users') ? Auth::user() : null;
$theme = $u['theme'] ?? 'system';
$siteName = Settings::get('site_name', 'ScreenShare Live');
$jsConfig = [
    'base' => base_url(),
    'api' => base_url() . 'api.php',
    'csrf' => csrf_token(),
    'lang' => I18n::lang(),
    'theme' => $theme,
    'loggedIn' => (bool)$u,
    'i18n' => I18n::dict(),
];
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . $siteName : $siteName) ?></title>
<link rel="icon" href="<?= e(base_url()) ?>assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach (($extraCss ?? []) as $css): ?>
<link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
<script type="application/json" id="app-config"><?= json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
</head>
