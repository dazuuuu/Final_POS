/* error-report.js — tells the server when something fails in the browser, so it shows up in
 * Support → Errors: script errors, requests that failed (503, 500, no connection) and anything
 * a page reports with window.dzReport(message). Never shows anything to the user. */
(function () {
  'use strict';
  if (window.dzReport) return;
  var sent = {}, count = 0, BASE = (document.currentScript && document.currentScript.src || '').replace(/assets\/js\/error-report\.js.*$/, '');
  function send(source, message, url, status) {
    var key = source + message + url;
    if (sent[key] || count >= 15) return;
    sent[key] = 1; count++;
    try {
      var body = JSON.stringify({ source: source, message: String(message).slice(0, 300), url: url || '', status: status || 0, page: location.pathname });
      var ep = BASE + 'api/errors/report.php';
      if (navigator.sendBeacon) { navigator.sendBeacon(ep, new Blob([body], { type: 'application/json' })); }
      else { fetch(ep, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' }).catch(function () {}); }
    } catch (e) {}
  }
  window.dzReport = function (m, u, s) { send('page', m, u, s); };
  window.addEventListener('error', function (e) {
    if (e.target && e.target !== window) return; // broken images / files are not script errors
    send('js', (e.message || 'Script error') + ' (' + String(e.filename || '').split('/').pop() + ':' + (e.lineno || 0) + ')', '', 0);
  });
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason; send('js', 'Unhandled: ' + (r && r.message ? r.message : String(r)), '', 0);
  });
  if (window.fetch) {
    var orig = window.fetch;
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      return orig.apply(this, arguments).then(function (res) {
        if (res && res.status >= 500 && url.indexOf('errors/report') === -1) send('fetch', 'HTTP ' + res.status + ' ' + (res.statusText || ''), url, res.status);
        return res;
      }, function (err) {
        if (url.indexOf('errors/report') === -1 && (!init || !init.signal || !init.signal.aborted)) send('fetch', 'Request failed: ' + (err && err.message || 'network'), url, 0);
        throw err;
      });
    };
  }
})();
