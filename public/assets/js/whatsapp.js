/* whatsapp.js — send a receipt / credit overview to a client's WhatsApp, from the user's own WhatsApp.
 * TasoWa.send(number, text) opens wa.me ready to send; TasoWa.saveMine(number, csrf, endpoint) stores the user's own number. */
(function () {
  'use strict';
  function norm(n) {
    var d = String(n || '').replace(/\D+/g, '');
    if (!d) return '';
    if (d.indexOf('00') === 0) d = d.slice(2); else if (d.charAt(0) === '0') d = '254' + d.slice(1); else if (d.length <= 9) d = '254' + d;
    return d.length >= 10 && d.length <= 15 ? d : '';
  }
  window.TasoWa = {
    norm: norm,
    send: function (number, text) {
      var n = norm(number);
      if (!n) { alert('Enter the client\'s WhatsApp number, e.g. 0712 345 678.'); return false; }
      window.open('https://wa.me/' + n + '?text=' + encodeURIComponent(text), '_blank', 'noopener');
      return true;
    },
    saveMine: function (number, csrf, endpoint, cb) {
      var fd = new FormData(); fd.append('number', number); fd.append('csrf_token', csrf);
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(cb, function () { cb({ ok: false, error: 'Could not save. Try again.' }); });
    }
  };
})();
