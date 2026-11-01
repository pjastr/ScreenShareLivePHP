<?php
/*
 * ScreenShare Live PHP – JSON API: nadawanie (nauczyciel) i odbiór (widzowie bez logowania).
 * Licencja: MIT
 */
define('SSLIVE', true);
require __DIR__ . '/app/bootstrap.php';
app_boot();

$action = isset($_GET['a']) && is_string($_GET['a']) ? $_GET['a'] : '';
$isPost = is_post();

if (Migrator::needsUpdate()) {
    json_out(['error' => __('maint.title')], 503);
}

if ($isPost) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ($_GET['_csrf'] ?? null));
    if (!csrf_valid(is_string($token) ? $token : null)) {
        json_out(['error' => __('common.csrf_error'), 'code' => 'csrf'], 419);
    }
}

/** Dane wejściowe: JSON w treści żądania albo zwykły formularz. */
function input(): array
{
    static $in = null;
    if ($in === null) {
        $in = $_POST;
        if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 512 * 1024);
            $in = json_decode((string)$raw, true);
            if (!is_array($in)) {
                json_out(['error' => 'invalid json'], 400);
            }
        }
    }
    return $in;
}

function require_post(bool $isPost): void
{
    if (!$isPost) {
        json_out(['error' => 'method not allowed'], 405);
    }
}

function require_user(): array
{
    $u = Auth::user();
    if (!$u) {
        json_out(['error' => 'unauthorized'], 401);
    }
    if (Auth::mustSetup2fa($u)) {
        json_out(['error' => __('auth.2fa_required_notice')], 403);
    }
    return $u;
}

/** Transmisja nadawana przez bieżącego nauczyciela z tej karty przeglądarki (token nadawcy). */
function publisher_stream(array $in): array
{
    $u = require_user();
    $s = Live::find((int)($in['sid'] ?? ($_GET['sid'] ?? 0)));
    if (!$s || (int)$s['user_id'] !== (int)$u['id']) {
        json_out(['error' => __('live.err.not_found')], 404);
    }
    if (!Live::isLive($s)) {
        json_out(['status' => 'ended', 'reason' => (string)$s['end_reason']]);
    }
    $pub = (string)($in['pub'] ?? ($_GET['pub'] ?? ''));
    if ((string)$s['pub_token'] === '' || $pub === '' || !hash_equals((string)$s['pub_token'], $pub)) {
        json_out(['error' => __('live.err.taken_over'), 'code' => 'taken'], 409);
    }
    return $s;
}

/** Widz na podstawie tokenu otrzymanego przy dołączeniu. */
function viewer_ctx(array $in): array
{
    $v = Live::viewerByToken((string)($in['t'] ?? ($_GET['t'] ?? '')));
    if (!$v) {
        json_out(['status' => 'gone'], 404);
    }
    $s = Live::find((int)$v['stream_id']);
    if (!$s || !Live::isLive($s)) {
        json_out(['status' => 'ended']);
    }
    if ($v['status'] === 'kicked') {
        json_out(['status' => 'kicked']);
    }
    if ($v['status'] !== 'active') {
        // widz wrócił po przerwie (np. uśpiona karta) – przywróć, jeśli jest miejsce
        if (Live::viewerCount((int)$s['id']) >= (int)$s['max_viewers']) {
            json_out(['status' => 'full']);
        }
        DB::update('viewers', ['status' => 'active'], '`id` = ?', [(int)$v['id']]);
        Live::sendSignal((int)$s['id'], (int)$v['id'], 1, 'hello', null);
    }
    return [$v, $s];
}

function stream_state(array $s): array
{
    return [
        'status' => 'live',
        'now' => time(),
        'ends' => Live::ts($s['ends_at']),
        'cap' => Live::capTs($s),
    ];
}

Live::sweep();

// Sesja jest potrzebna do zapisu tylko przy dołączaniu widza – zwolnij blokadę sesji,
// aby równoległe żądania (odpytywanie, sygnały, klatki) nie czekały na siebie.
if ($action !== 'view.join') {
    session_write_close();
}

switch ($action) {
    /* ===================== publiczne ===================== */

    case 'live.status':
        $s = Live::active();
        json_out(['live' => (bool)$s]);

    /* ===================== nauczyciel ===================== */

    case 'pub.start':
        require_post($isPost);
        $u = require_user();
        $in = input();
        $title = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')));
        $title = mb_substr($title !== '' ? $title : __('live.default_title', ['date' => date('Y-m-d H:i')]), 0, 190);
        $s = Live::start((int)$u['id'], $title, (int)($in['minutes'] ?? Live::defaultMinutes()), (int)($in['max_viewers'] ?? Live::maxViewers()), !empty($in['audio']),
            (string)($in['codec'] ?? ''), (string)($in['quality'] ?? ''));
        if (!$s) {
            $a = Live::active();
            json_out(['error' => __('live.err.busy', ['user' => $a['username'] ?? '?', 'time' => $a ? date('H:i', Live::ts($a['ends_at'])) : '']), 'code' => 'busy'], 409);
        }
        // Powiadomienie e-mail wysyłane po odpowiedzi do przeglądarki (nie opóźnia startu)
        if (Notifier::shouldNotify()) {
            $started = $s;
            register_shutdown_function(function () use ($started) {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                Notifier::streamStarted($started);
            });
        }
        json_out(['stream' => Live::publicInfo($s), 'code' => $s['code'], 'pub' => $s['pub_token'], 'max_viewers' => (int)$s['max_viewers']]);

    case 'pub.bye':
        // sendBeacon przy zamknięciu karty nauczyciela
        require_post($isPost);
        $in = input();
        $u = Auth::user();
        $s = Live::find((int)($in['sid'] ?? 0));
        if ($u && $s && Live::isLive($s) && (int)$s['user_id'] === (int)$u['id'] && (string)$s['pub_token'] !== '' && hash_equals((string)$s['pub_token'], (string)($in['pub'] ?? ''))) {
            Live::bye($s);
        }
        json_out(['ok' => true]);

    case 'pub.resume':
        require_post($isPost);
        $u = require_user();
        $in = input();
        $s = Live::active();
        if (!$s || (int)$s['user_id'] !== (int)$u['id'] || (int)$s['id'] !== (int)($in['sid'] ?? 0)) {
            json_out(['error' => __('live.err.not_found'), 'code' => 'ended'], 404);
        }
        $pub = Live::attach($s);
        // Widzowie czekający na obraz dostaną nowe połączenie
        foreach (Live::viewers((int)$s['id']) as $v) {
            DB::update('viewers', ['state' => 'waiting'], '`id` = ?', [(int)$v['id']]);
        }
        DB::run('DELETE FROM {signals} WHERE `stream_id` = ?', [(int)$s['id']]);
        json_out(['stream' => Live::publicInfo($s), 'code' => $s['code'], 'pub' => $pub, 'max_viewers' => (int)$s['max_viewers']]);

    case 'pub.poll':
        require_post($isPost);
        $in = input();
        $s = publisher_stream($in);
        DB::update('streams', ['pub_seen_at' => DB::now()], '`id` = ?', [(int)$s['id']]);
        // widzowie z aktywnym połączeniem WebRTC są obecni, nawet jeśli ich karta jest w tle
        $alive = array_slice(array_values(array_filter(array_map('intval', (array)($in['connected'] ?? [])))), 0, Live::MAX_VIEWERS);
        if ($alive) {
            DB::run("UPDATE {viewers} SET `last_seen_at` = ?, `state` = 'connected' WHERE `stream_id` = ? AND `status` = 'active' AND `id` IN (" . implode(',', $alive) . ')', [DB::now(), (int)$s['id']]);
        }
        $viewers = array_map(fn($v) => [
            'id' => (int)$v['id'],
            'name' => (string)$v['name'],
            'mode' => (string)$v['mode'],
            'state' => (string)$v['state'],
            'stats' => $v['stats'] ? json_decode((string)$v['stats'], true) : null,
            'joined' => Live::ts($v['joined_at']),
        ], Live::viewers((int)$s['id']));
        json_out(stream_state($s) + [
            'code' => (string)$s['code'],
            'max_viewers' => (int)$s['max_viewers'],
            'viewers' => $viewers,
            'signals' => Live::takeSignals((int)$s['id'], 1),
            'need_frames' => Settings::bool('live_fallback') && Live::needFrames((int)$s['id']),
        ]);

    case 'pub.signal':
        require_post($isPost);
        $in = input();
        $s = publisher_stream($in);
        $items = is_array($in['items'] ?? null) ? array_slice($in['items'], 0, 200) : [];
        $valid = array_flip(array_map(fn($v) => (int)$v['id'], Live::viewers((int)$s['id'])));
        foreach ($items as $it) {
            $vid = (int)($it['viewer'] ?? 0);
            $type = (string)($it['type'] ?? '');
            if (isset($valid[$vid]) && in_array($type, ['offer', 'ice', 'bye'], true)) {
                Live::sendSignal((int)$s['id'], $vid, 0, $type, $it['data'] ?? null);
            }
        }
        json_out(['ok' => true]);

    case 'pub.frame':
        require_post($isPost);
        $s = publisher_stream([]);
        $mime = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/webp'], true)) {
            json_out(['error' => 'bad type'], 415);
        }
        $data = (string)file_get_contents('php://input', false, null, 0, Live::MAX_FRAME_BYTES + 1);
        if ($data === '' || strlen($data) > Live::MAX_FRAME_BYTES) {
            json_out(['error' => 'frame too large'], 413);
        }
        $sig = substr($data, 0, 4);
        if (($mime === 'image/jpeg' && !str_starts_with($data, "\xFF\xD8\xFF")) || ($mime === 'image/webp' && $sig !== 'RIFF')) {
            json_out(['error' => 'bad image'], 415);
        }
        json_out(['seq' => Live::storeFrame((int)$s['id'], $mime, $data)]);

    case 'pub.ice':
        // nauczyciel zmienia serwery STUN w trakcie transmisji
        require_post($isPost);
        $in = input();
        $s = publisher_stream($in);
        $urls = Live::cleanStun($in['stun'] ?? []);
        $rev = Live::setStreamStun($s, $urls);
        $s = Live::find((int)$s['id']);
        json_out(['ice' => Live::iceServers($s), 'stun' => Live::stunList($s), 'ice_rev' => $rev,
            'frames_viewers' => (int)DB::value("SELECT COUNT(*) FROM {viewers} WHERE `stream_id` = ? AND `status` = 'active' AND `mode` = 'frames'", [(int)$s['id']])]);

    case 'pub.extend':
        require_post($isPost);
        $in = input();
        $s = publisher_stream($in);
        $ends = Live::extend($s, max(5, min(60, (int)($in['minutes'] ?? 15))));
        json_out(['ends' => $ends, 'cap' => Live::capTs($s)]);

    case 'pub.code':
        require_post($isPost);
        $in = input();
        $s = publisher_stream($in);
        json_out(['code' => Live::changeCode($s)]);

    case 'pub.kick':
        require_post($isPost);
        $in = input();
        $s = publisher_stream($in);
        Live::kick((int)$s['id'], (int)($in['viewer'] ?? 0));
        json_out(['ok' => true]);

    case 'pub.end':
        require_post($isPost);
        $u = require_user();
        $in = input();
        $s = Live::find((int)($in['sid'] ?? 0));
        if (!$s || ((int)$s['user_id'] !== (int)$u['id'] && $u['role'] !== 'admin')) {
            json_out(['error' => __('live.err.not_found')], 404);
        }
        Live::end((int)$s['id'], (int)$s['user_id'] === (int)$u['id'] ? 'manual' : 'admin');
        json_out(['ok' => true, 'status' => 'ended']);

    /* ===================== widzowie (bez logowania) ===================== */

    case 'view.join':
        require_post($isPost);
        $in = input();
        $s = Live::active();
        $acc = $_SESSION['live_access'] ?? null;
        if (!$s || !is_array($acc) || (int)$acc['sid'] !== (int)$s['id']) {
            json_out(['status' => $s ? 'code' : 'ended'], $s ? 403 : 200);
        }
        // widz usunięty przez nauczyciela nie może dołączyć ponownie z tej samej przeglądarki
        foreach ((array)($_SESSION['live_tokens'] ?? []) as $old) {
            $ov = Live::viewerByToken((string)$old);
            if ($ov && (int)$ov['stream_id'] === (int)$s['id'] && $ov['status'] === 'kicked') {
                unset($_SESSION['live_access']);
                json_out(['status' => 'kicked']);
            }
        }
        $v = Live::join($s, (string)($acc['name'] ?? ''));
        if (!$v) {
            json_out(['status' => 'full', 'max' => (int)$s['max_viewers']]);
        }
        $_SESSION['live_tokens'] = array_slice(array_merge((array)($_SESSION['live_tokens'] ?? []), [$v['token']]), -10);
        // nauczyciel dostaje sygnał "hello" i wysyła ofertę połączenia
        Live::sendSignal((int)$s['id'], (int)$v['id'], 1, 'hello', null);
        json_out(stream_state($s) + ['token' => $v['token'], 'viewer' => (int)$v['id'], 'stream' => Live::publicInfo($s, true),
            'ice' => Live::iceServers($s), 'ice_rev' => (int)($s['ice_rev'] ?? 0)]);

    case 'view.poll':
        require_post($isPost);
        $in = input();
        [$v, $s] = viewer_ctx($in);
        $mode = isset($in['mode']) ? (string)$in['mode'] : null;
        if ($mode === 'frames' && !Settings::bool('live_fallback')) {
            $mode = 'webrtc';
        }
        Live::touchViewer($v, isset($in['state']) ? (string)$in['state'] : null, $mode, is_array($in['st'] ?? null) ? $in['st'] : null);
        json_out(stream_state($s) + [
            'signals' => Live::takeSignals((int)$s['id'], 0, (int)$v['id']),
            'pub_online' => Live::ts($s['pub_seen_at']) > time() - 10,
            'ice_rev' => (int)($s['ice_rev'] ?? 0),
            'ice' => (int)($in['ir'] ?? -1) !== (int)($s['ice_rev'] ?? 0) ? Live::iceServers($s) : null,
            'viewers' => Live::viewerCount((int)$s['id']),
        ]);

    case 'view.signal':
        require_post($isPost);
        $in = input();
        [$v, $s] = viewer_ctx($in);
        $items = is_array($in['items'] ?? null) ? array_slice($in['items'], 0, 100) : [];
        foreach ($items as $it) {
            $type = (string)($it['type'] ?? '');
            if (in_array($type, ['answer', 'ice', 'hello', 'quality'], true)) {
                Live::sendSignal((int)$s['id'], (int)$v['id'], 1, $type, $it['data'] ?? null);
            }
        }
        json_out(['ok' => true]);

    case 'view.frame':
        // GET: ostatnia klatka obrazu (tryb zapasowy). 204 = bez zmian od klatki ?s=
        [$v, $s] = viewer_ctx($_GET);
        if (!Settings::bool('live_fallback')) {
            json_out(['error' => 'disabled'], 403);
        }
        Live::touchViewer($v);
        $since = (int)($_GET['s'] ?? 0);
        $seq = Live::frameSeq((int)$s['id']);
        if ($seq === 0 || $seq === $since) {
            http_response_code(204);
            header('Cache-Control: no-store');
            exit;
        }
        $f = Live::frame((int)$s['id']);
        header('Content-Type: ' . $f['mime']);
        header('Cache-Control: no-store');
        header('X-Frame-Seq: ' . (int)$f['seq']);
        // wiek klatki na serwerze (ms) – do statystyk opóźnienia w trybie zapasowym
        header('X-Frame-Age: ' . max(0, Live::nowMs() - (int)$f['ts_ms']));
        echo $f['data'];
        exit;

    case 'view.leave':
        require_post($isPost);
        $v = Live::viewerByToken((string)(input()['t'] ?? ''));
        if ($v) {
            Live::leave($v);
            DB::run('DELETE FROM {signals} WHERE `viewer_id` = ?', [(int)$v['id']]);
        }
        json_out(['ok' => true]);

    /* ===================== konto ===================== */

    case 'user.theme':
        require_post($isPost);
        $u = require_user();
        $in = input();
        $theme = in_array($in['theme'] ?? '', ['system', 'light', 'dark'], true) ? $in['theme'] : 'system';
        DB::update('users', ['theme' => $theme], '`id` = ?', [(int)$u['id']]);
        json_out(['ok' => true, 'theme' => $theme]);

    default:
        json_out(['error' => 'unknown action'], 404);
}
