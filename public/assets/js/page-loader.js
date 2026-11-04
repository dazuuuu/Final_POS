/* page-loader.js — a 1% → 100% loading screen for slow moments.
 *
 * It never slows anything down: the screen only appears when a page, a form
 * or a link takes longer than SHOW_AFTER ms. On a fast connection pages open
 * straight away and the loader is never seen.
 *   - first load of a page:   shown if the page isn't ready within SHOW_AFTER
 *   - clicking a link / sending a form: shown while the next page is on its way
 *   - slow background requests (fetch): a thin bar along the top
 *   - no internet: "waiting for connection", then carries on by itself
 * Opt out on an element with data-no-loader.
 */
(function () {
  'use strict';
  if (window.dzLoader) return;
  var SHOW_AFTER = 350;

  var css = ''
    + '#dzLoad{position:fixed;inset:0;z-index:99990;display:none;align-items:center;justify-content:center;flex-direction:column;gap:14px;'
    + 'background:rgba(255,255,255,.92);backdrop-filter:blur(2px);font:500 14px/1.3 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#0f172a;opacity:0;transition:opacity .2s ease}'
    + '#dzLoad.on{display:flex}#dzLoad.vis{opacity:1}'
    + '#dzLoad .dzl-ring{position:relative;width:86px;height:86px}'
    + '#dzLoad svg{width:86px;height:86px;transform:rotate(-90deg)}'
    + '#dzLoad circle{fill:none;stroke-width:7}#dzLoad .dzl-bg{stroke:#e2e8f0}'
    + '#dzLoad .dzl-fg{stroke:#0f766e;stroke-linecap:round;transition:stroke-dashoffset .25s ease}'
    + '#dzLoad .dzl-pct{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px}'
    + '#dzLoad .dzl-msg{color:#64748b;font-size:13px;text-align:center;max-width:260px}'
    + '#dzLoad .dzl-btn{display:none;border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:6px 14px;font:inherit;cursor:pointer}'
    + '#dzTopBar{position:fixed;top:0;left:0;height:3px;width:0;z-index:99991;background:#0f766e;transition:width .3s ease,opacity .3s ease;opacity:0;pointer-events:none}';
  var style = document.createElement('style');
  style.textContent = css;
  (document.head || document.documentElement).appendChild(style);

  var C = 2 * Math.PI * 38;
  var el, fg, pctEl, msgEl, btn, bar;
  function build() {
    if (el) return;
    el = document.createElement('div');
    el.id = 'dzLoad';
    el.setAttribute('role', 'progressbar');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = '<div class="dzl-ring"><svg viewBox="0 0 86 86"><circle class="dzl-bg" cx="43" cy="43" r="38"/>'
      + '<circle class="dzl-fg" cx="43" cy="43" r="38" stroke-dasharray="' + C + '" stroke-dashoffset="' + C + '"/></svg>'
      + '<div class="dzl-pct">1%</div></div><div class="dzl-msg">Loading…</div>'
      + '<button type="button" class="dzl-btn">Try again</button>';
    bar = document.createElement('div');
    bar.id = 'dzTopBar';
    var root = document.body || document.documentElement;
    root.appendChild(el);
    root.appendChild(bar);
    fg = el.querySelector('.dzl-fg');
    pctEl = el.querySelector('.dzl-pct');
    msgEl = el.querySelector('.dzl-msg');
    btn = el.querySelector('.dzl-btn');
    btn.addEventListener('click', function () { if (retry) retry(); else location.reload(); });
  }

  var pct = 0, timer = null, tick = null, started = 0, retry = null, shown = false;

  function set(p) {
    pct = Math.max(pct, Math.min(100, p));
    if (!el) return;
    var n = Math.max(1, Math.round(pct));
    pctEl.textContent = n + '%';
    fg.setAttribute('stroke-dashoffset', String(C * (1 - pct / 100)));
    el.setAttribute('aria-valuenow', String(n));
  }
  function message(text, withButton) {
    if (!el) return;
    msgEl.textContent = text;
    btn.style.display = withButton ? 'inline-block' : 'none';
  }
  function show() {
    build();
    if (shown) return;
    shown = true;
    el.classList.add('on');
    requestAnimationFrame(function () { el.classList.add('vis'); });
    set(pct || 1);
  }
  // Creeps toward 95%: quick at first, slower the longer it takes — real
  // progress (the page arriving) then takes it to 100%.
  function creep() {
    clearInterval(tick);
    tick = setInterval(function () {
      var secs = (Date.now() - started) / 1000;
      set(pct + Math.max(0.15, (95 - pct) * 0.06));
      if (secs > 12 && navigator.onLine !== false) message('Still loading — your connection seems slow…', true);
    }, 120);
  }

  /** Start the loader (shown only if this takes longer than SHOW_AFTER). */
  function start(msg, retryFn) {
    pct = 0; started = Date.now(); retry = retryFn || null;
    clearTimeout(timer);
    timer = setTimeout(function () { show(); message(msg || 'Loading…', false); }, SHOW_AFTER);
    creep();
  }
  /** Finish: run to 100% and fade out. */
  function done() {
    clearTimeout(timer); clearInterval(tick);
    if (!shown) { pct = 0; return; }
    set(100);
    setTimeout(function () {
      el.classList.remove('vis');
      setTimeout(function () { el.classList.remove('on'); shown = false; pct = 0; }, 220);
    }, 180);
  }

  // ---- 1. This page's own first load ----
  started = Date.now();
  if (document.readyState === 'loading') {
    timer = setTimeout(function () { show(); message('Loading…', false); }, SHOW_AFTER);
    creep();
    document.addEventListener('readystatechange', function () {
      if (document.readyState === 'interactive') set(70);
    });
    window.addEventListener('load', done);
    // Don't hold the page behind slow images: once the content is ready, finish.
    document.addEventListener('DOMContentLoaded', function () { setTimeout(done, 400); });
  }
  // Back/forward cache: the page comes back as it was — no loader.
  window.addEventListener('pageshow', function (e) { if (e.persisted) { shown = true; done(); } });

  // ---- 2. Leaving for another page (links and forms) ----
  function isDownload(url) {
    return /[?&](excel|pdf|csv|xlsx|download|export|print)=/i.test(url)
      || /\/(download|export)\.php/i.test(url)
      || /\.(pdf|xlsx?|csv|zip|sql)(\?|$)/i.test(url);
  }
  function offlineThen(go) {
    build(); show();
    message('No internet connection — waiting to reconnect…', true);
    retry = function () { if (navigator.onLine !== false) go(); };
    window.addEventListener('online', function once() {
      window.removeEventListener('online', once);
      message('Back online — loading…', false);
      go();
    });
  }

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.closest('[data-no-loader]') || a.hasAttribute('download')) return;
    var target = (a.getAttribute('target') || '').toLowerCase();
    if (target && target !== '_self') return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|sms|whatsapp|intent):/i.test(href)) return;
    var url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (url.origin !== location.origin || isDownload(url.href)) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
    if (navigator.onLine === false) {
      e.preventDefault();
      offlineThen(function () { location.href = url.href; });
      return;
    }
    start('Loading…', function () { location.href = url.href; });
  });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f || f.closest('[data-no-loader]')) return;
    var target = (f.getAttribute('target') || '').toLowerCase();
    if (target && target !== '_self') return;
    var action = f.getAttribute('action') || location.href;
    if (isDownload(action) || (f.method || '').toLowerCase() === 'get' && Array.prototype.some.call(f.elements, function (x) {
      return x.name && /^(excel|pdf|csv|download|export)$/i.test(x.name) && (x.type !== 'checkbox' || x.checked);
    })) return;
    if (navigator.onLine === false) {
      e.preventDefault();
      offlineThen(function () { f.submit(); });
      return;
    }
    // Pages with their own submit handling call preventDefault first, so
    // only real page submissions get here. Re-check once handlers have run.
    setTimeout(function () { if (!e.defaultPrevented) start(f.enctype === 'multipart/form-data' ? 'Uploading…' : 'Saving…'); }, 0);
  });

  // ---- 3. Slow background requests: thin bar at the top ----
  var active = 0, barTimer = null;
  function barOn() {
    build();
    clearTimeout(barTimer);
    barTimer = setTimeout(function () {
      if (!active) return;
      bar.style.opacity = '1';
      bar.style.width = '70%';
    }, 600);
  }
  function barOff() {
    if (active) return;
    clearTimeout(barTimer);
    if (!bar || bar.style.opacity !== '1') return;
    bar.style.width = '100%';
    setTimeout(function () { bar.style.opacity = '0'; setTimeout(function () { bar.style.width = '0'; }, 300); }, 200);
  }
  if (window.fetch) {
    var origFetch = window.fetch;
    window.fetch = function () {
      active++; barOn();
      return origFetch.apply(this, arguments).then(function (r) {
        active--; barOff(); return r;
      }, function (err) {
        active--; barOff(); throw err;
      });
    };
  }

  window.addEventListener('offline', function () {
    if (shown) message('No internet connection — waiting to reconnect…', true);
  });

  window.dzLoader = { start: start, done: done, set: set };
})();
