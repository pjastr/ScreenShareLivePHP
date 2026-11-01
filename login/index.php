<?php
/*
 * ScreenShare Live PHP – logowanie (domena/login/)
 * Licencja: MIT (zob. LICENSE)
 */
define('SSLIVE', true);
define('IN_SUBDIR', true);
require dirname(__DIR__) . '/app/bootstrap.php';
app_boot();
$defaultPage = 'login';
require ROOT . '/app/router.php';
