/* screensharelive-php – wspólny JS interfejsu (MIT) */
(function () {
  'use strict';
  var cfgEl = document.getElementById('app-config');
  var CFG = {};
  try { CFG = cfgEl ? JSON.parse(cfgEl.textContent) : {}; } catch (e) { CFG = {}; }
  var I18N = CFG.i18n || {};

  function t(key, params) {
    var s = Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : key;
    if (params) Object.keys(params).forEach(function (k) { s = s.split('{' + k + '}').join(String(params[k])); });
    return s;
  }

  function icon(name, cls) {
    var d = (window.TB_ICONS || {})[name];
    if (!d) return '';
    var filled = d.charAt(0) === 'F';
    return '<svg class="ic ' + (cls || '') + '" viewBox="0 0 24 24" width="20" height="20" ' +
      (filled ? 'fill="currentColor" stroke="none"' : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"') +
      ' aria-hidden="true">' + d.slice(1) + '</svg>';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- API ---------- */
  function api(action, data, opts) {
    opts = opts || {};
    var url = (CFG.api || 'api.php') + '?a=' + encodeURIComponent(action) + (opts.query ? '&' + opts.query : '');
    var init = { method: data === undefined && !opts.form ? 'GET' : 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CFG.csrf || '', 'Accept': 'application/json' } };
    if (opts.form) {
      init.body = opts.form;
    } else if (data !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(data);
    }
    if (opts.keepalive) init.keepalive = true;
    return fetch(url, init).then(function (r) {
      return r.text().then(function (txt) {
        var j = null;
        try { j = txt ? JSON.parse(txt) : {}; } catch (e) { j = { error: txt.slice(0, 200) || ('HTTP ' + r.status) }; }
        if (!r.ok) { var err = new Error(j.error || ('HTTP ' + r.status)); err.status = r.status; err.data = j; throw err; }
        return j;
      });
    });
  }

  /* ---------- Toasty ---------- */
  function toast(msg, type, actions, timeout) {
    var box = document.getElementById('toasts');
    if (!box) { box = document.createElement('div'); box.id = 'toasts'; box.className = 'toasts'; document.body.appendChild(box); }
    var el = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.innerHTML = (type === 'error' ? icon('alert-triangle') : type === 'success' ? icon('circle-check') : icon('info-circle')) + '<span></span>';
    el.querySelector('span').textContent = msg;
    (actions || []).forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = a.label;
      b.addEventListener('click', function () { a.onClick(); el.remove(); });
      el.appendChild(b);
    });
    box.appendChild(el);
    var ms = timeout === undefined ? (actions && actions.length ? 12000 : 3200) : timeout;
    if (ms > 0) setTimeout(function () { el.style.transition = 'opacity .25s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 260); }, ms);
    return el;
  }

  /* ---------- Okna dialogowe ---------- */
  function dialogBox(opts) {
    return new Promise(function (resolve) {
      var d = document.createElement('dialog');
      d.className = 'modal';
      d.innerHTML = '<form class="modal-box" method="dialog">' +
        '<div class="modal-head"><h2></h2></div>' +
        (opts.message ? '<p class="muted dlg-msg" style="margin:0"></p>' : '') +
        (opts.input !== undefined ? '<label class="field"><input type="text" maxlength="190" class="dlg-input"></label>' : '') +
        '<div class="modal-foot"><button type="button" class="btn btn-ghost dlg-cancel"></button><button type="submit" class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + ' dlg-ok"></button></div></form>';
      d.querySelector('h2').textContent = opts.title || '';
      if (opts.message) d.querySelector('.dlg-msg').textContent = opts.message;
      d.querySelector('.dlg-cancel').textContent = opts.cancel || t('common.cancel');
      d.querySelector('.dlg-ok').textContent = opts.ok || t('common.ok');
      var input = d.querySelector('.dlg-input');
      if (input) input.value = opts.input;
      var done = false;
      function finish(v) { if (done) return; done = true; d.close(); d.remove(); resolve(v); }
      d.querySelector('.dlg-cancel').addEventListener('click', function () { finish(input ? null : false); });
      d.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); finish(input ? input.value : true); });
      d.addEventListener('cancel', function (e) { e.preventDefault(); finish(input ? null : false); });
      document.body.appendChild(d);
      d.showModal();
      if (input) { input.focus(); input.select(); } else d.querySelector('.dlg-ok').focus();
    });
  }
  function confirmBox(message, opts) {
    opts = opts || {};
    return dialogBox({ title: opts.title || t('common.confirm'), message: message, ok: opts.ok || t('common.ok'), danger: opts.danger !== false });
  }
  function promptBox(title, value) { return dialogBox({ title: title, input: value || '', ok: t('common.save') }); }

  /* ---------- Motyw ---------- */
  function effectiveTheme() {
    var th = document.documentElement.getAttribute('data-theme');
    if (th === 'system' || !th) return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    return th;
  }
  function setTheme(th) {
    document.documentElement.setAttribute('data-theme', th);
    if (CFG.loggedIn) api('user.theme', { theme: th }).catch(function () {});
    else try { localStorage.setItem('sslive-theme', th); } catch (e) {}
    document.dispatchEvent(new CustomEvent('themechange'));
  }

  /* ---------- Delegacja zdarzeń ---------- */
  function closeDropdowns(except) {
    document.querySelectorAll('.dropdown.open').forEach(function (d) { if (d !== except) d.classList.remove('open'); });
  }

  /**
   * Menu wewnątrz przewijanego kontenera (np. tabeli) otwiera się w pozycji "fixed",
   * aby nie było przycinane i nie powodowało dodatkowego paska przewijania.
   */
  function placeMenu(btn, wrap) {
    var menu = wrap.querySelector('.menu');
    if (!menu) return;
    if (!wrap.closest('.table-card')) return;
    menu.classList.add('menu-fixed');
    var r = btn.getBoundingClientRect();
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    var left = menu.classList.contains('menu-right') ? r.right - mw : r.left;
    var top = r.bottom + 6;
    if (top + mh > window.innerHeight - 8 && r.top - mh - 6 > 8) top = r.top - mh - 6;
    menu.style.left = Math.max(8, Math.min(left, window.innerWidth - mw - 8)) + 'px';
    menu.style.top = Math.max(8, top) + 'px';
  }
  // menu w pozycji fixed zamykamy przy przewijaniu / zmianie rozmiaru okna
  window.addEventListener('scroll', function (e) {
    if (e.target && e.target.closest && e.target.closest('.menu')) return;
    if (document.querySelector('.dropdown.open .menu-fixed')) closeDropdowns();
  }, true);
  window.addEventListener('resize', function () { if (document.querySelector('.dropdown.open .menu-fixed')) closeDropdowns(); });

  document.addEventListener('click', function (e) {
    var tgt = e.target;
    var dd = tgt.closest('[data-dropdown]');
    if (dd) {
      e.preventDefault();
      var wrap = dd.closest('.dropdown');
      closeDropdowns(wrap);
      wrap.classList.toggle('open');
      if (wrap.classList.contains('open')) placeMenu(dd, wrap);
      return;
    }
    if (!tgt.closest('.menu')) closeDropdowns();
    else if (tgt.closest('.menu-item')) setTimeout(closeDropdowns, 0);

    var th = tgt.closest('[data-theme-toggle]');
    if (th) { setTheme(effectiveTheme() === 'dark' ? 'light' : 'dark'); return; }

    var mo = tgt.closest('[data-modal-open]');
    if (mo) {
      var dlg = document.getElementById(mo.getAttribute('data-modal-open'));
      if (dlg) {
        Object.keys(mo.dataset).forEach(function (k) {
          var name = k.replace(/[A-Z]/g, function (c) { return '-' + c.toLowerCase(); });
          dlg.querySelectorAll('[data-fill="' + name + '"]').forEach(function (i) { i.value = mo.dataset[k]; });
          dlg.querySelectorAll('[data-fill-text="' + name + '"]').forEach(function (i) { i.textContent = mo.dataset[k]; });
        });
        dlg.querySelectorAll('[data-fill-clear]').forEach(function (i) { if (i.type === 'checkbox') i.checked = false; else i.value = ''; });
        dlg.querySelectorAll('[data-disable-if]').forEach(function (i) {
          var key = i.getAttribute('data-disable-if').replace(/-([a-z])/g, function (m, c) { return c.toUpperCase(); });
          i.disabled = mo.dataset[key] === '1';
        });
        dlg.querySelectorAll('[data-show-if]').forEach(function (i) {
          var key = i.getAttribute('data-show-if').replace(/-([a-z])/g, function (m, c) { return c.toUpperCase(); });
          i.hidden = mo.dataset[key] !== '1';
        });
        dlg.showModal();
        var af = dlg.querySelector('[autofocus]'); if (af) af.focus();
      }
      return;
    }
    var mc = tgt.closest('[data-modal-close]');
    if (mc) { var d2 = mc.closest('dialog'); if (d2) d2.close(); return; }
    if (tgt.tagName === 'DIALOG' && tgt.classList.contains('modal')) {
      var r = tgt.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) tgt.close();
    }

    var pw = tgt.closest('[data-pw-toggle]');
    if (pw) {
      var inp = pw.parentNode.querySelector('input');
      inp.type = inp.type === 'password' ? 'text' : 'password';
      pw.innerHTML = icon(inp.type === 'password' ? 'eye' : 'eye-off');
      return;
    }
    var cp = tgt.closest('[data-copy]');
    if (cp) {
      var src = document.querySelector(cp.getAttribute('data-copy'));
      var text = src ? (src.value !== undefined && src.tagName === 'TEXTAREA' ? src.value : Array.prototype.map.call(src.children.length ? src.children : [src], function (c) { return c.textContent; }).join('\n')) : '';
      copyText(text).then(function () { toast(t('common.copied'), 'success'); });
      return;
    }
    var dl = tgt.closest('[data-download-text]');
    if (dl) {
      var s2 = document.querySelector(dl.getAttribute('data-download-text'));
      var txt = Array.prototype.map.call(s2.children, function (c) { return c.textContent; }).join('\r\n');
      downloadBlob(new Blob([txt + '\r\n'], { type: 'text/plain' }), dl.getAttribute('data-filename') || 'codes.txt');
      return;
    }
    var pl = tgt.closest('[data-post-link]');
    if (pl) {
      e.preventDefault();
      var f = document.createElement('form');
      f.method = 'post'; f.action = pl.getAttribute('href');
      f.innerHTML = '<input type="hidden" name="_csrf">';
      f.firstChild.value = CFG.csrf || '';
      document.body.appendChild(f); f.submit();
    }
  });

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDropdowns(); });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute('data-confirm') && !f._confirmed) {
      e.preventDefault();
      confirmBox(f.getAttribute('data-confirm')).then(function (ok) {
        if (ok) { f._confirmed = true; if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
      });
    }
  }, true);

  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-autosubmit]')) e.target.form.submit();
  });

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (el.matches('[data-pw-meter]')) {
      var v = el.value, score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
      if (/\d/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      var bar = el.closest('.field').querySelector('.pw-meter i');
      if (bar) {
        bar.style.width = Math.min(100, score * 20) + '%';
        bar.style.background = score <= 2 ? 'var(--danger)' : score <= 3 ? 'var(--warn)' : 'var(--success)';
      }
    }
    if (el.tagName === 'TEXTAREA' && el.closest('.tr-table')) autosize(el);
  });

  function autosize(el) { el.style.height = 'auto'; el.style.height = Math.min(400, el.scrollHeight + 2) + 'px'; }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (res) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      ta.remove(); res();
    });
  }

  function downloadBlob(blob, name) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }

  function relTime(date) {
    var diff = (new Date(date).getTime() - Date.now()) / 1000;
    var rtf;
    try { rtf = new Intl.RelativeTimeFormat(CFG.lang || 'pl', { numeric: 'auto' }); } catch (e) { return null; }
    var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (var i = 0; i < units.length; i++) {
      if (Math.abs(diff) >= units[i][1]) return rtf.format(Math.round(diff / units[i][1]), units[i][0]);
    }
    return rtf.format(0, 'minute');
  }

  function init() {
    document.querySelectorAll('time[data-rel]').forEach(function (el) {
      var r = relTime(el.getAttribute('data-rel'));
      if (r) el.textContent = r;
    });
    document.querySelectorAll('[data-qr]').forEach(function (el) {
      if (typeof window.qrcode !== 'function') return;
      var qr = window.qrcode(0, 'M');
      qr.addData(el.getAttribute('data-qr'));
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    });
    document.querySelectorAll('.tr-table textarea').forEach(autosize);
    var trSearch = document.getElementById('tr-search'), trMissing = document.getElementById('tr-missing');
    function trFilter() {
      var q = (trSearch.value || '').toLowerCase(), m = trMissing.checked;
      document.querySelectorAll('.tr-table tbody tr').forEach(function (tr) {
        var show = (!q || tr.getAttribute('data-key').indexOf(q) !== -1) && (!m || tr.getAttribute('data-missing') === '1');
        tr.hidden = !show;
      });
    }
    if (trSearch) { trSearch.addEventListener('input', trFilter); trMissing.addEventListener('change', trFilter); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.App = {
    cfg: CFG, t: t, icon: icon, esc: esc, api: api, toast: toast,
    confirm: confirmBox, prompt: promptBox, copyText: copyText, download: downloadBlob,
    effectiveTheme: effectiveTheme
  };
})();
