/* ScreenShare Live PHP – nadawanie ekranu przez nauczyciela (WebRTC, jeden nadawca → do 25 widzów) (MIT) */
(function () {
  'use strict';
  var App = window.App, t = App.t;
  var cfg = JSON.parse(document.getElementById('share-config').textContent);
  var api = App.cfg.api;
  var $ = function (id) { return document.getElementById(id); };

  /* ---------- Transmisja innego nauczyciela: czekaj na jej koniec ---------- */
  if (cfg.mode === 'other') {
    setInterval(function () {
      App.api('live.status').then(function (r) { if (!r.live) location.reload(); }).catch(function () {});
    }, 10000);
    return;
  }

  var canShare = !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia);
  if (!window.isSecureContext) $('warn-secure').hidden = false;
  else if (!canShare || !window.RTCPeerConnection) $('warn-support').hidden = false;
  if (!window.isSecureContext || !canShare || !window.RTCPeerConnection) $('btn-start').disabled = true;

  var sid = 0, pub = null, code = '', ends = 0, cap = 0, maxV = cfg.maxViewers, skew = 0;
  var quality = cfg.quality, wantAudio = false;
  var local = null;           // MediaStream z ekranu
  var peers = {};             // id widza -> { pc, cid, created, state, pending: [] }
  var viewers = [];
  var outbox = [], flushTimer = null, pollTimer = null, frameTimer = null, frameBusy = false;
  var needFrames = false, running = false, warned5 = false, cidSeq = 0;
  var preview = $('preview');

  function Q() { return cfg.qualities[quality] || cfg.qualities.balanced; }

  /* ---------- Ekran ---------- */
  function getMedia() {
    var q = Q();
    var v = { frameRate: { ideal: q.fps, max: q.fps }, width: { max: 1920 }, height: { max: 1080 } };
    var ask = function (audio) { return navigator.mediaDevices.getDisplayMedia({ video: v, audio: audio }); };
    return (wantAudio ? ask(true).catch(function (e) { if (e && e.name === 'TypeError') return ask(false); throw e; }) : ask(false))
      .then(function (ms) {
        ms.getVideoTracks().forEach(function (tr) {
          try { tr.contentHint = q.hint; } catch (e) {}
          tr.addEventListener('ended', function () { if (local === ms) pauseShare(); });
        });
        return ms;
      });
  }

  function setLocal(ms) {
    var old = local;
    local = ms;
    preview.srcObject = ms;
    preview.play().catch(function () {});
    $('paused').hidden = !!ms;
    $('btn-switch').hidden = !ms;
    $('btn-stop-share').hidden = !ms;
    var vt = ms && ms.getVideoTracks()[0];
    var st = vt && vt.getSettings ? vt.getSettings() : {};
    $('share-info').textContent = ms ? (vt.label || '') + (st.width ? ' · ' + st.width + '×' + st.height : '') + (ms.getAudioTracks().length ? ' · ' + t('live.with_audio') : '') : '';
    if (old && old !== ms) old.getTracks().forEach(function (tr) { tr.stop(); });
    updatePill();
  }

  function mediaError(e) {
    if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) App.toast(t('live.err.cancelled'), 'info');
    else App.toast(t('live.err.media') + ' ' + (e && e.message ? e.message : ''), 'error');
  }

  /** Nowe źródło obrazu: zamiana ścieżki w istniejących połączeniach albo nowe połączenia. */
  function useMedia(ms) {
    var hadAudio = local && local.getAudioTracks().length > 0;
    var replace = local && !!ms.getAudioTracks().length === hadAudio;
    var newVideo = ms.getVideoTracks()[0], newAudio = ms.getAudioTracks()[0];
    if (replace) {
      Object.keys(peers).forEach(function (id) {
        peers[id].pc.getSenders().forEach(function (s) {
          if (s.track && s.track.kind === 'video') s.replaceTrack(newVideo).catch(function () {});
          if (s.track && s.track.kind === 'audio' && newAudio) s.replaceTrack(newAudio).catch(function () {});
        });
      });
      setLocal(ms);
    } else {
      setLocal(ms);
      Object.keys(peers).forEach(function (id) { createPeer(+id); });
    }
  }

  function pauseShare() {
    Object.keys(peers).forEach(function (id) { closePeer(+id, true); });
    setLocal(null);
    $('paused-title').textContent = t('live.paused');
    $('paused-text').textContent = t('live.paused_text');
  }

  /* ---------- Sygnalizacja ---------- */
  function send(viewer, type, data, now) {
    outbox.push({ viewer: viewer, type: type, data: data });
    if (now) { clearTimeout(flushTimer); flush(); }
    else if (!flushTimer) flushTimer = setTimeout(flush, 150);
  }
  function flush() {
    flushTimer = null;
    if (!outbox.length || !pub) return;
    var items = outbox.splice(0, 200);
    App.api('pub.signal', { sid: sid, pub: pub, items: items }).catch(function (e) {
      if (e.status === 409) return takenOver();
      outbox = items.concat(outbox);
    }).then(function () { if (outbox.length && !flushTimer) flushTimer = setTimeout(flush, 300); });
  }

  function closePeer(id, bye) {
    var p = peers[id];
    if (!p) return;
    if (bye) send(id, 'bye', { cid: p.cid });
    try { p.pc.close(); } catch (e) {}
    delete peers[id];
  }

  function applyEncoding(pc) {
    var q = Q();
    pc.getSenders().forEach(function (s) {
      if (!s.track || s.track.kind !== 'video' || !s.getParameters) return;
      var p = s.getParameters();
      if (!p.encodings || !p.encodings.length) p.encodings = [{}];
      p.encodings[0].maxBitrate = q.bitrate * 1000;
      p.encodings[0].maxFramerate = q.fps;
      if (q.hint === 'detail') p.degradationPreference = 'maintain-resolution';
      s.setParameters(p).catch(function () {});
    });
  }

  function createPeer(id) {
    if (!local) return;
    closePeer(id, false);
    var cid = (++cidSeq) + '-' + Math.random().toString(36).slice(2, 8);
    var pc = new RTCPeerConnection({ iceServers: cfg.ice || [] });
    var peer = peers[id] = { pc: pc, cid: cid, created: Date.now(), state: 'connecting', pending: [] };
    local.getTracks().forEach(function (tr) { pc.addTrack(tr, local); });
    pc.onicecandidate = function (e) {
      if (e.candidate && peers[id] === peer) send(id, 'ice', { cid: cid, c: e.candidate.toJSON ? e.candidate.toJSON() : e.candidate });
    };
    var onState = function () {
      if (peers[id] !== peer) return;
      var cs = pc.connectionState || pc.iceConnectionState;
      peer.state = (cs === 'connected' || cs === 'completed') ? 'connected' : (cs === 'failed' ? 'failed' : (cs === 'disconnected' ? 'connecting' : peer.state));
      if (peer.state === 'connected') applyEncoding(pc);
      renderViewers();
    };
    pc.onconnectionstatechange = onState;
    pc.oniceconnectionstatechange = onState;
    pc.createOffer()
      .then(function (o) { return pc.setLocalDescription(o); })
      .then(function () { applyEncoding(pc); send(id, 'offer', { cid: cid, sdp: pc.localDescription.sdp }, true); })
      .catch(function (e) { console.warn('offer', e); });
  }

  function handle(sig) {
    var id = sig.viewer, d = sig.data || {}, p = peers[id];
    if (sig.type === 'hello') {
      // świeżo utworzone połączenie nie jest odtwarzane drugi raz
      if (p && Date.now() - p.created < 4000 && p.state !== 'failed') return;
      createPeer(id);
    } else if (sig.type === 'answer' && p && p.cid === d.cid) {
      p.pc.setRemoteDescription({ type: 'answer', sdp: d.sdp }).then(function () {
        p.pending.forEach(function (c) { p.pc.addIceCandidate(c).catch(function () {}); });
        p.pending = [];
      }).catch(function (e) { console.warn('answer', e); });
    } else if (sig.type === 'ice' && p && p.cid === d.cid && d.c) {
      if (p.pc.remoteDescription) p.pc.addIceCandidate(d.c).catch(function () {});
      else p.pending.push(d.c);
    }
  }

  /* ---------- Odpytywanie ---------- */
  function poll() {
    if (!running) return;
    var connected = Object.keys(peers).filter(function (id) { return peers[id].state === 'connected'; }).map(Number);
    App.api('pub.poll', { sid: sid, pub: pub, connected: connected }).then(function (r) {
      if (r.status !== 'live') return ended(r.reason);
      skew = r.now - Date.now() / 1000;
      ends = r.ends; cap = r.cap; maxV = r.max_viewers;
      if (r.code !== code) setCode(r.code);
      needFrames = !!r.need_frames;
      (r.signals || []).forEach(handle);
      viewers = r.viewers || [];
      var ids = {};
      viewers.forEach(function (v) {
        ids[v.id] = true;
        if (local && !peers[v.id]) createPeer(v.id);
      });
      Object.keys(peers).forEach(function (id) { if (!ids[id]) closePeer(+id, false); });
      renderViewers();
      if (needFrames && local && !frameTimer) frameLoop();
    }).catch(function (e) {
      if (e.status === 409) return takenOver();
      if (e.status === 401) { running = false; App.toast(t('live.err.session'), 'error', [{ label: t('auth.login'), onClick: function () { location.href = App.cfg.base + 'login/'; } }], 0); }
    }).then(function () {
      if (running) pollTimer = setTimeout(poll, 1000);
    });
  }

  /* ---------- Tryb zapasowy: klatka JPEG co ~1 s dla widzów bez WebRTC ---------- */
  var canvas = document.createElement('canvas');
  function frameLoop() {
    frameTimer = null;
    if (!running || !needFrames || !local) return;
    frameTimer = setTimeout(frameLoop, 1000);
    if (frameBusy || !preview.videoWidth) return;
    var w = preview.videoWidth, h = preview.videoHeight, sc = Math.min(1, 1600 / w);
    canvas.width = Math.round(w * sc); canvas.height = Math.round(h * sc);
    canvas.getContext('2d').drawImage(preview, 0, 0, canvas.width, canvas.height);
    frameBusy = true;
    canvas.toBlob(function (blob) {
      if (!blob) { frameBusy = false; return; }
      fetch(api + '?a=pub.frame&sid=' + sid + '&pub=' + encodeURIComponent(pub), {
        method: 'POST', credentials: 'same-origin', body: blob,
        headers: { 'Content-Type': 'image/jpeg', 'X-CSRF-Token': App.cfg.csrf }
      }).catch(function () {}).then(function () { frameBusy = false; });
    }, 'image/jpeg', 0.72);
  }

  /* ---------- Interfejs ---------- */
  function fmtCode(c) { return c ? c.slice(0, 3) + ' ' + c.slice(3) : '— — —'; }
  function linkFor(c) { return cfg.viewerUrl + '?code=' + c; }
  function setCode(c) {
    code = c;
    $('code').textContent = fmtCode(c);
    $('present-code').textContent = fmtCode(c);
    var qrBox = $('present-qr');
    if (typeof window.qrcode === 'function') {
      var qr = window.qrcode(0, 'M');
      qr.addData(linkFor(c)); qr.make();
      qrBox.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    }
  }

  function fmtLeft(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
  }
  setInterval(function () {
    if (!running) return;
    var left = ends - (Date.now() / 1000 + skew);
    $('remain').textContent = fmtLeft(left);
    $('btn-extend').disabled = cap - ends < 60;
    $('btn-extend').title = cap - ends < 60 ? t('live.extend_max', { n: cfg.maxMinutes }) : '';
    if (left < 300 && left > 0 && !warned5) { warned5 = true; App.toast(t('live.ends_soon'), 'info', null, 8000); }
    if (left >= 300) warned5 = false;
  }, 1000);

  function updatePill() {
    var pill = $('pub-pill');
    pill.className = 'live-pill ' + (local ? 'connected' : 'waiting');
    $('pub-pill-text').textContent = local ? t('live.state.live') : t('live.state.paused');
  }

  var stateNames = { connecting: 'live.state.connecting', connected: 'live.state.connected', failed: 'live.state.failed', frames: 'live.state.frames', waiting: 'live.state.waiting' };
  function renderViewers() {
    var ul = $('viewers');
    $('vcount').textContent = viewers.length + ' / ' + maxV;
    if (!viewers.length) { ul.innerHTML = '<li class="muted small empty-v">' + App.esc(t('live.no_viewers')) + '</li>'; return; }
    ul.innerHTML = viewers.map(function (v) {
      var p = peers[v.id];
      var st = v.mode === 'frames' && (!p || p.state !== 'connected') ? 'frames' : (p ? p.state : (local ? 'connecting' : 'waiting'));
      var name = v.name || t('live.viewer_anon', { n: v.id });
      return '<li><span class="avatar avatar-sm">' + App.esc(name.charAt(0).toUpperCase()) + '</span>' +
        '<span class="v-name" title="' + App.esc(name) + '">' + App.esc(name) + '</span>' +
        '<span class="v-state ' + st + '">' + App.esc(t(stateNames[st] || st)) + '</span>' +
        '<button type="button" class="btn-icon btn-sm" data-kick="' + v.id + '" data-name="' + App.esc(name) + '" title="' + App.esc(t('live.kick')) + '">' + App.icon('user-x') + '</button></li>';
    }).join('');
  }

  $('viewers').addEventListener('click', function (e) {
    var b = e.target.closest('[data-kick]');
    if (!b) return;
    var id = +b.getAttribute('data-kick');
    App.confirm(t('live.kick_confirm', { name: b.getAttribute('data-name') })).then(function (ok) {
      if (!ok) return;
      closePeer(id, true);
      App.api('pub.kick', { sid: sid, pub: pub, viewer: id }).then(function () { App.toast(t('live.kicked_ok'), 'success'); });
    });
  });

  function enterLive(r) {
    sid = r.stream.id; pub = r.pub; ends = r.stream.ends; cap = r.stream.cap; maxV = r.max_viewers;
    try { sessionStorage.setItem('sslive-pub', String(sid)); } catch (e) {}
    $('live-title').textContent = r.stream.title;
    setCode(r.code);
    $('setup').hidden = true;
    $('live').hidden = false;
    running = true;
    clearTimeout(pollTimer);
    poll();
    renderViewers();
  }

  function stopAll() {
    running = false;
    clearTimeout(pollTimer); clearTimeout(frameTimer); frameTimer = null;
    Object.keys(peers).forEach(function (id) { closePeer(+id, false); });
    if (local) local.getTracks().forEach(function (tr) { tr.stop(); });
    local = null; preview.srcObject = null;
  }

  function ended(reason) {
    stopAll();
    App.toast(t(reason === 'time' ? 'live.ended_time' : (reason === 'admin' ? 'live.ended_admin' : 'live.ended_ok')), 'info', null, 0);
    setTimeout(function () { location.reload(); }, 2500);
  }

  function takenOver() {
    if (!running) return;
    stopAll();
    $('paused').hidden = false;
    $('paused-title').textContent = t('live.taken_title');
    $('paused-text').textContent = t('live.err.taken_over');
    $('btn-resume').textContent = t('live.take_here');
    $('btn-resume').onclick = function () { location.reload(); };
    updatePill();
  }

  /* ---------- Akcje ---------- */
  $('setup-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target, btn = $('btn-start');
    quality = (f.querySelector('[name=quality]:checked') || {}).value || quality;
    wantAudio = f.audio.checked;
    btn.disabled = true;
    getMedia().then(function (ms) {
      return App.api('pub.start', {
        title: f.title.value, minutes: +f.minutes.value, max_viewers: +f.max_viewers.value, audio: ms.getAudioTracks().length > 0, quality: quality
      }).then(function (r) { enterLive(r); setLocal(ms); }, function (err) {
        ms.getTracks().forEach(function (tr) { tr.stop(); });
        throw err;
      });
    }).catch(function (err) {
      if (err && err.status) App.toast(err.message, 'error', null, 8000); else mediaError(err);
    }).then(function () { btn.disabled = false; });
  });

  /** Wznowienie po odświeżeniu strony / przejęcie z innej karty. */
  function resumeStream() {
    return App.api('pub.resume', { sid: cfg.stream.id }).then(function (r) { enterLive(r); return r; });
  }

  $('btn-resume').addEventListener('click', function () {
    if (!running) return;
    getMedia().then(useMedia).catch(mediaError);
  });
  $('btn-switch').addEventListener('click', function () { getMedia().then(useMedia).catch(mediaError); });
  $('btn-stop-share').addEventListener('click', pauseShare);

  $('btn-end').addEventListener('click', function () {
    App.confirm(t('live.end_confirm'), { ok: t('live.end') }).then(function (ok) {
      if (!ok) return;
      App.api('pub.end', { sid: sid }).then(function () { ended('manual'); }).catch(function (e) { App.toast(e.message, 'error'); });
    });
  });
  $('btn-extend').addEventListener('click', function () {
    App.api('pub.extend', { sid: sid, pub: pub, minutes: 15 }).then(function (r) {
      ends = r.ends; cap = r.cap;
      App.toast(t('live.extended', { time: new Date((r.ends - skew) * 1000).toLocaleTimeString(App.cfg.lang || [], { hour: '2-digit', minute: '2-digit' }) }), 'success');
    }).catch(function (e) { App.toast(e.message, 'error'); });
  });
  $('btn-new-code').addEventListener('click', function () {
    App.confirm(t('live.new_code_confirm'), { danger: false }).then(function (ok) {
      if (!ok) return;
      App.api('pub.code', { sid: sid, pub: pub }).then(function (r) { setCode(r.code); App.toast(t('live.new_code_ok'), 'success'); });
    });
  });
  $('btn-copy-link').addEventListener('click', function () {
    App.copyText(linkFor(code)).then(function () { App.toast(t('common.copied'), 'success'); });
  });
  $('btn-present').addEventListener('click', function () {
    var d = $('present');
    d.showModal();
    if (d.requestFullscreen) d.requestFullscreen().catch(function () {});
  });
  $('present').addEventListener('close', function () { if (document.fullscreenElement) document.exitFullscreen().catch(function () {}); });

  window.addEventListener('beforeunload', function (e) {
    if (running && local) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ---------- Start strony ---------- */
  if (cfg.mode === 'own') {
    $('live').hidden = false;
    $('live-title').textContent = cfg.stream.title;
    setCode(cfg.code);
    setLocal(null);
    var sameTab = false;
    try { sameTab = sessionStorage.getItem('sslive-pub') === String(cfg.stream.id); } catch (e) {}
    if (cfg.pubRecent && !sameTab) {
      // transmisja jest nadawana w innej karcie – nie przejmuj jej bez zgody
      $('paused-title').textContent = t('live.taken_title');
      $('paused-text').textContent = t('live.other_tab');
      $('btn-resume').textContent = t('live.take_here');
      $('btn-resume').onclick = function () {
        $('btn-resume').onclick = null;
        resumeStream().then(function () {
          $('btn-resume').innerHTML = App.icon('screen-share') + App.esc(t('live.resume'));
          $('paused-title').textContent = t('live.paused');
          $('paused-text').textContent = t('live.paused_text');
        }).catch(function (e) { App.toast(e.message, 'error'); });
      };
    } else {
      resumeStream().catch(function () { location.reload(); });
    }
  } else {
    $('setup').hidden = false;
  }
})();
