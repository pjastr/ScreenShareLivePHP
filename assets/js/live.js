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
        .then(function () { send('answer', { cid: d.cid, sdp: p.localDescription.sdp }, true); armConnectTimeout(); })
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
  function frameLoop() {
    if (mode !== 'frames' || finished) return;
    fetch(api + '?a=view.frame&t=' + token + '&s=' + frameSeq, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 200 && (r.headers.get('Content-Type') || '').indexOf('image/') === 0) {
          frameSeq = parseInt(r.headers.get('X-Frame-Seq') || '0', 10);
          return r.blob().then(function (b) {
            var u = URL.createObjectURL(b);
            frameImg.onload = function () { if (frameUrl) URL.revokeObjectURL(frameUrl); frameUrl = u; };
            frameImg.src = u;
            frameImg.hidden = false; video.hidden = true;
            if (!gotFrame) { gotFrame = true; if (state === 'frames') hideOverlay(); }
          });
        }
        return null;
      })
      .catch(function () {})
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
    App.api('view.poll', { t: token, state: wire, mode: mode }).then(function (r) {
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
