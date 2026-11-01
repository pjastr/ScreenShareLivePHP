<?php
defined('SSLIVE') || exit;

/**
 * Transmisje na żywo: limity, kody dostępu, widzowie i sygnalizacja WebRTC.
 *
 * Obraz płynie bezpośrednio z przeglądarki nauczyciela do przeglądarek widzów (WebRTC, P2P).
 * Serwer PHP przekazuje tylko krótkie wiadomości (offer/answer/ICE) przez zwykłe żądania HTTP,
 * dlatego aplikacja działa na hostingu współdzielonym bez WebSocketów.
 * Jeśli WebRTC jest zablokowane w sieci widza, widz przechodzi na tryb zapasowy:
 * nauczyciel wysyła co ~1 s klatkę JPEG na serwer, a widz ją pobiera.
 */
final class Live
{
    /** Twarde limity aplikacji – ustawienia w panelu admina mogą je tylko zmniejszyć. */
    public const MAX_MINUTES = 180;
    public const MIN_MINUTES = 5;
    public const MAX_VIEWERS = 25;

    /** Po ilu sekundach bez sygnału od nauczyciela transmisja jest kończona. */
    public const PUB_TIMEOUT = 90;
    /** Po ilu sekundach bez sygnału od widza uznajemy, że wyszedł. */
    public const VIEWER_TIMEOUT = 25;

    public const MAX_SIGNAL_BYTES = 65536;
    public const MAX_FRAME_BYTES = 1800000;

    private static bool $swept = false;

    public static function ts(?string $utc): int
    {
        return $utc ? (int)strtotime($utc . ' UTC') : 0;
    }

    public static function at(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function maxViewers(): int
    {
        return max(1, min(self::MAX_VIEWERS, (int)Settings::get('live_max_viewers', (string)self::MAX_VIEWERS)));
    }

    public static function defaultMinutes(): int
    {
        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int)Settings::get('live_default_minutes', '90')));
    }

    /**
     * Porządki: kończy transmisje po czasie lub bez nauczyciela, oznacza nieaktywnych widzów.
     * Wywoływane przy każdym żądaniu dotyczącym transmisji (nie potrzeba crona).
     */
    public static function sweep(): void
    {
        if (self::$swept) {
            return;
        }
        self::$swept = true;
        $now = time();
        $ended = DB::run("UPDATE {streams} SET `status` = 'ended', `active_slot` = NULL, `ended_at` = ?, `end_reason` = 'time'
            WHERE `active_slot` = 1 AND `ends_at` <= ?", [self::at($now), self::at($now)])->rowCount();
        $ended += DB::run("UPDATE {streams} SET `status` = 'ended', `active_slot` = NULL, `ended_at` = ?, `end_reason` = 'lost'
            WHERE `active_slot` = 1 AND `pub_seen_at` < ?", [self::at($now), self::at($now - self::PUB_TIMEOUT)])->rowCount();
        DB::run("UPDATE {viewers} SET `status` = 'left' WHERE `status` = 'active' AND `last_seen_at` < ?", [self::at($now - self::VIEWER_TIMEOUT)]);
        if ($ended > 0 || random_int(1, 200) === 1) {
            self::cleanup();
        }
    }

    /** Usuwa dane pomocnicze zakończonych transmisji (sygnały, klatki, stare wpisy widzów). */
    public static function cleanup(): void
    {
        DB::run('DELETE s FROM {signals} s LEFT JOIN {streams} t ON t.`id` = s.`stream_id` AND t.`active_slot` = 1 WHERE t.`id` IS NULL');
        DB::run('DELETE f FROM {frames} f LEFT JOIN {streams} t ON t.`id` = f.`stream_id` AND t.`active_slot` = 1 WHERE t.`id` IS NULL');
        DB::run('DELETE FROM {signals} WHERE `created_at` < ?', [self::at(time() - 600)]);
        DB::run('DELETE FROM {viewers} WHERE `last_seen_at` < ?', [self::at(time() - 86400 * 30)]);
    }

    /** Trwająca transmisja (z nazwą nauczyciela) albo null. */
    public static function active(): ?array
    {
        self::sweep();
        return DB::row('SELECT s.*, u.`username` FROM {streams} s LEFT JOIN {users} u ON u.`id` = s.`user_id` WHERE s.`active_slot` = 1 LIMIT 1');
    }

    public static function find(int $id): ?array
    {
        return DB::row('SELECT s.*, u.`username` FROM {streams} s LEFT JOIN {users} u ON u.`id` = s.`user_id` WHERE s.`id` = ?', [$id]);
    }

    public static function isLive(?array $s): bool
    {
        return $s && (int)($s['active_slot'] ?? 0) === 1 && $s['status'] === 'live';
    }

    public static function newCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Rozpoczyna transmisję. Zwraca wiersz transmisji albo null, gdy trwa już inna transmisja.
     */
    public static function start(int $userId, string $title, int $minutes, int $maxViewers, bool $audio): ?array
    {
        self::sweep();
        $minutes = max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));
        $maxViewers = max(1, min(self::maxViewers(), $maxViewers));
        $now = time();
        try {
            $id = DB::insert('streams', [
                'user_id' => $userId,
                'title' => $title,
                'code' => self::newCode(),
                'status' => 'live',
                'active_slot' => 1,
                'max_viewers' => $maxViewers,
                'audio' => $audio ? 1 : 0,
                'pub_token' => self::token(),
                'pub_seen_at' => self::at($now),
                'started_at' => self::at($now),
                'ends_at' => self::at($now + $minutes * 60),
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                return null; // indeks UNIQUE: inna transmisja już trwa
            }
            throw $e;
        }
        return self::find($id);
    }

    /** Ponowne podłączenie nauczyciela (np. po odświeżeniu strony): nowy token nadawcy. */
    public static function attach(array $s): string
    {
        $t = self::token();
        DB::update('streams', ['pub_token' => $t, 'pub_seen_at' => DB::now()], '`id` = ?', [(int)$s['id']]);
        return $t;
    }

    public static function end(int $id, string $reason = 'manual'): void
    {
        DB::run("UPDATE {streams} SET `status` = 'ended', `active_slot` = NULL, `ended_at` = ?, `end_reason` = ? WHERE `id` = ? AND `active_slot` = 1", [DB::now(), $reason, $id]);
        self::cleanup();
    }

    /** Przedłuża transmisję, ale nigdy ponad MAX_MINUTES od jej rozpoczęcia. Zwraca nowy koniec (timestamp). */
    public static function extend(array $s, int $minutes): int
    {
        $cap = self::ts($s['started_at']) + self::MAX_MINUTES * 60;
        $new = min($cap, self::ts($s['ends_at']) + max(1, $minutes) * 60);
        DB::update('streams', ['ends_at' => self::at($new)], '`id` = ?', [(int)$s['id']]);
        return $new;
    }

    public static function changeCode(array $s): string
    {
        $c = self::newCode();
        DB::update('streams', ['code' => $c], '`id` = ?', [(int)$s['id']]);
        return $c;
    }

    /** Czas, do którego można przedłużyć transmisję. */
    public static function capTs(array $s): int
    {
        return self::ts($s['started_at']) + self::MAX_MINUTES * 60;
    }

    /* ---------- Widzowie ---------- */

    public static function viewers(int $streamId): array
    {
        return DB::all("SELECT `id`,`name`,`mode`,`state`,`joined_at`,`last_seen_at` FROM {viewers} WHERE `stream_id` = ? AND `status` = 'active' ORDER BY `id`", [$streamId]);
    }

    public static function viewerCount(int $streamId): int
    {
        return (int)DB::value("SELECT COUNT(*) FROM {viewers} WHERE `stream_id` = ? AND `status` = 'active'", [$streamId]);
    }

    /** Dołącza widza. Zwraca wiersz widza albo null, gdy osiągnięto limit widzów. */
    public static function join(array $s, string $name): ?array
    {
        self::sweep();
        $sid = (int)$s['id'];
        // Blokada wiersza transmisji serializuje równoczesne dołączenia (limit widzów).
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::run('SELECT `id` FROM {streams} WHERE `id` = ? FOR UPDATE', [$sid]);
            $count = self::viewerCount($sid);
            if ($count >= (int)$s['max_viewers']) {
                $pdo->rollBack();
                return null;
            }
            $token = self::token();
            $now = DB::now();
            $id = DB::insert('viewers', [
                'stream_id' => $sid,
                'token' => $token,
                'name' => $name,
                'ip' => client_ip(),
                'mode' => 'webrtc',
                'state' => 'waiting',
                'status' => 'active',
                'joined_at' => $now,
                'last_seen_at' => $now,
            ]);
            DB::run('UPDATE {streams} SET `total_viewers` = `total_viewers` + 1, `peak_viewers` = GREATEST(`peak_viewers`, ?) WHERE `id` = ?', [$count + 1, $sid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return DB::row('SELECT * FROM {viewers} WHERE `id` = ?', [$id]);
    }

    public static function viewerByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return DB::row('SELECT * FROM {viewers} WHERE `token` = ?', [$token]);
    }

    public static function touchViewer(array $v, ?string $state = null, ?string $mode = null): void
    {
        $data = ['last_seen_at' => DB::now()];
        if ($state !== null && in_array($state, ['waiting', 'connecting', 'connected', 'failed'], true)) {
            $data['state'] = $state;
        }
        if ($mode !== null && in_array($mode, ['webrtc', 'frames'], true)) {
            $data['mode'] = $mode;
        }
        DB::update('viewers', $data, '`id` = ?', [(int)$v['id']]);
    }

    public static function kick(int $streamId, int $viewerId): void
    {
        DB::run("UPDATE {viewers} SET `status` = 'kicked' WHERE `id` = ? AND `stream_id` = ?", [$viewerId, $streamId]);
        DB::run('DELETE FROM {signals} WHERE `stream_id` = ? AND `viewer_id` = ?', [$streamId, $viewerId]);
    }

    public static function leave(array $v): void
    {
        DB::run("UPDATE {viewers} SET `status` = 'left' WHERE `id` = ? AND `status` = 'active'", [(int)$v['id']]);
    }

    /** Czy choć jeden widz potrzebuje klatek z serwera (tryb zapasowy). */
    public static function needFrames(int $streamId): bool
    {
        return (bool)DB::value("SELECT COUNT(*) FROM {viewers} WHERE `stream_id` = ? AND `status` = 'active' AND `mode` = 'frames'", [$streamId]);
    }

    /* ---------- Sygnalizacja ---------- */

    public static function sendSignal(int $streamId, int $viewerId, int $dir, string $type, $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_SIGNAL_BYTES) {
            throw new InvalidArgumentException('signal too large');
        }
        DB::insert('signals', [
            'stream_id' => $streamId,
            'viewer_id' => $viewerId,
            'dir' => $dir,
            'type' => $type,
            'payload' => $json,
            'created_at' => DB::now(),
        ]);
    }

    /** Odbiera (i usuwa) oczekujące wiadomości. Dla nauczyciela $viewerId = null. */
    public static function takeSignals(int $streamId, int $dir, ?int $viewerId = null): array
    {
        $sql = 'SELECT `id`,`viewer_id`,`type`,`payload` FROM {signals} WHERE `stream_id` = ? AND `dir` = ?';
        $params = [$streamId, $dir];
        if ($viewerId !== null) {
            $sql .= ' AND `viewer_id` = ?';
            $params[] = $viewerId;
        }
        $rows = DB::all($sql . ' ORDER BY `id` LIMIT 500', $params);
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        DB::run('DELETE FROM {signals} WHERE `id` IN (' . implode(',', $ids) . ')');
        return array_map(fn($r) => [
            'viewer' => (int)$r['viewer_id'],
            'type' => $r['type'],
            'data' => json_decode((string)$r['payload'], true),
        ], $rows);
    }

    /* ---------- Klatki (tryb zapasowy) ---------- */

    public static function storeFrame(int $streamId, string $mime, string $data): int
    {
        DB::run('INSERT INTO {frames} (`stream_id`,`seq`,`mime`,`data`,`updated_at`) VALUES (?,1,?,?,?)
            ON DUPLICATE KEY UPDATE `seq` = `seq` + 1, `mime` = VALUES(`mime`), `data` = VALUES(`data`), `updated_at` = VALUES(`updated_at`)',
            [$streamId, $mime, $data, DB::now()]);
        return (int)DB::value('SELECT `seq` FROM {frames} WHERE `stream_id` = ?', [$streamId]);
    }

    public static function frameSeq(int $streamId): int
    {
        return (int)(DB::value('SELECT `seq` FROM {frames} WHERE `stream_id` = ?', [$streamId]) ?? 0);
    }

    public static function frame(int $streamId): ?array
    {
        return DB::row('SELECT `seq`,`mime`,`data` FROM {frames} WHERE `stream_id` = ?', [$streamId]);
    }

    /* ---------- Konfiguracja WebRTC ---------- */

    /** Serwery STUN/TURN dla RTCPeerConnection (ustawiane w panelu admina). */
    public static function iceServers(): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', (string)Settings::get('live_ice_servers', '')) ?: [] as $u) {
            if (preg_match('#^stuns?:[A-Za-z0-9.\-]+(:\d+)?$#', $u)) {
                $out[] = ['urls' => $u];
            }
        }
        $turn = trim((string)Settings::get('live_turn_url', ''));
        if ($turn !== '') {
            $urls = array_values(array_filter(preg_split('/[\s,]+/', $turn) ?: [], fn($u) => preg_match('#^turns?:[^\s]+$#', $u)));
            if ($urls) {
                $out[] = ['urls' => $urls, 'username' => (string)Settings::get('live_turn_user', ''), 'credential' => (string)Settings::get('live_turn_pass', '')];
            }
        }
        return $out;
    }

    /**
     * Presety jakości (na jednego widza): maks. szerokość obrazu [px], liczba klatek,
     * maks. przepływność [kbps], contentHint. Obraz jest przechwytywany w pełnej rozdzielczości
     * ekranu (do 4K) i zmniejszany przy kodowaniu, dlatego preset można zmienić w trakcie transmisji.
     */
    public static function qualities(): array
    {
        return [
            'text' => ['width' => 2560, 'fps' => 15, 'bitrate' => 6000, 'hint' => 'detail'],
            'balanced' => ['width' => 1920, 'fps' => 15, 'bitrate' => 4000, 'hint' => 'detail'],
            'motion' => ['width' => 1920, 'fps' => 30, 'bitrate' => 6000, 'hint' => 'motion'],
            'saver' => ['width' => 1280, 'fps' => 10, 'bitrate' => 1200, 'hint' => 'detail'],
        ];
    }

    /** Preferowany kodek wideo: auto (domyślny przeglądarki) | vp9 | h264 | vp8 | av1. */
    public static function codecs(): array
    {
        return ['vp9', 'h264', 'av1', 'vp8', 'auto'];
    }

    public static function codec(): string
    {
        $c = (string)Settings::get('live_codec', 'vp9');
        return in_array($c, self::codecs(), true) ? $c : 'vp9';
    }

    /** Próba odgadnięcia kodu jest liczona jak nieudane logowanie (blokada po wielu próbach). */
    public static function codeLocked(): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - 600);
        return (int)DB::value("SELECT COUNT(*) FROM {login_attempts} WHERE `ip` = ? AND `ident` = 'live:code' AND `success` = 0 AND `created_at` > ?", [client_ip(), $since]) >= 10;
    }

    public static function recordCodeAttempt(bool $ok): void
    {
        DB::insert('login_attempts', ['ip' => client_ip(), 'ident' => 'live:code', 'success' => $ok ? 1 : 0, 'created_at' => DB::now()]);
        if ($ok) {
            DB::run("DELETE FROM {login_attempts} WHERE `ip` = ? AND `ident` = 'live:code' AND `success` = 0", [client_ip()]);
        }
    }

    /** Publiczny adres strony dla widzów – strona główna aplikacji (np. https://domena.pl/). */
    public static function viewerUrl(?string $code = null): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $u = (is_https() ? 'https://' : 'http://') . $host . base_url();
        return $code !== null ? $u . '?code=' . $code : $u;
    }

    /** Dane transmisji dla JS. */
    public static function publicInfo(array $s): array
    {
        return [
            'id' => (int)$s['id'],
            'title' => (string)$s['title'],
            'teacher' => (string)($s['username'] ?? ''),
            'started' => self::ts($s['started_at']),
            'ends' => self::ts($s['ends_at']),
            'cap' => self::capTs($s),
            'audio' => (int)$s['audio'] === 1,
            'live' => self::isLive($s),
        ];
    }
}
