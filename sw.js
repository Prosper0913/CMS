/* sw.js — service worker for the TCM Classroom "installed app".
   Lives at /classroomv2/sw.js so it can control every page under /classroomv2/.

   What it does:
   - Pages (PHP) always come from the network, so grades/attendance are never stale
     or cached. If the network is down it shows a friendly offline page instead of
     the browser's dinosaur.
   - Static files (CSS, JS, images, icons) are cached for speed: shown instantly from
     cache and refreshed in the background.
   - It never touches POST requests, /api/ calls, or anything outside /classroomv2/. */
const VERSION = 'v1';
const CACHE = 'tcm-static-' + VERSION;
const BASE = '/classroomv2/';
const OFFLINE_URL = BASE + 'pwa-offline.html';
const PRECACHE = [OFFLINE_URL, BASE + 'assets/icons/icon-192.png', BASE + 'assets/images/TCM%20logo%20(2).png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('tcm-static-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin || !url.pathname.startsWith(BASE)) return;
  if (url.pathname.startsWith(BASE + 'api/')) return;

  // Page loads: network first, offline page as the fallback.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
    return;
  }

  // Static assets: stale-while-revalidate.
  if (url.pathname.startsWith(BASE + 'assets/') && /\.(css|js|png|jpe?g|webp|svg|ico|woff2?)$/i.test(url.pathname)) {
    e.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((hit) => {
          const net = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res; }).catch(() => hit);
          return hit || net;
        })
      )
    );
  }
});
