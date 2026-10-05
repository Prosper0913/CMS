<?php
// ============================================================
//  includes/cookie_notice.php
//  Site-wide, informational-only cookie notice. This system only
//  ever sets one cookie — the PHP session cookie (PHPSESSID),
//  which is strictly necessary to keep you signed in and isn't
//  used for tracking, analytics, or advertising. Because only a
//  strictly-necessary cookie is used, opt-in consent isn't legally
//  required — but we still show this once so it's never a surprise.
//  Dismissing it just sets a localStorage flag (not a cookie) so
//  it won't show again on that device/browser.
// ============================================================
?>
<style>
  /* Self-contained on purpose — this partial is included on pages (like
     login.php) that don't load assets/style.css, so it can't depend on it. */
  #cookie-notice{position:fixed;left:20px;right:20px;bottom:20px;z-index:9999;max-width:640px;margin:0 auto;
    background:#123f30;border:1px solid rgba(255,255,255,.14);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.35);
    padding:16px 18px;display:none;align-items:center;gap:16px;color:rgba(255,255,255,.82);font-size:13px;
    font-family:'DM Sans',sans-serif;}
  #cookie-notice.is-visible{display:flex;}
  #cookie-notice p{margin:0;flex:1;line-height:1.5;}
  #cookie-notice a{color:#34d399;text-decoration:none;font-weight:600;}
  #cookie-notice a:hover{text-decoration:underline;}
  #cookie-notice button{flex-shrink:0;background:#34d399;color:#06281f;border:none;border-radius:8px;
    padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;}
  @media (max-width:640px){ #cookie-notice{flex-direction:column;align-items:stretch;} }
</style>
<div id="cookie-notice" role="status" aria-live="polite">
  <p>
    This site uses only one cookie — a session cookie that keeps you signed in. It isn't used for tracking or ads.
    <a href="/classroomv2/cookie_policy.php">Learn more</a>
  </p>
  <button type="button" id="cookie-notice-dismiss">Got it</button>
</div>
<script>
(function(){
  var KEY = 'cms_cookie_notice_dismissed';
  var banner = document.getElementById('cookie-notice');
  if (!banner) return;
  try {
    if (localStorage.getItem(KEY) === '1') return;
  } catch (e) { /* storage blocked — just show it once per page load */ }
  banner.classList.add('is-visible');
  var btn = document.getElementById('cookie-notice-dismiss');
  if (btn) {
    btn.addEventListener('click', function(){
      banner.classList.remove('is-visible');
      try { localStorage.setItem(KEY, '1'); } catch (e) {}
    });
  }
})();
</script>
