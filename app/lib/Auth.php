<?php
defined('SSLIVE') || exit;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public const MAX_FAIL_IDENT = 5;   // błędne próby dla jednego loginu
    public const MAX_FAIL_IP = 15;     // błędne próby z jednego IP
    public const LOCK_MINUTES = 15;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $uid = (int)($_SESSION['uid'] ?? 0);
            if ($uid > 0) {
                $u = DB::row('SELECT * FROM {users} WHERE `id` = ?', [$uid]);
                if ($u && $u['status'] === 'active' && hash_equals((string)($_SESSION['uhash'] ?? ''), self::sessionHash($u))) {
                    self::$user = $u;
                } else {
                    unset($_SESSION['uid'], $_SESSION['uhash']);
                }
            }
        }
        return self::$user;
    }

    /** Zmiana hasła unieważnia inne sesje (hash sesji zależy od hasha hasła). */
    private static function sessionHash(array $u): string
    {
        return hash_hmac('sha256', $u['id'] . '|' . $u['password_hash'], app_secret());
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function refresh(): void
    {
        self::$loaded = false;
        self::$user = null;
    }

    public static function normalizeIdent(string $ident): string
    {
        return mb_strtolower(trim($ident));
    }

    public static function isLocked(string $ident): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $byIdent = (int)DB::value('SELECT COUNT(*) FROM {login_attempts} WHERE `ident` = ? AND `success` = 0 AND `created_at` > ?', [self::normalizeIdent($ident), $since]);
        $byIp = (int)DB::value('SELECT COUNT(*) FROM {login_attempts} WHERE `ip` = ? AND `success` = 0 AND `created_at` > ?', [client_ip(), $since]);
        return $byIdent >= self::MAX_FAIL_IDENT || $byIp >= self::MAX_FAIL_IP;
    }

    public static function recordAttempt(string $ident, bool $success): void
    {
        DB::insert('login_attempts', [
            'ip' => client_ip(),
            'ident' => self::normalizeIdent($ident),
            'success' => $success ? 1 : 0,
            'created_at' => DB::now(),
        ]);
        if ($success) {
            DB::run('DELETE FROM {login_attempts} WHERE `ident` = ? AND `success` = 0', [self::normalizeIdent($ident)]);
        }
        if (random_int(1, 50) === 1) {
            DB::run('DELETE FROM {login_attempts} WHERE `created_at` < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 7)]);
        }
    }

    public static function findByLogin(string $ident): ?array
    {
        return DB::row('SELECT * FROM {users} WHERE `username` = ? OR `email` = ? LIMIT 1', [trim($ident), self::normalizeIdent($ident)]);
    }

    public static function login(array $u): void
    {
        session_regenerate_id(true);
        unset($_SESSION['2fa_pending'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['uhash'] = self::sessionHash($u);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        DB::update('users', ['last_login_at' => DB::now(), 'last_login_ip' => client_ip()], '`id` = ?', [(int)$u['id']]);
        self::refresh();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::refresh();
    }

    /** Po zmianie hasła bieżąca sesja musi dostać nowy hash. */
    public static function rehashSession(): void
    {
        $u = DB::row('SELECT * FROM {users} WHERE `id` = ?', [self::id()]);
        if ($u) {
            $_SESSION['uhash'] = self::sessionHash($u);
        }
        self::refresh();
    }

    public static function validatePassword(string $p): ?string
    {
        if (mb_strlen($p) < 8) {
            return __('auth.err.password_short');
        }
        if (mb_strlen($p) > 200) {
            return __('auth.err.password_long');
        }
        return null;
    }

    public static function validateUsername(string $u): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u)) {
            return __('auth.err.username_format');
        }
        return null;
    }

    /** Tworzy konto; zwraca id lub rzuca wyjątek z komunikatem dla użytkownika. */
    public static function createUser(string $username, string $email, string $password, string $role = 'user'): int
    {
        $username = trim($username);
        $email = self::normalizeIdent($email);
        if ($err = self::validateUsername($username)) {
            throw new InvalidArgumentException($err);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new InvalidArgumentException(__('auth.err.email'));
        }
        if ($err = self::validatePassword($password)) {
            throw new InvalidArgumentException($err);
        }
        if (DB::value('SELECT COUNT(*) FROM {users} WHERE `username` = ?', [$username])) {
            throw new InvalidArgumentException(__('auth.err.username_taken'));
        }
        if (DB::value('SELECT COUNT(*) FROM {users} WHERE `email` = ?', [$email])) {
            throw new InvalidArgumentException(__('auth.err.email_taken'));
        }
        return DB::insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => in_array($role, ['admin', 'user'], true) ? $role : 'user',
            'status' => 'active',
            'created_at' => DB::now(),
        ]);
    }

    /* ---------- Kody zapasowe 2FA ---------- */

    public static function hashBackup(string $code): string
    {
        return hash_hmac('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)), app_secret());
    }

    /** Generuje 10 kodów; zwraca je jawnie (do pokazania raz), a w bazie zapisuje hashe. */
    public static function generateBackupCodes(int $userId): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $c = '';
            for ($j = 0; $j < 8; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
        }
        DB::update('users', ['backup_codes' => json_encode(array_map([self::class, 'hashBackup'], $codes))], '`id` = ?', [$userId]);
        return $codes;
    }

    public static function useBackupCode(array $u, string $code): bool
    {
        $list = json_decode((string)$u['backup_codes'], true) ?: [];
        $h = self::hashBackup($code);
        foreach ($list as $i => $stored) {
            if (hash_equals($stored, $h)) {
                unset($list[$i]);
                DB::update('users', ['backup_codes' => json_encode(array_values($list))], '`id` = ?', [(int)$u['id']]);
                return true;
            }
        }
        return false;
    }

    public static function backupCodesLeft(array $u): int
    {
        return count(json_decode((string)$u['backup_codes'], true) ?: []);
    }

    /* ---------- Panel admina: ponowna weryfikacja 2FA ---------- */

    /** Ważność dostępu do panelu admina po weryfikacji (przedłużana przy każdej akcji). */
    public const ADMIN_IDLE = 900;

    public static function adminVerified(): bool
    {
        return (int)($_SESSION['admin_ok_uid'] ?? 0) === self::id()
            && (int)($_SESSION['admin_ok_until'] ?? 0) > time();
    }

    public static function markAdminVerified(): void
    {
        $_SESSION['admin_ok_uid'] = self::id();
        $_SESSION['admin_ok_until'] = time() + self::ADMIN_IDLE;
    }

    public static function dropAdminVerified(): void
    {
        unset($_SESSION['admin_ok_uid'], $_SESSION['admin_ok_until']);
    }

    /** Czy ustawienia wymagają od tego użytkownika włączenia 2FA. */
    public static function mustSetup2fa(array $u): bool
    {
        if ((int)$u['totp_enabled'] === 1) {
            return false;
        }
        $req = Settings::get('require_2fa', 'none');
        return $req === 'all' || ($req === 'admins' && $u['role'] === 'admin');
    }
}
