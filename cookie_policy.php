<?php
// ============================================================
//  cookie_policy.php — Cookie Policy
//  Public page: readable whether signed in or not.
// ============================================================
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Cookie Policy — Classroom CMS</title>
  <link rel="icon" type="image/png" href="assets/images/TCM logo (2).png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-legal">

<?php $active_legal = 'cookies'; include __DIR__ . '/includes/legal_nav.php'; ?>

<div class="legal-wrap">
  <h1>Cookie Policy</h1>
  <p class="legal-updated">Last updated: October 5, 2026</p>

  <div class="legal-card">

    <h2>1. What Cookies We Use</h2>
    <p>
      This System uses exactly <strong>one</strong> cookie:
    </p>
    <ul>
      <li>
        <strong>PHPSESSID</strong> (strictly necessary) — created the moment you visit the site,
        used only to keep you signed in and to remember security tokens (like the ones that
        protect forms from being submitted by another site). It's deleted automatically when you
        log out or close your browser, depending on your browser's own settings.
      </li>
    </ul>
    <div class="legal-note">
      We do <strong>not</strong> use advertising cookies, analytics/tracking cookies, or any
      cookies from third-party ad or tracking networks.
    </div>

    <h2>2. Why No Cookie-Consent Banner (Just a Notice)</h2>
    <p>
      Cookie-consent rules (such as the EU's ePrivacy rules and similar guidance from the
      Philippines' National Privacy Commission) require opt-in consent for cookies that aren't
      strictly necessary — for example, advertising or analytics cookies. Because the only
      cookie this System sets is strictly necessary to keep you signed in, consent isn't legally
      required to set it. For transparency, we still show a one-time, dismissible notice linking
      to this page the first time you visit — it's informational, not a consent gate, since
      there's nothing non-essential to opt in or out of.
    </p>

    <h2>3. Other Technical Requests</h2>
    <p>
      Some pages load fonts and icons from Google Fonts and from the jsDelivr CDN (used for our
      icon set and, on pages with charts, the Chart.js library). These are standard static assets
      — like loading an image — and the System does not use them to set tracking cookies. Your
      browser may make a request to those domains to fetch the asset, the same way it would for
      any external image or font.
    </p>

    <h2>4. Managing Cookies</h2>
    <p>
      Most browsers let you view, block, or delete cookies in their settings. Since this System's
      only cookie is what keeps you signed in, blocking or deleting it will simply sign you out
      and require you to log in again — it won't affect anything else about your browsing.
    </p>

    <h2>5. Changes to This Policy</h2>
    <p>
      If that ever changes — for example, if a future feature introduces an optional
      analytics cookie — we'll update this page and the consent approach described above
      accordingly.
    </p>

    <h2>6. Contact</h2>
    <p>Questions about this policy should be directed to your school's System administrator.</p>

  </div>
</div>

<?php include __DIR__ . '/includes/cookie_notice.php'; ?>
</body>
</html>
