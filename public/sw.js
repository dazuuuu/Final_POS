/* Dazu Modern POS service worker — makes the app installable + fast.
   Pages are always network-first (so live data + auth stay correct);
   only static assets and the login shell are cached. */
const CACHE = 'dazu-pos-v8';
// Derived from this script's own URL, not hard-coded — works whatever
// folder the app is deployed under.
const BASE  = new URL('.', self.location).pathname.replace(/\/$/, '');
const SHELL = [
  BASE + '/manifest.webmanifest',
  BASE + '/assets/icons/icon-192.png',
  BASE + '/assets/icons/icon-512.png',
  BASE + '/assets/icons/icon-512-maskable.png',
  BASE + '/assets/icons/apple-touch-icon.png',
  BASE + '/assets/icons/favicon-32.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // This file (or the manifest) opened as a page — e.g. the installed app
  // reopening the last address it had: go to the POS, never show the code.
  if (req.mode === 'navigate' && /\/(sw\.js|manifest\.webmanifest)$/.test(url.pathname)) {
    event.respondWith(Response.redirect(BASE + '/terminal/', 302));
    return;
  }

  // Let redirects pass through normally; the browser rejects a redirected response
  // when the service worker tries to handle it with a non-follow mode.
  if (req.redirect === 'manual' || req.redirect === 'error') {
    return;
  }

  // No connection: a waiting screen that reloads by itself once the internet
  // is back, instead of the browser's broken "can't connect" page.
  const OFFLINE_PAGE = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Waiting for connection</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
    + 'flex-direction:column;gap:14px;font:500 15px/1.4 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#0f172a;text-align:center;padding:20px}'
    + '.r{width:56px;height:56px;border-radius:50%;border:6px solid #e2e8f0;border-top-color:#0f766e;animation:s 1s linear infinite}'
    + '@keyframes s{to{transform:rotate(360deg)}}small{color:#64748b}button{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:7px 16px;font:inherit}</style></head>'
    + '<body><div class="r"></div><div>Waiting for your internet connection…</div><small>This page opens by itself as soon as you are back online.</small>'
    + '<button onclick="location.reload()">Try again</button>'
    + '<script>function go(){location.reload()}addEventListener("online",go);setInterval(function(){if(navigator.onLine)fetch(location.href,{method:"HEAD",cache:"no-store"}).then(go,function(){})},4000)</script>'
    + '</body></html>';
  const fallbackResponse = () =>
    new Response(OFFLINE_PAGE, {
      status: 503,
      headers: { 'Content-Type': 'text/html; charset=utf-8' }
    });

  const networkThenCache = (request) =>
    fetch(request, { redirect: 'follow' })
      .then((response) => {
        if (!response || !response.ok) {
          throw new Error('Bad response');
        }
        // Never keep a page (HTML) — only files and data. A stored page could
        // come back later in place of the login screen.
        if ((response.headers.get('Content-Type') || '').indexOf('text/html') !== -1) {
          return response;
        }
        const copy = response.clone();
        caches.open(CACHE).then((cache) => cache.put(request, copy));
        return response;
      })
      .catch(() =>
        caches.match(request).then((hit) => {
          if (hit) return hit;
          return fallbackResponse();
        })
      );

  // Page navigations: always the network, whatever its status (a login error,
  // a 403 or a redirect must reach the user as-is). Pages are never cached —
  // a cached page is one shop's screen and must not stand in for another.
  // Only when the device is truly offline: a plain offline notice.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(fallbackResponse));
    return;
  }

  // Static assets: cache-first, then fill the cache.
  if (/\.(png|jpe?g|svg|gif|webp|ico|css|js|woff2?|ttf)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((hit) => {
        if (hit) return hit;
        return networkThenCache(req);
      })
    );
    return;
  }

  // Everything else: network, fall back to cache if offline.
  event.respondWith(networkThenCache(req));
});
