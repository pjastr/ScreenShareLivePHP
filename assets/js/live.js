/* ScreenShare Live PHP – odtwarzacz widza: WebRTC + tryb zapasowy z klatkami (MIT) */
(function () {
  'use strict';
  var App = window.App, t = App.t;
  var cfg = JSON.parse(document.getElementById('live-config').textContent);
  var api = App.cfg.api;

  var $ = function (id) { return document.getElementById(id); };
  var video = $('video'), frameImg = $('frame'), overlay = $('overlay');
  var pill = $('pill'), pillText = $('pill-text');

  var token = null, pc = null, cid = null;
  var state = 'connecting';      // connecting | waiting | connected | frames | ended
  var mode = 'webrtc';           // webrtc | frames
  var finished = false, pubOnline = true;
  var ends = cfg.stream.ends, skew = 0;
  var outbox = [], flushTimer = null, pollTimer = null, frameTimer = null, connectTimer = null, discTimer = null;
  var failures = 0, frameSeq = 0, frameUrl = null, gotFrame = false, queue = Promise.resolve();

  /* ---------- Interfejs ---------- */
  function showOverlay(iconName, title, text, actions) {
    $('ov-icon').innerHTML = App.icon(iconName, iconName === 'loader-2' ? 'spin' : '');
    $('ov-title').textContent = title;
    $('ov-text').textContent = text || '';
    var box = $('ov-actions');
    box.innerHTML = '';
    (actions || []).forEach(function (a) {
      var el = document.createElement(a.href ? 'a' : 'button');
      el.className = 'btn ' + (a.primary ? 'btn-primary' : '');
      if (a.href) el.href = a.href; else { el.type = 'button'; el.addEventListener('click', a.onClick); }
      el.innerHTML = App.icon(a.icon || 'refresh') + App.esc(a.label);
      box.appendChild(el);
    });
    overlay.hidden = false;
  }
  function hideOverlay() { overlay.hidden = true; }

  function setState(st) {
    state = st;
    pill.className = 'live-pill ' + st;
    pillText.textContent = t(st === 'connected' ? 'live.state.live' : 'live.state.' + st);
    if (st === 'connected' || (st === 'frames' && gotFrame)) hideOverlay();
    else if (st === 'waiting') showOverlay('broadcast', t('live.state.waiting'), t('live.view.waiting_teacher'));
    else if (st === 'connecting') showOverlay('loader-2', t('live.state.connecting'), '');
    else if (st === 'frames') showOverlay('loader-2', t('live.state.frames'), t('live.view.fallback_note'));
  }

  function fmtLeft(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
  }
  setInterval(function () {
    if (finished) return;
    $('remain').textContent = fmtLeft(ends - (Date.now() / 1000 + skew));
  }, 1000);

  /* ---------- Sygnalizacja ---------- */
  function send(type, data, now) {
    outbox.push({ type: type, data: data === undefined ? null : data });
    if (now) { clearTimeout(flushTimer); flush(); }
    else if (!flushTimer) flushTimer = setTimeout(flush, 150);
  }
  function flush() {
    flushTimer = null;
    if (!outbox.length || !token || finished) return;
    var items = outbox; outbox = [];
    App.api('view.signal', { t: token, items: items }).catch(function () {
      outbox = items.concat(outbox);
      flushTimer = setTimeout(flush, 1500);
    });
  }

  function closePc() {
    if (pc) { try { pc.close(); } catch (e) {} }
    pc = null; cid = null;
  }

  function newPc(id) {
    closePc();
    cid = id;
    var p = pc = new RTCPeerConnection({ iceServers: cfg.ice || [] });
    p.ontrack = function (e) {
      var ms = e.streams && e.streams[0] ? e.streams[0] : new MediaStream([e.track]);
      if (video.srcObject !== ms) video.srcObject = ms;
      if (e.track.kind === 'audio') { $('btn-sound').hidden = false; if (video.muted) $('tap-sound').hidden = false; }
      var pr = video.play(); if (pr && pr.catch) pr.catch(function () {});
    };
    p.onicecandidate = function (e) {
      if (e.candidate && p === pc) send('ice', { cid: id, c: e.candidate.toJSON ? e.candidate.toJSON() : e.candidate });
    };
    p.onconnectionstatechange = function () { if (p === pc) onConn(p.connectionState || p.iceConnectionState); };
    if (!('connectionState' in p)) p.oniceconnectionstatechange = function () { if (p === pc) onConn(p.iceConnectionState); };
    return p;
  }

  function onConn(cs) {
    if (cs === 'connected' || cs === 'completed') {
      clearTimeout(connectTimer); clearTimeout(discTimer);
      failures = 0;
      if (mode === 'frames') stopFrames();
      setState('connected');
    } else if (cs === 'failed') {
      failures++;
      if (failures >= 2) fallback();
      else { send('hello', null, true); armConnectTimeout(); }
    } else if (cs === 'disconnected') {
      setState('connecting');
      clearTimeout(discTimer);
      discTimer = setTimeout(function () {
        if (pc && pc.connectionState !== 'connected') { send('hello', null, true); armConnectTimeout(); }
      }, 6000);
    }
  }

  function handle(sig) {
    var d = sig.data || {};
    if (sig.type === 'offer') {
      if (!window.RTCPeerConnection) return null;
      var p = newPc(d.cid);
      if (state !== 'frames') setState('connecting');
      return p.setRemoteDescription({ type: 'offer', sdp: d.sdp })
        .then(function () { return p.createAnswer(); })
        .then(function (a) { return p.setLocalDescription(a); })
        .then(function () {
          send('answer', { cid: d.cid, sdp: p.localDescription.sdp }, true);
          if (vq !== 'high') send('quality', { level: vq }, true);
          armConnectTimeout();
        })
        .catch(function (e) { console.warn('offer', e); });
    }
    if (sig.type === 'ice' && pc && d.cid === cid && d.c) {
      return pc.addIceCandidate(d.c).catch(function () {});
    }
    if (sig.type === 'bye' && d.cid === cid) {
      closePc();
      if (mode !== 'frames') setState('waiting');
    }
    return null;
  }

  function armConnectTimeout() {
    clearTimeout(connectTimer);
    connectTimer = setTimeout(function () {
      if (state !== 'connected' && pubOnline && !finished) fallback();
    }, 15000);
  }

  /* ---------- Tryb zapasowy: klatki przez serwer ---------- */
  function fallback() {
    if (finished || mode === 'frames') return;
    if (!cfg.fallback) {
      showOverlay('wifi-off', t('live.view.rtc_failed'), t('live.view.rtc_failed_text'), [{ label: t('live.view.retry_rtc'), primary: true, onClick: retryRtc }]);
      return;
    }
    mode = 'frames';
    $('mode-note').hidden = false;
    setState('frames');
    poll(true);
    frameLoop();
  }
  function stopFrames() {
    mode = 'webrtc';
    clearTimeout(frameTimer); frameTimer = null;
    $('mode-note').hidden = true;
    frameImg.hidden = true; video.hidden = false;
  }
  /* Liczniki trybu zapasowego (statystyki). */
  var fb = { got: 0, same: 0, errors: 0, bytes: 0, lastBytes: 0, lastFetch: 0, lastAge: -1, hist: [] };
  function frameLoop() {
    if (mode !== 'frames' || finished) return;
    var t0 = performance.now();
    fetch(api + '?a=view.frame&t=' + token + '&s=' + frameSeq, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 200 && (r.headers.get('Content-Type') || '').indexOf('image/') === 0) {
          frameSeq = parseInt(r.headers.get('X-Frame-Seq') || '0', 10);
          var age = parseInt(r.headers.get('X-Frame-Age') || '-1', 10);
          return r.blob().then(function (b) {
            var dt = performance.now() - t0;
            fb.got++; fb.bytes += b.size; fb.lastBytes = b.size; fb.lastFetch = dt;
            fb.lastAge = age >= 0 ? age + dt : -1;
            fb.hist.push({ t: Date.now(), b: b.size });
            while (fb.hist.length && fb.hist[0].t < Date.now() - 10000) fb.hist.shift();
            var u = URL.createObjectURL(b);
            frameImg.onload = function () { if (frameUrl) URL.revokeObjectURL(frameUrl); frameUrl = u; };
            frameImg.src = u;
            frameImg.hidden = false; video.hidden = true;
            if (!gotFrame) { gotFrame = true; if (state === 'frames') hideOverlay(); }
          });
        }
        if (r.status === 204) fb.same++;
        else if (!r.ok) fb.errors++;
        fb.lastFetch = performance.now() - t0;
        return null;
      })
      .catch(function () { fb.errors++; })
      .then(function () { frameTimer = setTimeout(frameLoop, 1000); });
  }
  function retryRtc() {
    failures = 0;
    send('hello', null, true);
    armConnectTimeout();
    if (mode !== 'frames') setState('connecting');
  }
  $('btn-retry').addEventListener('click', function () { retryRtc(); App.toast(t('live.view.retrying')); });

  /* ---------- Odpytywanie serwera ---------- */
  function schedule() {
    clearTimeout(pollTimer);
    if (finished) return;
    pollTimer = setTimeout(poll, state === 'connected' ? 4000 : (mode === 'frames' ? 3000 : 1000));
  }

  function poll(once) {
    if (finished || !token) return;
    var wire = mode === 'frames' ? 'connected' : (state === 'connected' ? 'connected' : (state === 'waiting' ? 'waiting' : 'connecting'));
    App.api('view.poll', { t: token, state: wire, mode: mode, st: summary }).then(function (r) {
      if (r.status !== 'live') return status(r.status, r);
      skew = r.now - Date.now() / 1000;
      ends = r.ends;
      $('vcount').textContent = r.viewers;
      pubOnline = !!r.pub_online;
      if (!pubOnline && state !== 'connected' && mode !== 'frames') setState('waiting');
      (r.signals || []).forEach(function (s) { queue = queue.then(function () { return handle(s); }).catch(function (e) { console.warn(e); }); });
    }).catch(function (e) {
      if (e.status === 404) return status('gone');
      if (e.status === 419) return location.reload();
    }).then(function () { if (!once) schedule(); });
  }

  function status(st, r) {
    if (st === 'ended') return finish('ended');
    if (st === 'kicked') return finish('kicked');
    if (st === 'code') return location.reload();
    if (st === 'gone') { token = null; return join(); }
    if (st === 'full') {
      setState('waiting');
      showOverlay('users', t('live.view.full'), t('live.view.full_text', { n: (r && r.max) || '' }));
      clearTimeout(pollTimer);
      pollTimer = setTimeout(join, 10000);
    }
  }

  function join() {
    if (finished) return;
    App.api('view.join', {}).then(function (r) {
      if (r.status !== 'live') return status(r.status, r);
      token = r.token;
      skew = r.now - Date.now() / 1000;
      ends = r.ends;
      setState('connecting');
      armConnectTimeout();
      poll();
    }).catch(function (e) {
      if (e.status === 403 || e.status === 419) return location.reload();
      pollTimer = setTimeout(join, 3000);
    });
  }

  function finish(kind) {
    finished = true;
    clearTimeout(pollTimer); clearTimeout(frameTimer); clearTimeout(connectTimer);
    closePc();
    video.srcObject = null; frameImg.hidden = true;
    $('mode-note').hidden = true; $('tap-sound').hidden = true;
    pill.className = 'live-pill ended';
    pillText.textContent = t('live.state.ended');
    $('remain').textContent = '—';
    showOverlay(kind === 'kicked' ? 'user-x' : 'broadcast-off',
      t(kind === 'kicked' ? 'live.view.kicked' : 'live.view.ended'),
      t(kind === 'kicked' ? 'live.view.kicked_text' : 'live.view.ended_text'),
      [{ label: t('live.view.back_home'), primary: true, icon: 'home', href: App.cfg.base }]);
  }

  /* ---------- Jakość wybrana przez widza ---------- */
  var vq = 'high';
  try { vq = localStorage.getItem('sslive-vq') || 'high'; } catch (e) {}
  if (['high', 'medium', 'low'].indexOf(vq) < 0) vq = 'high';
  function markVq() {
    document.querySelectorAll('[data-vq]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-vq') === vq); });
    $('q-label').textContent = vq === 'high' ? 'HD' : (vq === 'medium' ? 'SD' : 'LD');
  }
  markVq();
  document.querySelectorAll('[data-vq]').forEach(function (b) {
    b.addEventListener('click', function () {
      vq = b.getAttribute('data-vq');
      try { localStorage.setItem('sslive-vq', vq); } catch (e) {}
      markVq();
      if (token) send('quality', { level: vq }, true);
      App.toast(t('live.view.quality_set', { q: t('live.vq.' + vq) }), 'success');
    });
  });

  /* ---------- Rozmiar: dopasuj do okna / 100% (piksel w piksel) ---------- */
  var stage = $('stage'), actual = false;
  function sizeActual() {
    [video, frameImg].forEach(function (el) {
      var w = el.videoWidth || el.naturalWidth;
      el.style.width = actual && w ? (w / (window.devicePixelRatio || 1)) + 'px' : '';
    });
  }
  $('btn-fit').addEventListener('click', function () {
    actual = !actual;
    stage.classList.toggle('actual', actual);
    $('fit-label').textContent = t(actual ? 'live.view.fit' : 'live.view.actual_size');
    sizeActual();
  });
  video.addEventListener('resize', sizeActual);
  frameImg.addEventListener('load', function () { if (actual) sizeActual(); });
  window.addEventListener('resize', sizeActual);

  /* ---------- Statystyki odbioru ---------- */
  // Zbierane co sekundę (także przy ukrytym panelu) – skrót trafia do nauczyciela przy odpytywaniu.
  var statsBox = $('stats'), statsOn = false, prevIn = null, summary = null, rows = [];
  function fmtRate(bps) {
    if (!(bps >= 0)) return '—';
    return bps >= 1e6 ? (bps / 1e6).toLocaleString(App.cfg.lang, { maximumFractionDigits: 1 }) + ' Mb/s' : Math.round(bps / 1000) + ' kb/s';
  }
  function fmtMs(ms) { return ms >= 0 ? (ms >= 10000 ? (ms / 1000).toFixed(0) + ' s' : Math.round(ms) + ' ms') : '—'; }
  function dispSize(el) {
    var dpr = window.devicePixelRatio || 1;
    return el.clientWidth ? Math.round(el.clientWidth * dpr) + '×' + Math.round(el.clientHeight * dpr) : '—';
  }
  function render() {
    if (!statsOn) return;
    statsBox.innerHTML = rows.map(function (r) {
      return '<div' + (r[2] ? ' class="' + r[2] + '"' : '') + '><span>' + App.esc(t(r[0])) + '</span><b>' + App.esc(r[1]) + '</b></div>';
    }).join('');
  }
  function collectFrames() {
    var now = Date.now(), win = fb.hist.filter(function (h) { return h.t > now - 5000; });
    var span = win.length > 1 ? (win[win.length - 1].t - win[0].t) / 1000 : 0;
    var fps = span > 0 ? (win.length - 1) / span : (win.length ? 1 / 5 * win.length : 0);
    var bps = win.reduce(function (a, h) { return a + h.b; }, 0) * 8 / 5;
    var res = frameImg.naturalWidth ? frameImg.naturalWidth + '×' + frameImg.naturalHeight : '';
    summary = { fps: Math.round(fps * 10) / 10, kbps: Math.round(bps / 1000), age: Math.round(fb.lastAge), res: res.replace('×', 'x'), kb: Math.round(fb.lastBytes / 1024) };
    rows = [
      ['live.st.mode', t('live.state.frames'), 'warn'],
      ['live.st.conn', t('live.st.conn_http')],
      ['live.st.res', res || '—'],
      ['live.st.display', dispSize(frameImg)],
      ['live.st.fps', fps ? fps.toLocaleString(App.cfg.lang, { maximumFractionDigits: 1 }) : '0'],
      ['live.st.bitrate', fmtRate(bps)],
      ['live.st.frame_size', fb.lastBytes ? Math.round(fb.lastBytes / 1024) + ' KB' : '—'],
      ['live.st.frame_age', fmtMs(fb.lastAge)],
      ['live.st.fetch', fmtMs(fb.lastFetch)],
      ['live.st.frames_got', String(fb.got)],
      ['live.st.frames_same', String(fb.same)],
      ['live.st.data', (fb.bytes / 1048576).toLocaleString(App.cfg.lang, { maximumFractionDigits: 1 }) + ' MB'],
      ['live.st.errors', String(fb.errors), fb.errors ? 'warn' : ''],
      ['live.view.quality', t('live.vq.' + vq)]
    ];
    render();
  }
  function collectRtc() {
    if (!pc) { summary = null; rows = [['live.st.mode', t('live.state.' + state)]]; render(); return; }
    pc.getStats().then(function (rep) {
      var inb = null, pair = null, codecs = {}, cands = {};
      rep.forEach(function (r) {
        if (r.type === 'inbound-rtp' && (r.kind || r.mediaType) === 'video') inb = r;
        else if (r.type === 'candidate-pair' && (r.nominated || r.selected) && r.state === 'succeeded') pair = r;
        else if (r.type === 'codec') codecs[r.id] = r;
        else if (r.type === 'local-candidate' || r.type === 'remote-candidate') cands[r.id] = r;
      });
      if (!inb) { summary = null; rows = [['live.st.mode', t('live.state.' + state)]]; render(); return; }
      var bps = prevIn && inb.timestamp > prevIn.timestamp ? (inb.bytesReceived - prevIn.bytesReceived) * 8000 / (inb.timestamp - prevIn.timestamp) : -1;
      var lossPct = 0;
      if (prevIn) {
        var lost = (inb.packetsLost || 0) - prevIn.lost, got = (inb.packetsReceived || 0) - prevIn.got;
        lossPct = got + lost > 0 ? Math.max(0, lost) / (got + lost) * 100 : 0;
      }
      prevIn = { bytesReceived: inb.bytesReceived, timestamp: inb.timestamp, lost: inb.packetsLost || 0, got: inb.packetsReceived || 0 };
      var lc = pair && cands[pair.localCandidateId], rc = pair && cands[pair.remoteCandidateId];
      var conn = !lc ? '—' : (lc.candidateType === 'relay' || (rc && rc.candidateType === 'relay') ? 'TURN' : (lc.candidateType === 'host' && (!rc || rc.candidateType === 'host') ? 'LAN' : 'P2P'));
      var w = inb.frameWidth || video.videoWidth || 0, h = inb.frameHeight || video.videoHeight || 0;
      var rtt = pair && pair.currentRoundTripTime !== undefined ? pair.currentRoundTripTime * 1000 : -1;
      summary = { fps: Math.round(inb.framesPerSecond || 0), kbps: bps >= 0 ? Math.round(bps / 1000) : 0, rtt: Math.round(rtt), res: w + 'x' + h, loss: Math.round(lossPct * 10) / 10 };
      rows = [
        ['live.st.mode', 'WebRTC'],
        ['live.st.conn', conn],
        ['live.st.res', w + '×' + h],
        ['live.st.display', dispSize(video)],
        ['live.st.fps', inb.framesPerSecond !== undefined ? String(Math.round(inb.framesPerSecond)) : '—'],
        ['live.st.bitrate', fmtRate(bps)],
        ['live.st.codec', inb.codecId && codecs[inb.codecId] ? codecs[inb.codecId].mimeType.replace('video/', '') : '—'],
        ['live.st.loss', lossPct.toFixed(1) + ' %', lossPct > 2 ? 'warn' : ''],
        ['live.st.jitter', inb.jitter !== undefined ? Math.round(inb.jitter * 1000) + ' ms' : '—'],
        ['live.st.rtt', fmtMs(rtt)],
        ['live.st.dropped', String(inb.framesDropped || 0)],
        ['live.view.quality', t('live.vq.' + vq)]
      ];
      render();
    }).catch(function () {});
  }
  setInterval(function () {
    if (finished) return;
    if (mode === 'frames') collectFrames(); else collectRtc();
  }, 1000);
  $('btn-stats').addEventListener('click', function () {
    statsOn = !statsOn;
    statsBox.hidden = !statsOn;
    this.classList.toggle('active', statsOn);
    try { localStorage.setItem('sslive-vstats', statsOn ? '1' : '0'); } catch (e) {}
    render();
  });
  try { if (localStorage.getItem('sslive-vstats') === '1') $('btn-stats').click(); } catch (e) {}

  /* ---------- Sterowanie ---------- */
  var player = $('player');
  $('btn-fs').addEventListener('click', function () {
    if (document.fullscreenElement) { document.exitFullscreen(); return; }
    if (player.requestFullscreen) player.requestFullscreen().catch(function () {});
    else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen();
  });
  document.addEventListener('fullscreenchange', function () {
    $('btn-fs').innerHTML = App.icon(document.fullscreenElement ? 'minimize' : 'maximize');
  });
  $('stage').addEventListener('dblclick', function () { $('btn-fs').click(); });
  function setMuted(m) {
    video.muted = m;
    $('btn-sound').innerHTML = App.icon(m ? 'volume-off' : 'volume');
    $('tap-sound').hidden = true;
  }
  $('btn-sound').addEventListener('click', function () { setMuted(!video.muted); });
  $('tap-sound').addEventListener('click', function () { setMuted(false); video.play().catch(function () {}); });

  window.addEventListener('pagehide', function () {
    if (!token || finished) return;
    var fd = new FormData();
    fd.append('t', token); fd.append('_csrf', App.cfg.csrf);
    try { navigator.sendBeacon(api + '?a=view.leave', fd); } catch (e) {}
  });

  if (!window.RTCPeerConnection) {
    if (cfg.fallback) { mode = 'webrtc'; }
    else { showOverlay('alert-triangle', t('live.view.no_webrtc'), ''); return; }
  }
  setState('connecting');
  join();
  if (!window.RTCPeerConnection) setTimeout(fallback, 500);
})();
