/* assets/ui.js — small UI polish, loaded with `defer` on every page.
   1) Fades the page out when you click an internal link.
   2) Auto-dismisses success alerts after a few seconds.
   Everything here is cosmetic: if this file fails to load, the site
   works exactly as before. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var root = document.documentElement;

  // Back/forward cache can restore a page frozen mid-fade — undo that.
  window.addEventListener('pageshow', function () { root.classList.remove('ui-leaving'); });

  // ── 1) fade-out on internal navigation ──
  if (!reduce) {
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a) return;
      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return;
      if (a.target && a.target !== '_self') return;                 // new tab
      if (a.hasAttribute('download')) return;                      // file download
      if (/[?&]export=/.test(href)) return;                        // CSV/PDF export: page stays put
      var url;
      try { url = new URL(a.href, location.href); } catch (_) { return; }
      if (url.origin !== location.origin) return;                  // external site
      if (url.pathname === location.pathname && url.search === location.search) return;  // same page / anchor

      // onclick="return confirm(...)" runs before this and sets defaultPrevented, so cancelled deletes never fade.
      e.preventDefault();
      root.classList.add('ui-leaving');
      setTimeout(function () { location.href = a.href; }, 150);
    });
  }

  // ── 2) auto-dismiss success alerts ──
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-success').forEach(function (el) {
      setTimeout(function () {
        el.classList.add('ui-dismiss');
        setTimeout(function () { el.remove(); }, 600);
      }, 6000);
    });
  });
})();