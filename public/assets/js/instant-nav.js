/* instant-nav.js — pages open instantly.
 *
 * When someone points at a link (mouse hover) or starts tapping it, the next
 * page is fetched in the background, so by the time the click lands it is
 * already here. Chrome / Edge / Android go further and render the page
 * ahead (Speculation Rules). Pages that change something just by opening
 * them (log out, delete, switch shop, downloads…) are never fetched early.
 * Opt a link out with data-no-instant.
 */
(function () {
  'use strict';
  if (window.dzInstant) return;
  window.dzInstant = true;

  // Never open these ahead of the click.
  var SKIP = [
    '/auth/', 'logout', '/switch', 'delete', 'remove', 'cancel', 'void', 'clock',
    'download', 'export', 'excel=', 'pdf=', 'csv=', 'print', 'receipt', '/api/',
    'action=', 'token=', 'resume=', 'sw.js', 'manifest',
    '/import/', '/backup/', '/signup', '/activate' // pages with upload / security forms: always load fresh
  ];
  function skip(url) {
    var s = (url.pathname + url.search).toLowerCase();
    for (var i = 0; i < SKIP.length; i++) { if (s.indexOf(SKIP[i]) !== -1) return true; }
    return /\.(pdf|xlsx?|csv|zip|sql|png|jpe?g|gif|webp)$/i.test(url.pathname);
  }

  var conn = navigator.connection || {};
  if (conn.saveData) return; // the person asked to save data

  // 1. Chrome / Edge: render the page ahead on hover (desktop) or touch (phones).
  if (HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
    var not = SKIP.map(function (p) { return { not: { href_matches: '*' + p + '*' } }; });
    var rules = {
      prerender: [{
        source: 'document',
        where: { and: [{ href_matches: '/*' }, { not: { selector_matches: '[data-no-instant], [target=_blank], [download]' } }].concat(not) },
        eagerness: 'moderate'
      }]
    };
    var s = document.createElement('script');
    s.type = 'speculationrules';
    s.textContent = JSON.stringify(rules);
    document.head.appendChild(s);
    return;
  }

  // 2. Other browsers: fetch the page early so the click is answered at once.
  var done = {};
  function warm(a) {
    if (!a || a.hasAttribute('data-no-instant') || a.hasAttribute('download') || (a.target && a.target !== '_self')) return;
    var url;
    try { url = new URL(a.href, location.href); } catch (e) { return; }
    if (url.origin !== location.origin || url.hash && url.pathname === location.pathname || skip(url)) return;
    var key = url.pathname + url.search;
    if (done[key] || key === location.pathname + location.search) return;
    done[key] = true;
    var l = document.createElement('link');
    l.rel = 'prefetch';
    l.href = url.href;
    document.head.appendChild(l);
  }
  var hoverTimer = null;
  document.addEventListener('mouseover', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(function () { warm(a); }, 65);
  }, { passive: true });
  document.addEventListener('mouseout', function () { clearTimeout(hoverTimer); }, { passive: true });
  document.addEventListener('touchstart', function (e) {
    warm(e.target.closest && e.target.closest('a[href]'));
  }, { passive: true, capture: true });
})();
