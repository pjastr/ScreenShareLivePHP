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
  try { var sq = sessionStorage.getItem('sslive-quality'); if (sq && cfg.qualities[sq] && cfg.mode === 'own') quality = sq; } catch (e) {}
  var local = null;           // MediaStream z ekranu
  var peers = {};             // id widza -> { pc, cid, created, state, pending: [] }
  var viewers = [];
  var outbox = [], flushTimer = null, pollTimer = null, frameTimer = null, frameBusy = false;
  var needFrames = false, running = false, warned5 = false, cidSeq = 0;
  var preview = $('preview');

  function Q() { return cfg.qualities[quality] || cfg.qualities.balanced; }

  /* ---------- Ekran ---------- */
  /* Jakość wybrana przez widza: obniża rozdzielczość i przepływność tylko dla niego. */
  var VQ = { high: { scale: 1, rate: 1 }, medium: { scale: 0.67, rate: 0.45 }, low: { scale: 0.5, rate: 0.2 } };
  var vqs = {};               // id widza -> high | medium | low

  function getMedia() {
    var q = Q();
    // Przechwytywanie w pełnej rozdzielczości ekranu (do 4K); zmniejszanie odbywa się przy kodowaniu,
    // więc jakość można zmienić w trakcie transmisji bez ponownego wybierania ekranu.
    var v = { frameRate: { ideal: q.fps, max: 30 }, width: { max: 3840 }, height: { max: 2160 } };
    var ask = function (audio) { return navigator.mediaDevices.getDisplayMedia({ video: v, audio: audio }); };
    return (wantAudio ? ask(true).catch(function (e) { if (e && e.name === 'TypeError') return ask(false); throw e; }) : ask(false))
      .then(function (ms) {
        ms.getVideoTracks().forEach(function (tr) {
          try { tr.contentHint = q.hint; } catch (e) {}
          if (tr.applyConstraints) tr.applyConstraints({ frameRate: { ideal: q.fps, max: q.fps } }).catch(function () {});
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
    updateInfo();
    if (ms) preview.addEventListener('loadedmetadata', updateInfo, { once: true });
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

  function srcSize() {
    var vt = local && local.getVideoTracks()[0];
    var st = vt && vt.getSettings ? vt.getSettings() : {};
    return { w: st.width || preview.videoWidth || 1920, h: st.height || preview.videoHeight || 1080, fps: st.frameRate || 0 };
  }

  /** Parametry kodowania dla jednego widza: preset nauczyciela × jakość wybrana przez widza. */
  function applyEncoding(id) {
    var peer = peers[id];
    if (!peer) return;
    var q = Q(), vq = VQ[vqs[id]] || VQ.high, src = srcSize();
    var scale = Math.max(1, src.w / Math.max(320, q.width * vq.scale));
    peer.pc.getSenders().forEach(function (s) {
      if (!s.track || s.track.kind !== 'video' || !s.getParameters) return;
      var set = function (withPref) {
        var p = s.getParameters();
        if (!p.encodings || !p.encodings.length) return Promise.resolve();
        p.encodings[0].maxBitrate = Math.round(q.bitrate * 1000 * vq.rate);
        p.encodings[0].maxFramerate = q.fps;
        p.encodings[0].scaleResolutionDownBy = Math.round(scale * 100) / 100;
        if (withPref) p.degradationPreference = q.hint === 'detail' ? 'maintain-resolution' : 'balanced';
        return s.setParameters(p);
      };
      // nie każda przeglądarka obsługuje degradationPreference – wtedy bez niego
      set(true).catch(function () { return set(false); }).catch(function (e) { console.warn('encoding', e); });
    });
  }

  /** Preferowany kodek (np. VP9 – lepsza ostrość tekstu przy tej samej przepływności). */
  function preferCodec(pc) {
    if (!cfg.codec || cfg.codec === 'auto') return;
    var mime = 'video/' + (cfg.codec === 'h264' ? 'h264' : cfg.codec);
    pc.getTransceivers().forEach(function (tc) {
      if (!tc.sender || !tc.sender.track || tc.sender.track.kind !== 'video' || !tc.setCodecPreferences) return;
      [window.RTCRtpReceiver, window.RTCRtpSender].some(function (K) {
        if (!K || !K.getCapabilities) return false;
        var caps = (K.getCapabilities('video') || {}).codecs || [];
        var first = caps.filter(function (c) { return c.mimeType.toLowerCase() === mime; });
        if (!first.length) return false;
        try { tc.setCodecPreferences(first.concat(caps.filter(function (c) { return first.indexOf(c) < 0; }))); return true; } catch (e) { return false; }
      });
    });
  }

  /** Szybszy start obrazu w dobrej jakości (Chrome): początkowa przepływność zamiast ~300 kb/s. */
  function boostStart(sdp) {
    var start = Math.min(Q().bitrate, 2500), lines = sdp.split('\r\n'), inVideo = false, pts = {};
    lines.forEach(function (l) {
      if (l.indexOf('m=') === 0) inVideo = l.indexOf('m=video') === 0;
      var m = inVideo && l.match(/^a=rtpmap:(\d+) (VP8|VP9|H264|AV1)\//i);
      if (m) pts[m[1]] = true;
    });
    var out = [], hasFmtp = {};
    lines.forEach(function (l) {
      var m = l.match(/^a=fmtp:(\d+) /);
      if (m && pts[m[1]] && l.indexOf('x-google-start-bitrate') < 0) { l += ';x-google-start-bitrate=' + start; hasFmtp[m[1]] = true; }
      out.push(l);
      var r = l.match(/^a=rtpmap:(\d+) /);
      if (r && pts[r[1]] && !new RegExp('^a=fmtp:' + r[1] + ' ', 'm').test(sdp)) out.push('a=fmtp:' + r[1] + ' x-google-start-bitrate=' + start);
    });
    return out.join('\r\n');
  }

  function createPeer(id) {
    if (!local) return;
    closePeer(id, false);
    var cid = (++cidSeq) + '-' + Math.random().toString(36).slice(2, 8);
    var pc = new RTCPeerConnection({ iceServers: cfg.ice || [] });
    var peer = peers[id] = { pc: pc, cid: cid, created: Date.now(), state: 'connecting', pending: [] };
    local.getTracks().forEach(function (tr) { pc.addTrack(tr, local); });
    preferCodec(pc);
    pc.onicecandidate = function (e) {
      if (e.candidate && peers[id] === peer) send(id, 'ice', { cid: cid, c: e.candidate.toJSON ? e.candidate.toJSON() : e.candidate });
    };
    var onState = function () {
      if (peers[id] !== peer) return;
      var cs = pc.connectionState || pc.iceConnectionState;
      peer.state = (cs === 'connected' || cs === 'completed') ? 'connected' : (cs === 'failed' ? 'failed' : (cs === 'disconnected' ? 'connecting' : peer.state));
      if (peer.state === 'connected') applyEncoding(id);
      renderViewers();
    };
    pc.onconnectionstatechange = onState;
    pc.oniceconnectionstatechange = onState;
    pc.createOffer()
      .then(function (o) { return pc.setLocalDescription(o); })
      .then(function () { applyEncoding(id); send(id, 'offer', { cid: cid, sdp: pc.localDescription.sdp }, true); })
      .catch(function (e) { console.warn('offer', e); });
  }

  function handle(sig) {
    var id = sig.viewer, d = sig.data || {}, p = peers[id];
    if (sig.type === 'hello') {
      // świeżo utworzone połączenie nie jest odtwarzane drugi raz
      if (p && Date.now() - p.created < 4000 && p.state !== 'failed') return;
      createPeer(id);
    } else if (sig.type === 'answer' && p && p.cid === d.cid) {
      var sdp = d.sdp;
      try { sdp = boostStart(sdp); } catch (e) {}
      p.pc.setRemoteDescription({ type: 'answer', sdp: sdp }).catch(function () {
        return p.pc.setRemoteDescription({ type: 'answer', sdp: d.sdp });
      }).then(function () {
        p.pending.forEach(function (c) { p.pc.addIceCandidate(c).catch(function () {}); });
        p.pending = [];
      }).catch(function (e) { console.warn('answer', e); });
    } else if (sig.type === 'quality') {
      vqs[id] = VQ[d.level] ? d.level : 'high';
      applyEncoding(id);
      renderViewers();
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
      notifyFallback(r.viewers || []);
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
  var fbs = { sent: 0, bytes: 0, lastBytes: 0, lastUp: 0, skipped: 0, hist: [] };
  function frameLoop() {
    frameTimer = null;
    if (!running || !needFrames || !local) return;
    frameTimer = setTimeout(frameLoop, 1000);
    if (frameBusy || !preview.videoWidth) { if (frameBusy) fbs.skipped++; return; }
    var w = preview.videoWidth, h = preview.videoHeight, sc = Math.min(1, Math.min(1920, Q().width) / w);
    canvas.width = Math.round(w * sc); canvas.height = Math.round(h * sc);
    canvas.getContext('2d').drawImage(preview, 0, 0, canvas.width, canvas.height);
    frameBusy = true;
    canvas.toBlob(function (blob) {
      if (!blob) { frameBusy = false; return; }
      var t0 = performance.now(), size = blob.size;
      fetch(api + '?a=pub.frame&sid=' + sid + '&pub=' + encodeURIComponent(pub), {
        method: 'POST', credentials: 'same-origin', body: blob,
        headers: { 'Content-Type': 'image/jpeg', 'X-CSRF-Token': App.cfg.csrf }
      }).then(function (r) {
        if (!r.ok) return;
        fbs.sent++; fbs.bytes += size; fbs.lastBytes = size; fbs.lastUp = performance.now() - t0;
        fbs.hist.push(Date.now());
        while (fbs.hist.length && fbs.hist[0] < Date.now() - 10000) fbs.hist.shift();
      }).catch(function () {}).then(function () { frameBusy = false; });
    }, 'image/jpeg', 0.85);
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
    updateOverlay(fmtLeft(left));
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
      var vq = vqs[v.id] && vqs[v.id] !== 'high' ? '<span class="v-vq" title="' + App.esc(t('live.vq_hint')) + '">' + App.esc(t('live.vq.' + vqs[v.id])) + '</span>' : '';
      var line = '';
      if (statsOn) {
        if (st === 'frames' && v.stats) line = framesLine(v.stats);
        else if (p && p.stats) line = peerLine(p.stats) + (v.stats && v.stats.loss > 0 ? ' · ' + t('live.st.loss_short', { n: v.stats.loss }) : '');
      }
      line = line ? '<span class="v-stats">' + App.esc(line) + '</span>' : '';
      return '<li><span class="avatar avatar-sm">' + App.esc(name.charAt(0).toUpperCase()) + '</span>' +
        '<span class="v-main"><span class="v-name" title="' + App.esc(name) + '">' + App.esc(name) + '</span>' + line + '</span>' + vq +
        '<span class="v-state ' + st + '">' + App.esc(t(stateNames[st] || st)) + '</span>' +
        '<button type="button" class="btn-icon btn-sm" data-kick="' + v.id + '" data-name="' + App.esc(name) + '" title="' + App.esc(t('live.kick')) + '">' + App.icon('user-x') + '</button></li>';
    }).join('');
  }

  /* ---------- Jakość w trakcie transmisji ---------- */
  function setQuality(key, silent) {
    if (!cfg.qualities[key]) return;
    quality = key;
    try { sessionStorage.setItem('sslive-quality', key); } catch (e) {}
    $('q-live').value = key;
    var q = Q(), vt = local && local.getVideoTracks()[0];
    if (vt) {
      try { vt.contentHint = q.hint; } catch (e) {}
      if (vt.applyConstraints) vt.applyConstraints({ frameRate: { ideal: q.fps, max: q.fps } }).catch(function () {});
    }
    Object.keys(peers).forEach(function (id) { applyEncoding(+id); });
    updateInfo();
    if (!silent) App.toast(t('live.quality_set', { q: t('live.q.' + key) }), 'success');
  }
  $('q-live').addEventListener('change', function () { setQuality(this.value); });

  function updateInfo() {
    var vt = local && local.getVideoTracks()[0];
    if (!vt) { $('share-info').textContent = ''; return; }
    var src = srcSize(), q = Q(), w = Math.min(src.w, q.width), h = Math.round(src.h * w / src.w);
    $('share-info').textContent = t('live.info', { src: src.w + '×' + src.h, out: w + '×' + h, fps: q.fps, mbps: (q.bitrate / 1000).toLocaleString(App.cfg.lang, { maximumFractionDigits: 1 }) }) +
      (local.getAudioTracks().length ? ' · ' + t('live.with_audio') : '');
  }

  /* ---------- Statystyki (WebRTC getStats) ---------- */
  var statsOn = false, statsTimer = null;
  function fmtRate(bps) {
    if (!(bps >= 0)) return '—';
    return bps >= 1e6 ? (bps / 1e6).toLocaleString(App.cfg.lang, { maximumFractionDigits: 1 }) + ' Mb/s' : Math.round(bps / 1000) + ' kb/s';
  }
  function connType(local, remote) {
    if (!local) return '';
    if (local.candidateType === 'relay' || (remote && remote.candidateType === 'relay')) return 'TURN';
    if (local.candidateType === 'host' && (!remote || remote.candidateType === 'host')) return 'LAN';
    return 'P2P';
  }
  function peerLine(s) {
    return [s.w ? s.w + '×' + s.h : '', s.fps ? Math.round(s.fps) + ' ' + t('live.unit.fps') : '', fmtRate(s.bps), s.rtt >= 0 ? Math.round(s.rtt * 1000) + ' ms' : '', s.conn].filter(Boolean).join(' · ');
  }
  function framesLine(s) {
    return [s.res ? String(s.res).replace('x', '×') : '', s.fps !== undefined ? s.fps + ' ' + t('live.unit.fps') : '', s.kbps !== undefined ? fmtRate(s.kbps * 1000) : '',
      s.age >= 0 ? t('live.st.age_short', { n: s.age >= 1000 ? (s.age / 1000).toFixed(1) + ' s' : s.age + ' ms' }) : '', 'HTTP'].filter(Boolean).join(' · ');
  }
  function collect(id) {
    var p = peers[id];
    if (!p || p.state !== 'connected') return Promise.resolve();
    return p.pc.getStats().then(function (rep) {
      var out = null, pair = null, codecs = {}, cands = {};
      rep.forEach(function (r) {
        if (r.type === 'outbound-rtp' && (r.kind || r.mediaType) === 'video') out = r;
        else if (r.type === 'candidate-pair' && (r.nominated || r.selected) && r.state === 'succeeded') pair = pair && pair.bytesSent > r.bytesSent ? pair : r;
        else if (r.type === 'codec') codecs[r.id] = r;
        else if (r.type === 'local-candidate' || r.type === 'remote-candidate') cands[r.id] = r;
      });
      if (!out) return;
      var prev = p.prevOut, s = p.stats || {};
      s.bps = prev && out.timestamp > prev.timestamp ? (out.bytesSent - prev.bytesSent) * 8000 / (out.timestamp - prev.timestamp) : s.bps;
      p.prevOut = { bytesSent: out.bytesSent, timestamp: out.timestamp };
      s.w = out.frameWidth || 0; s.h = out.frameHeight || 0; s.fps = out.framesPerSecond || 0;
      s.limit = out.qualityLimitationReason || 'none';
      s.codec = out.codecId && codecs[out.codecId] ? codecs[out.codecId].mimeType.replace('video/', '') : (s.codec || '');
      s.encoder = out.encoderImplementation || '';
      s.rtt = pair && pair.currentRoundTripTime !== undefined ? pair.currentRoundTripTime : -1;
      s.conn = pair ? connType(cands[pair.localCandidateId], cands[pair.remoteCandidateId]) : '';
      p.stats = s;
    }).catch(function () {});
  }
  function statsTick() {
    var ids = Object.keys(peers);
    Promise.all(ids.map(collect)).then(function () {
      var total = 0, limits = {}, codecs = {}, rtts = [], best = null, n = 0;
      ids.forEach(function (id) {
        var s = peers[id] && peers[id].stats;
        if (!s || peers[id].state !== 'connected') return;
        n++;
        total += s.bps || 0;
        if (s.limit && s.limit !== 'none') limits[s.limit] = (limits[s.limit] || 0) + 1;
        if (s.codec) codecs[s.codec] = 1;
        if (s.rtt >= 0) rtts.push(s.rtt);
        if (!best || s.w * s.h > best.w * best.h) best = s;
      });
      var src = srcSize();
      $('st-up').textContent = n ? fmtRate(total) + ' (' + n + ')' : '—';
      $('st-src').textContent = local ? src.w + '×' + src.h + (src.fps ? ' · ' + Math.round(src.fps) + ' ' + t('live.unit.fps') : '') : '—';
      $('st-sent').textContent = best ? best.w + '×' + best.h + ' · ' + Math.round(best.fps) + ' ' + t('live.unit.fps') : '—';
      $('st-codec').textContent = Object.keys(codecs).join(', ') || '—';
      $('st-stun').textContent = (cfg.stun || []).map(function (u) { return u.replace(/^stuns?:/, ''); }).join(', ') || t('live.ice.none');
      $('st-rtt').textContent = rtts.length ? Math.round(rtts.reduce(function (a, b) { return a + b; }, 0) / rtts.length * 1000) + ' ms' : '—';
      var lim = Object.keys(limits).sort(function (a, b) { return limits[b] - limits[a]; })[0];
      $('st-limit').textContent = lim ? t('live.limit.' + lim) + (n > 1 ? ' (' + limits[lim] + '/' + n + ')' : '') : t('live.limit.none');
      $('st-limit').className = lim ? 'warn-text' : '';
      var hint = $('st-hint');
      hint.hidden = !lim;
      if (lim) hint.textContent = t(lim === 'cpu' ? 'live.limit_hint.cpu' : 'live.limit_hint.bandwidth');
      // tryb zapasowy
      var fbViewers = viewers.filter(function (v) { return v.mode === 'frames' && !(peers[v.id] && peers[v.id].state === 'connected'); });
      $('st-fb').hidden = !fbViewers.length && !fbs.sent;
      if (!$('st-fb').hidden) {
        var now = Date.now(), recent = fbs.hist.filter(function (x) { return x > now - 5000; });
        var fps = recent.length > 1 ? (recent.length - 1) / ((recent[recent.length - 1] - recent[0]) / 1000) : 0;
        var ages = fbViewers.map(function (v) { return v.stats && v.stats.age >= 0 ? v.stats.age : null; }).filter(function (x) { return x !== null; });
        $('st-fb-n').textContent = String(fbViewers.length);
        $('st-fb-fps').textContent = needFrames ? (fps ? fps.toLocaleString(App.cfg.lang, { maximumFractionDigits: 1 }) : '0') + ' ' + t('live.unit.fps') : t('live.st.fb_idle');
        $('st-fb-size').textContent = fbs.lastBytes ? Math.round(fbs.lastBytes / 1024) + ' KB' : '—';
        $('st-fb-up').textContent = fbs.lastUp ? Math.round(fbs.lastUp) + ' ms' : '—';
        $('st-fb-sent').textContent = fbs.sent + ' · ' + (fbs.bytes / 1048576).toLocaleString(App.cfg.lang, { maximumFractionDigits: 1 }) + ' MB' + (fbs.skipped ? ' · ' + t('live.st.fb_skipped', { n: fbs.skipped }) : '');
        $('st-fb-srv').textContent = fbs.lastBytes && fbViewers.length ? '≈ ' + fmtRate(fbs.lastBytes * 8 * Math.max(fps, 0.5) * fbViewers.length) : '—';
        $('st-fb-age').textContent = ages.length ? Math.round(ages.reduce(function (a, b) { return a + b; }, 0) / ages.length) + ' ms' : '—';
      }
      if (statsOn) renderViewers();
    });
  }
  $('btn-stats').addEventListener('click', function () {
    statsOn = !statsOn;
    this.setAttribute('aria-pressed', String(statsOn));
    this.classList.toggle('active', statsOn);
    $('pub-stats').hidden = !statsOn;
    try { localStorage.setItem('sslive-stats', statsOn ? '1' : '0'); } catch (e) {}
    clearInterval(statsTimer);
    statsTimer = setInterval(function () { if (running && statsOn) statsTick(); }, 1000);
    if (statsOn) statsTick(); else renderViewers();
  });

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
    $('q-live').value = quality;
    try { sessionStorage.setItem('sslive-quality', quality); } catch (e) {}
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
    closeOverlay();
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
  /* Tytuł generowany automatycznie z daty i godziny (można go zmienić). */
  function pad(n) { return String(n).padStart(2, '0'); }
  function autoTitle() {
    var d = new Date();
    return t('live.default_title', { date: d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) });
  }
  var titleInput = $('setup-form').title, titleAuto = true;
  titleInput.value = autoTitle();
  titleInput.addEventListener('input', function () { titleAuto = titleInput.value.trim() === ''; });
  setInterval(function () { if (titleAuto && document.activeElement !== titleInput) titleInput.value = autoTitle(); }, 15000);

  $('setup-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target, btn = $('btn-start');
    if (titleAuto || !f.title.value.trim()) f.title.value = autoTitle();
    quality = (f.querySelector('[name=quality]:checked') || {}).value || quality;
    cfg.codec = f.codec.value;
    wantAudio = f.audio.checked;
    btn.disabled = true;
    getMedia().then(function (ms) {
      return App.api('pub.start', {
        title: f.title.value, minutes: +f.minutes.value, max_viewers: +f.max_viewers.value, audio: ms.getAudioTracks().length > 0, quality: quality, codec: cfg.codec
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

  /* ---------- Serwery STUN w trakcie transmisji ---------- */
  var fbSeen = {};
  /** Komunikat, gdy uczeń przechodzi na tryb zapasowy – z szybkim dostępem do zmiany STUN. */
  function notifyFallback(list) {
    list.forEach(function (v) {
      if (v.mode === 'frames' && !fbSeen[v.id]) {
        fbSeen[v.id] = true;
        App.toast(t('live.ice.viewer_fallback', { name: v.name || t('live.viewer_anon', { n: v.id }) }), 'info',
          [{ label: t('live.ice.change'), onClick: openIce }], 10000);
      }
      if (v.mode !== 'frames') delete fbSeen[v.id];
    });
  }
  function openIce() {
    $('ice-text').value = (cfg.stun || []).join('\n');
    $('ice-test-res').textContent = '';
    $('ice-dlg').showModal();
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-open-ice]')) { openIce(); return; }
    var pr = e.target.closest('[data-ice-preset]');
    if (pr) { $('ice-text').value = pr.getAttribute('data-ice-preset'); $('ice-test-res').textContent = ''; }
  });
  function stunFromText() {
    return $('ice-text').value.split(/[\s,]+/).map(function (u) { return u.trim(); }).filter(function (u) { return /^stuns?:[A-Za-z0-9.\-]+(:\d{1,5})?$/.test(u); });
  }
  /** Test: czy przez podane serwery STUN przeglądarka uzyskuje adres publiczny (kandydat srflx). */
  function testStun(urls) {
    return new Promise(function (resolve) {
      if (!urls.length) return resolve({ ok: false, empty: true });
      var t0 = performance.now(), done = false, pc;
      try { pc = new RTCPeerConnection({ iceServers: urls.map(function (u) { return { urls: u }; }) }); } catch (e) { return resolve({ ok: false }); }
      var finish = function (res) { if (done) return; done = true; try { pc.close(); } catch (e) {} resolve(res); };
      pc.onicecandidate = function (e) {
        if (e.candidate && / typ srflx /.test(e.candidate.candidate)) finish({ ok: true, ms: Math.round(performance.now() - t0), ip: (e.candidate.address || e.candidate.candidate.split(' ')[4]) });
        if (!e.candidate) finish({ ok: false });
      };
      pc.createDataChannel('t');
      pc.createOffer().then(function (o) { return pc.setLocalDescription(o); }).catch(function () { finish({ ok: false }); });
      setTimeout(function () { finish({ ok: false }); }, 5000);
    });
  }
  $('ice-test-btn').addEventListener('click', function () {
    var res = $('ice-test-res'), urls = stunFromText();
    res.className = 'small muted'; res.textContent = t('live.ice.testing');
    testStun(urls).then(function (r) {
      res.className = 'small ' + (r.ok ? 'ok-text' : 'warn-text');
      res.textContent = r.empty ? t('live.ice.test_empty') : (r.ok ? t('live.ice.test_ok', { ms: r.ms, ip: r.ip }) : t('live.ice.test_fail'));
    });
  });
  $('ice-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var urls = stunFromText();
    if ($('ice-text').value.trim() && !urls.length) { App.toast(t('live.ice.invalid'), 'error'); return; }
    App.api('pub.ice', { sid: sid, pub: pub, stun: urls }).then(function (r) {
      cfg.ice = r.ice; cfg.stun = r.stun;
      $('ice-dlg').close();
      $('st-stun').textContent = (cfg.stun || []).map(function (u) { return u.replace(/^stuns?:/, ''); }).join(', ') || t('live.ice.none');
      App.toast(r.frames_viewers ? t('live.ice.applied_retry', { n: r.frames_viewers }) : t('live.ice.applied'), 'success', null, 6000);
    }).catch(function (err) { App.toast(err.message, 'error'); });
  });

  /* ---------- Nakładka z kodem i adresem (zawsze na wierzchu / w lewym dolnym rogu ekranu) ---------- */
  var ovWin = null, ovIsPip = false;
  var OV_CSS = 'html,body{margin:0;height:100%;background:#111522;color:#eef0f7;font:14px/1.3 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;overflow:hidden}' +
    '.ov{display:flex;align-items:center;gap:14px;height:100%;padding:8px 14px;box-sizing:border-box}' +
    '.dot{width:10px;height:10px;border-radius:50%;background:#ff5d5d;flex:none;animation:b 1.6s infinite}@keyframes b{50%{opacity:.3}}' +
    '.txt{display:flex;flex-direction:column;min-width:0;flex:1}.lbl{color:#a8aec0;font-size:12px}' +
    '.url{font-weight:650;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#9d9dff}' +
    '.code{font:800 30px/1 ui-monospace,Consolas,monospace;letter-spacing:.06em;white-space:nowrap}' +
    '.meta{color:#a8aec0;font-size:12px;white-space:nowrap}';
  function ovHtml() {
    var host = cfg.viewerUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
    return '<div class="ov"><span class="dot"></span><div class="txt"><span class="lbl">' + App.esc(t('live.overlay_join')) + '</span>' +
      '<span class="url">' + App.esc(host) + '</span><span class="meta" id="ov-meta"></span></div>' +
      '<div class="code" id="ov-code">' + App.esc(fmtCode(code)) + '</div></div>';
  }
  function fillOverlay(doc) {
    doc.title = t('live.overlay') + ' – ' + fmtCode(code);
    var st = doc.createElement('style'); st.textContent = OV_CSS; doc.head.appendChild(st);
    doc.body.innerHTML = ovHtml();
  }
  function updateOverlay(left) {
    if (!ovWin || ovWin.closed) { ovWin = null; return; }
    try {
      var d = ovWin.document, c = d.getElementById('ov-code'), m = d.getElementById('ov-meta');
      if (c && c.textContent !== fmtCode(code)) c.textContent = fmtCode(code);
      if (m) m.textContent = '⏳ ' + left + '  ·  👥 ' + viewers.length + ' / ' + maxV;
    } catch (e) {}
  }
  function closeOverlay() { try { if (ovWin && !ovWin.closed) ovWin.close(); } catch (e) {} ovWin = null; }
  function openOverlay() {
    if (ovWin && !ovWin.closed) { closeOverlay(); return; }
    var W = 380, H = 96;
    if (window.documentPictureInPicture && documentPictureInPicture.requestWindow) {
      // Chrome/Edge: okno zawsze na wierzchu (można je przeciągnąć w dowolne miejsce ekranu)
      documentPictureInPicture.requestWindow({ width: W, height: H, disallowReturnToOpener: true }).then(function (w) {
        ovWin = w; ovIsPip = true; fillOverlay(w.document);
        w.addEventListener('pagehide', function () { ovWin = null; });
        App.toast(t('live.overlay_pip'), 'info', null, 7000);
      }).catch(function () { openPopup(W, H); });
    } else openPopup(W, H);
  }
  function openPopup(W, H) {
    // inne przeglądarki: małe okno w lewym dolnym rogu, tuż nad paskiem zadań
    var sc = window.screen || {}, left = (sc.availLeft || 0) + 8, top = (sc.availTop || 0) + (sc.availHeight || 800) - H - 60;
    var w = window.open('', 'sslive-overlay', 'popup=yes,width=' + W + ',height=' + H + ',left=' + left + ',top=' + top);
    if (!w) { App.toast(t('live.overlay_blocked'), 'error'); return; }
    ovWin = w; ovIsPip = false;
    w.document.open(); w.document.write('<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>'); w.document.close();
    fillOverlay(w.document);
    try { w.moveTo(left, top); } catch (e) {}
  }
  $('btn-overlay').addEventListener('click', openOverlay);

  /* Kodeki, których ta przeglądarka nie potrafi wysyłać, są oznaczone i wyłączone. */
  (function () {
    var sel = $('codec-select');
    if (!sel || !window.RTCRtpSender || !RTCRtpSender.getCapabilities) return;
    var have = ((RTCRtpSender.getCapabilities('video') || {}).codecs || []).map(function (c) { return c.mimeType.toLowerCase(); });
    Array.prototype.forEach.call(sel.options, function (o) {
      if (o.value === 'auto') return;
      if (have.indexOf('video/' + o.value) < 0) { o.disabled = true; o.textContent += ' – ' + t('live.codec_unsupported'); if (o.selected) sel.value = 'auto'; }
    });
  })();

  /* ---------- Zamknięcie karty kończy transmisję (po krótkiej chwili na ewentualne odświeżenie) ---------- */
  window.addEventListener('pagehide', function () {
    if (!running || !pub) return;
    var fd = new FormData();
    fd.append('sid', sid); fd.append('pub', pub); fd.append('_csrf', App.cfg.csrf);
    try { navigator.sendBeacon(api + '?a=pub.bye', fd); } catch (e) {}
  });

  /* ---------- Start strony ---------- */
  $('q-live').value = quality;
  try { if (localStorage.getItem('sslive-stats') === '1') $('btn-stats').click(); } catch (e) {}
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
