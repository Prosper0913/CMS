/* assets/pwa.js — turns the website into an installable app.
   Adds the manifest/theme tags (if the page doesn't already have them),
   registers the service worker, and powers the "Install app" button on the
   login page. Cosmetic/optional: if it fails, the site works exactly as before. */
(function () {
  'use strict';
  var BASE = '/classroomv2/';
  var head = document.head;

  function addTag(name, attrs) {
    var el = document.createElement(name);
    Object.keys(attrs).forEach(function (k) { el.setAttribute(k, attrs[k]); });
    head.appendChild(el);
  }
  if (!document.querySelector('link[rel="manifest"]'))
    addTag('link', { rel: 'manifest', href: BASE + 'manifest.webmanifest' });
  if (!document.querySelector('meta[name="theme-color"]'))
    addTag('meta', { name: 'theme-color', content: '#1e5f4e' });
  if (!document.querySelector('link[rel="apple-touch-icon"]'))
    addTag('link', { rel: 'apple-touch-icon', href: BASE + 'assets/icons/apple-touch-icon.png' });
  if (!document.querySelector('meta[name="apple-mobile-web-app-capable"]')) {
    addTag('meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
    addTag('meta', { name: 'apple-mobile-web-app-title', content: 'TCM Classroom' });
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE }).catch(function () {});
    });
  }

  // ── Install button (login page only: needs #pwa-install-btn) ──
  var box  = document.getElementById('app-install');
  var btn  = document.getElementById('pwa-install-btn');
  var ios  = document.getElementById('pwa-ios-hint');
  if (!box) return;

  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone;
  if (standalone) { box.hidden = true; return; }          // already running as the installed app

  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) {   // Chrome / Edge / Android
    e.preventDefault();
    deferred = e;
    if (btn) btn.hidden = false;
    box.hidden = false;
  });
  if (btn) btn.addEventListener('click', function () {
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.then(function () { deferred = null; btn.hidden = true; });
  });
  window.addEventListener('appinstalled', function () { box.hidden = true; });

  // iPhone/iPad Safari has no install prompt — show manual steps instead.
  if (ios && /iphone|ipad|ipod/i.test(navigator.userAgent)) { ios.hidden = false; box.hidden = false; }
})();
