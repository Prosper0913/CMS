<?php
// ============================================================
//  includes/auth_page.php  —  Shared layout for the public
//  account-recovery pages (forgot_password.php / reset_password.php).
//  Same look as the sign-in card on login.php.
//
//    auth_page_start('Title', 'Kicker', 'Heading');
//    ... page content ...
//    auth_page_end();
// ============================================================

function auth_page_start(string $title, string $kicker, string $heading): void {
    // Reset links carry a secret token in the URL — never leak it via
    // the Referer header (this page loads fonts/icons from CDNs).
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="assets/images/TCM logo (2).png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  <title><?php echo htmlspecialchars($title); ?> — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <style>
    :root{
      --school-bg:#1e5f4e; --school-text:#FFFFFF; --school-text6:#000000;
      --school-text7:#6B7280; --school-bg5:#FFFFFF;
      --border-on-light: color-mix(in srgb, var(--school-text7) 30%, transparent);
      --surface-tint: color-mix(in srgb, var(--school-bg5) 93%, var(--school-text7) 7%);
    }
    *{box-sizing:border-box;}
    body{
      margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      padding:32px 20px;font-family:'DM Sans',sans-serif;
      background:linear-gradient(160deg,var(--school-bg) 0%,#123d32 100%);
      color:var(--school-text6);
    }
    .ap-card{
      width:100%;max-width:420px;background:var(--school-bg5);
      border-radius:18px;padding:36px 32px;box-shadow:0 20px 60px rgba(0,0,0,.28);
    }
    .ap-card img.logo{display:block;margin:0 auto 14px;width:84px;height:84px;object-fit:contain;}
    .ap-card .kicker{font-size:12px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;color:var(--school-text7);margin-bottom:8px;}
    .ap-card h2{font-family:'Syne',sans-serif;font-size:24px;font-weight:700;margin:0 0 10px;}
    .ap-card p.lead{font-size:13.5px;line-height:1.6;color:var(--school-text7);margin:0 0 22px;}
    .ap-card form{display:flex;flex-direction:column;gap:16px;}
    .form-group label{display:block;font-size:12.5px;font-weight:500;color:var(--school-text7);margin-bottom:7px;}
    .input-wrap{position:relative;display:flex;align-items:center;gap:10px;background:var(--surface-tint);border:1px solid var(--border-on-light);border-radius:10px;padding:12px 14px;transition:border-color .25s ease;}
    .input-wrap:focus-within{border-color:var(--school-bg);}
    .input-wrap i{color:var(--school-text7);font-size:17px;}
    .input-wrap input,.input-wrap textarea{flex:1;background:transparent;border:none;outline:none;color:var(--school-text6);font-family:'DM Sans',sans-serif;font-size:14.5px;min-width:0;}
    .input-wrap textarea{resize:vertical;min-height:64px;}
    .input-wrap.top{align-items:flex-start;}
    .input-wrap.top i{margin-top:2px;}
    .btn-main{display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:10px;border:none;background:var(--school-bg);color:var(--school-text);font-family:'DM Sans',sans-serif;font-weight:600;font-size:14.5px;cursor:pointer;text-decoration:none;transition:filter .2s ease,transform .2s ease;}
    .btn-main:hover{filter:brightness(1.12);transform:translateY(-1px);}
    .alert{display:flex;align-items:flex-start;gap:8px;padding:11px 14px;border-radius:10px;font-size:13.5px;line-height:1.5;margin-bottom:18px;}
    .alert.err{background:rgba(224,97,109,.1);border:1px solid rgba(224,97,109,.35);color:#B3232E;}
    .alert.ok{background:rgba(30,95,78,.1);border:1px solid rgba(30,95,78,.35);color:#175040;}
    .back-link{display:block;text-align:center;margin-top:20px;font-size:13px;color:var(--school-text7);text-decoration:none;}
    .back-link:hover{color:var(--school-bg);text-decoration:underline;}
    .hint{font-size:11.5px;color:var(--school-text7);margin-top:6px;}
  </style>
</head>
<body>
<div class="ap-card">
  <img class="logo" src="assets/images/TCM logo (2).png" alt="TCM Logo">
  <div class="kicker"><?php echo htmlspecialchars($kicker); ?></div>
  <h2><?php echo htmlspecialchars($heading); ?></h2>
<?php
}

function auth_page_end(): void {
    ?>
</div>
</body>
</html>
<?php
}
