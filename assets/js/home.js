/* ScreenShare Live PHP – strona główna: oczekiwanie na transmisję, formatowanie kodu (MIT) */
(function () {
  'use strict';
  var App = window.App;
  if (document.querySelector('[data-live-wait]')) {
    var check = function () {
      App.api('live.status').then(function (r) { if (r.live) location.reload(); }).catch(function () {});
    };
    setInterval(check, 5000);
  }
  var code = document.querySelector('input[name="code"]');
  if (code) {
    code.addEventListener('input', function () {
      var d = code.value.replace(/\D/g, '').slice(0, 6);
      code.value = d.length > 3 ? d.slice(0, 3) + ' ' + d.slice(3) : d;
    });
    if (code.value) code.dispatchEvent(new Event('input'));
  }
})();
