<?php
defined('SSLIVE') || exit;
/*
 * Router stron logowania i panelu (domena/login/ oraz domena/panel/).
 * $defaultPage ustawia plik wejściowy katalogu.
 */
$defaultPage = $defaultPage ?? 'share';
$page = isset($_GET['p']) && is_string($_GET['p']) ? $_GET['p'] : $defaultPage;
if (!preg_match('/^[a-z0-9-]{1,40}$/', $page)) {
    $page = $defaultPage;
}

$routes = [
    // strona => [plik, dostęp] ; dostęp: guest|public|user|admin
    'login'           => ['login.php', 'guest'],
    'register'        => ['register.php', 'guest'],
    '2fa'             => ['twofa.php', 'guest'],
    'logout'          => ['logout.php', 'public'],
    'dbupdate'        => ['dbupdate.php', 'admin'],
    'share'           => ['share.php', 'user'],
    'profile'         => ['profile.php', 'user'],
    'admin'           => ['admin/overview.php', 'admin'],
    'admin-users'     => ['admin/users.php', 'admin'],
    'admin-settings'  => ['admin/settings.php', 'admin'],
    'admin-languages' => ['admin/languages.php', 'admin'],
    'admin-translate' => ['admin/translate.php', 'admin'],
    'admin-update'    => ['admin/update.php', 'admin'],
    'admin-backup'    => ['admin/backup.php', 'admin'],
    'admin-verify'    => ['admin/verify.php', 'admin'],
    'admin-streams'   => ['admin/streams.php', 'admin'],
    'admin-logins'    => ['admin/logins.php', 'admin'],
];

if (!isset($routes[$page])) {
    http_response_code(404);
    $page = Auth::user() ? 'share' : 'login';
}

// Ochrona CSRF dla wszystkich formularzy
if (is_post() && !csrf_valid($_POST['_csrf'] ?? null)) {
    flash('error', __('common.csrf_error'));
    redirect(url($page, array_intersect_key($_GET, ['id' => 1, 'code' => 1])));
}

// Wymagana aktualizacja bazy danych (jak w WordPress)
if (Migrator::needsUpdate() && !in_array($page, ['login', '2fa', 'logout', 'dbupdate', 'admin-backup', 'admin-verify'], true)) {
    if (Auth::user() && Auth::isAdmin()) {
        redirect(url('dbupdate'));
    }
    if (!Auth::user() || !Auth::isAdmin()) {
        http_response_code(503);
        echo view('layout_auth', [
            'title' => __('maint.title'),
            'content' => '<h1 class="auth-title">' . e(__('maint.title')) . '</h1><p class="muted">' . e(__('maint.text')) . '</p>'
                . (Auth::user() ? '' : '<a class="btn btn-primary btn-block" href="' . e(url('login')) . '">' . e(__('auth.login')) . '</a>'),
        ]);
        exit;
    }
}

[$file, $access] = $routes[$page];
$user = Auth::user();

if ($access === 'guest' && $user) {
    redirect(url('share'));
}
if (in_array($access, ['user', 'admin'], true) && !$user) {
    redirect(url('login'));
}
if ($access === 'admin' && !Auth::isAdmin()) {
    http_response_code(403);
    redirect(url('share'));
}
// Wymuszone 2FA – dopóki użytkownik go nie włączy, może korzystać tylko z profilu
if ($user && Auth::mustSetup2fa($user) && !in_array($page, ['profile', 'logout', 'dbupdate'], true)) {
    flash('warning', __('auth.2fa_required_notice'));
    redirect(url('profile') . '#security');
}

// Panel admina: wejście wymaga nowego kodu 2FA. Weryfikacja jest ważna przez Auth::ADMIN_IDLE
// od ostatniej czynności w panelu – także po przejściu do zwykłej części aplikacji i powrocie.
if ($user && Auth::isAdmin()) {
    if ($access === 'admin' && $page !== 'admin-verify') {
        // aktualizacja bazy nie może się zablokować, gdy admin nie ma jeszcze 2FA
        $soft = in_array($page, ['dbupdate', 'admin-backup'], true);
        if ((int)$user['totp_enabled'] !== 1) {
            if (!$soft) {
                flash('warning', __('admin.verify.need_2fa'));
                redirect(url('profile') . '#security');
            }
        } elseif (!Auth::adminVerified()) {
            $_SESSION['admin_return'] = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? (string)($_SERVER['REQUEST_URI'] ?? '') : url($page);
            redirect(url('admin-verify'));
        } else {
            Auth::markAdminVerified();
        }
    }
}

require ROOT . '/app/pages/' . $file;
