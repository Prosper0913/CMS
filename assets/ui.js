/* assets/ui.js — small UI polish, loaded with `defer` on every page.
   Auto-dismisses success alerts after a few seconds.
   (Page-to-page fading is now done natively by CSS — see "Page-to-page
   navigation" in style.css — so no click handling is needed here.)
   Cosmetic only: if this file fails to load, the site works exactly as before. */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-success').forEach(function (el) {
      setTimeout(function () {
        el.classList.add('ui-dismiss');
        setTimeout(function () { el.remove(); }, 600);
      }, 6000);
    });
  });
})();