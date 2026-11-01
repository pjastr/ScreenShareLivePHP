<?php
/*
 * ScreenShare Live PHP – panel nauczyciela i administratora (domena/panel/)
 * Licencja: MIT (zob. LICENSE)
 */
define('SSLIVE', true);
define('IN_SUBDIR', true);
require dirname(__DIR__) . '/app/bootstrap.php';
app_boot();
$defaultPage = 'share';
require ROOT . '/app/router.php';
