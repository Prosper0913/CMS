<?php
// ============================================================
//  loginv2.php — Sign-in page, v2
//  Same auth logic as login.php (kept as-is / unused for now).
//  Differences from login.php:
//    - no left "showcase" panel — just the login form, centered
//    - the page scrolls: below the fold there's a school-location
//      section, mission & vision, and a footer with legal links +
//      a "Report an Issue" form
//    - the login form itself is now CSRF-protected
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/audit.php';
require_once 'includes/csrf.php';

if (isset($_SESSION['role'])) {
    header("Location: " . match ($_SESSION['role']) {
        'teacher' => '/classroomv2/teacher/dashboard.php',
        'admin'   => '/classroomv2/admin/dashboard.php',
        default   => '/classroomv2/student/dashboard.php',
    });
    exit;
}

$error = '';
$notice = isset($_GET['reset']) ? "Your password was updated. Please sign in with your new password." : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired. Please try signing in again.";
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username===''||$password==='') {
        $error = "Please enter both your Student ID / username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id,username,password,role,student_id FROM users WHERE username=?");
        $stmt->bind_param("s",$username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user && password_verify($password,$user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['student_id']= $user['student_id'];
            audit_log($conn, 'login', 'success', [
                'user_id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'],
            ]);
            header("Location: " . match ($user['role']) {
                'teacher' => '/classroomv2/teacher/dashboard.php',
                'admin'   => '/classroomv2/admin/dashboard.php',
                default   => '/classroomv2/student/dashboard.php',
            });
            exit;
        } else {
            audit_log($conn, 'login', 'failure', [
                'user_id'  => $user['id']   ?? null,
                'username' => $username,
                'role'     => $user['role'] ?? null,
                'reason'   => $user ? 'wrong_password' : 'unknown_user',
            ]);
            $error = "Invalid Student ID / username or password.";
        }
    }
    }
}

// ── Easy-to-edit school info — update these to your actual details ──
$SCHOOL_NAME     = "College of Maasin";
$SCHOOL_ADDRESS  = "Maasin City, Southern Leyte, Philippines";
$SCHOOL_LAT      = "10.134692065956623";
$SCHOOL_LNG      = "124.83862468701554";
$SCHOOL_MAP_QUERY = urlencode($SCHOOL_LAT . ',' . $SCHOOL_LNG);
$SCHOOL_EMAIL    = "collegeofmaasin1925@yahoo.com";
$SCHOOL_PHONE    = "00000000000";
$DEV_EMAIL       = "anthonyvdances@gmail.com";
$DEV_PHONE       = "00000000000";
$SCHOOL_MISSION  = "The College of Maasin, with the dynamic integration of instruction, research, "
                  . "and extension, commits itself to seek a life of faith, learning and action to "
                  . "develop people into becoming God-loving citizens with integrity in character, "
                  . "intellectually competent and honest, morally and ethically sensitive, excellent "
                  . "in work performance, creatively aware and responsive to the needs and "
                  . "aspirations of people for the realization of a just, free and responsible "
                  . "Christian social order.";
$SCHOOL_VISION   = "The College of Maasin, as a dynamic learning institution, commits its life "
                  . "resources and ministry toward the development of persons, nurtured by faith "
                  . "in God and the liberating process of excellent learning toward holistic and "
                  . "creative action for social renewal.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="assets/images/TCM logo (2).png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Sign In — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">

  <style>
    :root{
      --school-bg:  #1e5f4e;
      --school-text: #FFFFFF;
      --school-text6: #050505ec;
      --school-text7: #6B7280;
      --school-bg5: #FFFFFF;

      --muted-on-dark: rgba(255,255,255,.75);
      --border-on-light: color-mix(in srgb, var(--school-text7) 30%, transparent);
      --surface-tint: color-mix(in srgb, var(--school-bg5) 93%, var(--school-text7) 7%);

      --role-default:#1e5f4e;
      --role-student: #00c22a;
      --role-instructor: #E3A857;
      --role-admin: #d42937;
      --role-accent: var(--role-default);
    }

    *{box-sizing:border-box;}
    html{scroll-behavior:smooth;}

    body.page-login{
      margin:0;
      background:var(--school-bg5);
      color:var(--school-text6);
      font-family:'DM Sans',sans-serif;
    }

    /* ---------- HERO (login only, no showcase panel) ---------- */
    .hero{
      min-height:100vh;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      padding:48px 24px;
      background:var(--school-bg);
      color:var(--school-text);
      position:relative;
      isolation:isolate;
    }
    .hero::before{
      content:'';
      position:absolute;inset:0;z-index:-1;
      background: #fffefed0;
    }

    .scroll-hint{
      margin-top:34px;
      display:flex;flex-direction:column;align-items:center;gap:4px;
      color:var(--muted-on-dark);
      font-size:12px;letter-spacing:.08em;text-transform:uppercase;
      text-decoration:none;
    }
    .scroll-hint i{font-size:20px;animation:bob 1.8s ease-in-out infinite;}
    @keyframes bob{0%,100%{transform:translateY(0);}50%{transform:translateY(5px);}}

    /* ---------- AUTH CARD ---------- */
    .auth-card{width:100%;max-width:380px;background:var(--school-bg5);color:var(--school-text6);
      border-radius:18px;padding:36px 32px;box-shadow:0 24px 60px rgba(0,0,0,.35);}

    .auth-card .kicker{font-size:12px;font-weight:500;letter-spacing:.14em;text-transform:uppercase;
      color:var(--school-text7);margin-bottom:8px;}
    .auth-card h2{font-family:'Syne',sans-serif;font-size:26px;font-weight:700;margin:0 0 28px;color:var(--school-text6);}

    .role-strip{display:flex;gap:8px;margin-bottom:28px;}
    .role-pill{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 6px;
      border-radius:12px;border:1px solid var(--border-on-light);background:var(--school-bg5);
      color:var(--school-text7);cursor:pointer;font-family:'DM Sans',sans-serif;font-size:12.5px;font-weight:500;
      transition:border-color .3s ease, background .3s ease, color .3s ease, transform .3s ease;}
    .role-pill i{font-size:19px;transition:color .3s ease;}
    .role-pill:hover,.role-pill:focus-visible{color:var(--school-text6);border-color:var(--pill-color, var(--role-default));
      background:var(--surface-tint);transform:translateY(-2px);outline:none;}
    .role-pill:hover i,.role-pill:focus-visible i{color:var(--pill-color, var(--role-default));}
    .role-pill[data-role="student"]{--pill-color:var(--role-student);}
    .role-pill[data-role="instructor"]{--pill-color:var(--role-instructor);}
    .role-pill[data-role="admin"]{--pill-color:var(--role-admin);}

    .auth-card form{display:flex;flex-direction:column;gap:18px;}
    .form-group label{display:block;font-size:12.5px;font-weight:500;color:var(--school-text7);margin-bottom:7px;}
    .input-wrap{position:relative;display:flex;align-items:center;gap:10px;background:var(--surface-tint);
      border:1px solid var(--border-on-light);border-radius:10px;padding:12px 14px;transition:border-color .25s ease;}
    .input-wrap:focus-within{border-color:var(--school-bg);}
    .input-wrap i{color:var(--school-text7);font-size:17px;}
    .input-wrap input{flex:1;background:transparent;border:none;outline:none;color:var(--school-text6);
      font-family:'DM Sans',sans-serif;font-size:14.5px;}
    .input-wrap input::placeholder{color:color-mix(in srgb, var(--school-text7) 75%, transparent);}
    #pw-input{padding-right:30px;}
    .pw-toggle-btn{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;
      color:var(--school-text7);cursor:pointer;display:flex;align-items:center;padding:0;z-index:2;}
    .pw-toggle-btn i{font-size:17px;}
    .pw-toggle-btn:hover{color:var(--school-text6);}

    .btn-login{margin-top:6px;display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;
      border-radius:10px;border:none;background:var(--school-bg);color:var(--school-text);font-family:'DM Sans',sans-serif;
      font-weight:600;font-size:14.5px;cursor:pointer;transition:filter .2s ease, transform .2s ease;}
    .btn-login:hover{filter:brightness(1.12);transform:translateY(-1px);}

    .alert{display:flex;align-items:center;gap:8px;background:rgba(224,97,109,.1);border:1px solid rgba(224,97,109,.35);
      color:#B3232E;padding:11px 14px;border-radius:10px;font-size:13.5px;margin-bottom:20px;}
    .alert.ok{background:rgba(30,95,78,.1);border-color:rgba(30,95,78,.35);color:#175040;}
    .forgot-row{text-align:right;margin-top:-6px;}
    .forgot-row a{font-size:12.5px;color:var(--school-text7);text-decoration:none;}
    .forgot-row a:hover{color:var(--school-bg);text-decoration:underline;}

    /* honeypot — hidden from real users, left reachable for bots */
    .hp-field{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}

    /* ---------- PUBLIC SECTIONS BELOW THE FOLD ---------- */
    .pub-section{padding:72px 24px;}
    .pub-wrap{max-width:980px;margin:0 auto;}
    .pub-eyebrow{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--school-bg);
      margin-bottom:10px;}
    .pub-section h2{font-family:'Syne',sans-serif;font-size:clamp(24px,3vw,32px);margin:0 0 14px;}
    .pub-section p{line-height:1.7;color:var(--school-text7);max-width:680px;}

    .visit-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:40px;align-items:start;margin-top:28px;}
    .visit-details{display:flex;flex-direction:column;gap:16px;}
    .visit-row{display:flex;gap:14px;align-items:flex-start;}
    .visit-row i{font-size:20px;color:var(--school-bg);margin-top:2px;flex-shrink:0;}
    .visit-row strong{display:block;font-size:14.5px;margin-bottom:2px;}
    .visit-row span{color:var(--school-text7);font-size:14px;line-height:1.5;}
    .map-frame{border-radius:16px;overflow:hidden;border:1px solid var(--border-on-light);
      box-shadow:0 10px 30px rgba(0,0,0,.08);aspect-ratio:4/3;}
    .map-frame iframe{width:100%;height:100%;border:0;display:block;}

    .pub-section.alt{background:var(--surface-tint);}
    .mv-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;margin-top:28px;}
    .mv-card{background:var(--school-bg5);border:1px solid var(--border-on-light);border-radius:16px;padding:28px 26px;}
    .mv-card .mv-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:21px;
      color:var(--school-bg);background:color-mix(in srgb, var(--school-bg) 12%, transparent);margin-bottom:16px;}
    .mv-card h3{font-family:'Syne',sans-serif;font-size:18px;margin:0 0 10px;}
    .mv-card p{margin:0;font-size:14.5px;color:var(--school-text7);}

    /* ---------- FOOTER ---------- */
    .site-footer{background:#0f4437;color:rgba(255,255,255,.82);padding:52px 24px 28px;}
    .footer-grid{max-width:980px;margin:0 auto;display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:36px;}
    .footer-brand{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
    .footer-brand img{width:34px;height:34px;border-radius:8px;}
    .footer-brand strong{font-family:'Syne',sans-serif;font-size:15.5px;}
    .footer-grid p{font-size:13px;line-height:1.6;color:rgba(255,255,255,.6);max-width:280px;}
    .footer-col h4{font-size:12.5px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
      color:rgba(255,255,255,.55);margin:0 0 14px;}
    .footer-col ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px;}
    .footer-col a,.footer-col button.linklike{color:rgba(255,255,255,.82);text-decoration:none;font-size:13.5px;
      background:none;border:none;padding:0;cursor:pointer;font-family:'DM Sans',sans-serif;text-align:left;}
    .footer-col a:hover,.footer-col button.linklike:hover{color:#fff;text-decoration:underline;}
    .footer-bottom{max-width:980px;margin:36px auto 0;padding-top:20px;border-top:1px solid rgba(255,255,255,.1);
      font-size:12.5px;color:rgba(255,255,255,.5);display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;}

    /* ---------- REPORT-AN-ISSUE MODAL ---------- */
    .modal-overlay{position:fixed;inset:0;background:rgba(5,20,16,.55);display:none;align-items:center;
      justify-content:center;padding:20px;z-index:1000;}
    .modal-overlay.is-open{display:flex;}
    .modal-box{width:100%;max-width:440px;background:#fff;color:var(--school-text6);border-radius:16px;
      padding:28px 26px;box-shadow:0 30px 70px rgba(0,0,0,.4);max-height:90vh;overflow-y:auto;}
    .modal-box h3{font-family:'Syne',sans-serif;font-size:19px;margin:0 0 4px;}
    .modal-box .modal-sub{font-size:13px;color:var(--school-text7);margin:0 0 20px;}
    .modal-box .form-group{margin-bottom:14px;}
    .modal-box label{display:block;font-size:12.5px;font-weight:500;color:var(--school-text7);margin-bottom:6px;}
    .modal-box select,.modal-box textarea,.modal-box input[type=text],.modal-box input[type=email]{
      width:100%;padding:10px 12px;border-radius:9px;border:1px solid var(--border-on-light);
      font-family:'DM Sans',sans-serif;font-size:13.5px;background:var(--surface-tint);color:var(--school-text6);}
    .modal-box textarea{resize:vertical;min-height:90px;}
    .modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;}
    .btn-ghost{padding:10px 16px;border-radius:9px;border:1px solid var(--border-on-light);background:none;
      color:var(--school-text7);font-size:13.5px;cursor:pointer;}
    .btn-primary{padding:10px 18px;border-radius:9px;border:none;background:var(--school-bg);color:#fff;
      font-size:13.5px;font-weight:600;cursor:pointer;}
    .btn-primary:disabled{opacity:.6;cursor:default;}
    .modal-msg{font-size:13px;padding:10px 12px;border-radius:9px;margin-bottom:14px;display:none;}
    .modal-msg.ok{display:block;background:rgba(30,95,78,.1);color:#175040;}
    .modal-msg.err{display:block;background:rgba(224,97,109,.1);color:#B3232E;}

    @media (max-width:760px){
      .visit-grid,.mv-grid{grid-template-columns:1fr;}
      .footer-grid{grid-template-columns:1fr;}
      .footer-bottom{flex-direction:column;}
    }
    @media (prefers-reduced-motion:reduce){ html{scroll-behavior:auto;} .scroll-hint i{animation:none;} }
  </style>
</head>
<body class="page-login">

  <!-- ============== HERO: login form only ============== -->
  <section class="hero" id="top">
    <div class="auth-card">
      <div style="text-align:center;">
        <img src="assets/images/TCM logo (2).png" alt="TCM Logo" width="96" height="96" style="display:block;margin:0 auto 16px;">
      </div>
      <div class="kicker">Welcome back</div>
      <h2>Sign in to your account</h2>

      <?php if ($error): ?>
      <div class="alert"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($notice): ?>
      <div class="alert ok"><i class="ti ti-circle-check"></i> <?php echo htmlspecialchars($notice); ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-group">
          <label>Student ID / Username</label>
          <div class="input-wrap">
            <i class="ti ti-user"></i>
            <input type="text" name="username" placeholder="Students: enter your Student ID"
              value="<?php echo htmlspecialchars($_POST['username']??''); ?>" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label>Password</label>
          <div class="input-wrap">
            <i class="ti ti-lock"></i>
            <input type="password" name="password" id="pw-input" placeholder="Enter your password" required>
            <button type="button" id="pw-toggle" class="pw-toggle-btn" aria-label="Show password">
              <i id="pw-icon" class="ti ti-eye"></i>
            </button>
          </div>
        </div>
        <div class="forgot-row"><a href="forgot_password.php">Forgot password?</a></div>
        <button type="submit" class="btn-login"><i class="ti ti-login"></i> Sign In</button>
      </form>
    </div>

    <a href="#visit" class="scroll-hint"><span style="color: var(--school-text6);">Learn more about <?php echo htmlspecialchars($SCHOOL_NAME); ?></span><i class="ti ti-chevron-down" style="color: var(--school-text6);"></i></a>
  </section>

  <!-- ============== VISIT US ============== -->
  <section class="pub-section" id="visit">
    <div class="pub-wrap">
      <div class="pub-eyebrow">Find us</div>
      <h2>Visit <?php echo htmlspecialchars($SCHOOL_NAME); ?></h2>
      <p>Stop by during school hours — our administrative office can help with enrollment, records requests, and general inquiries.</p>
      <div class="visit-grid">
        <div class="visit-details">
          <div class="visit-row">
            <i class="ti ti-map-pin"></i>
            <div><strong>Address</strong><span><?php echo htmlspecialchars($SCHOOL_ADDRESS); ?></span></div>
          </div>
          <div class="visit-row">
            <i class="ti ti-clock"></i>
            <div><strong>Office Hours</strong><span>Monday – Friday, 8:00 AM – 5:00 PM</span></div>
          </div>
          <div class="visit-row">
            <i class="ti ti-mail"></i>
            <div><strong>Email</strong><span><?php echo htmlspecialchars($SCHOOL_EMAIL); ?></span></div>
          </div>
          <div class="visit-row">
            <i class="ti ti-phone"></i>
            <div><strong>Phone</strong><span><?php echo htmlspecialchars($SCHOOL_PHONE); ?></span></div>
          </div>
        </div>
        <div class="map-frame">
          <iframe
            src="https://www.google.com/maps?q=<?php echo $SCHOOL_MAP_QUERY; ?>&output=embed"
            loading="lazy" referrerpolicy="no-referrer-when-downgrade"
            title="Map showing <?php echo htmlspecialchars($SCHOOL_NAME); ?>"></iframe>
        </div>
      </div>
    </div>
  </section>

  <!-- ============== MISSION & VISION ============== -->
  <section class="pub-section alt" id="about">
    <div class="pub-wrap">
      <div class="pub-eyebrow">Who we are</div>
      <h2>Our Mission &amp; Vision</h2>
      <div class="mv-grid">
        <div class="mv-card">
          <h3>Mission</h3>
          <p><?php echo htmlspecialchars($SCHOOL_MISSION); ?></p>
        </div>
        <div class="mv-card">
          <h3>Vision</h3>
          <p><?php echo htmlspecialchars($SCHOOL_VISION); ?></p>
        </div>
      </div>
    </div>
  </section>

  <!-- ============== FOOTER ============== -->
  <footer class="site-footer">
    <div class="footer-grid">
      <div>
        <div class="footer-brand">
          <img src="assets/images/TCM logo (2).png" alt="TCM logo">
          <strong><?php echo htmlspecialchars($SCHOOL_NAME); ?></strong>
        </div>
        <p>Classroom Management System — grades, attendance, and class records in one secure place for students, teachers, and administrators.</p>
      </div>
      <div class="footer-col">
        <h4>Legal</h4>
        <ul>
          <li><a href="/classroomv2/privacy.php">Privacy Policy</a></li>
          <li><a href="/classroomv2/terms.php">Terms &amp; Conditions</a></li>
          <li><a href="/classroomv2/cookie_policy.php">Cookie Policy</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Support</h4>
        <ul>
          <li><a href="forgot_password.php">Forgot password</a></li>
          <li><button type="button" class="linklike" id="openReportModal">Report an issue</button></li>
          <li><a href="mailto:<?php echo htmlspecialchars($SCHOOL_EMAIL); ?>"><?php echo htmlspecialchars($SCHOOL_EMAIL); ?></a></li>
          <li><a href="tel:<?php echo htmlspecialchars($SCHOOL_PHONE); ?>"><?php echo htmlspecialchars($SCHOOL_PHONE); ?></a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span><?php echo htmlspecialchars($SCHOOL_NAME); ?> Classroom Management System &middot; <?php echo date('Y'); ?></span>
      <span>System support: <a href="mailto:<?php echo htmlspecialchars($DEV_EMAIL); ?>" style="color:inherit;"><?php echo htmlspecialchars($DEV_EMAIL); ?></a> &middot; <?php echo htmlspecialchars($DEV_PHONE); ?></span>
      <a href="#top" style="color:inherit;">Back to top ↑</a>
    </div>
  </footer>

  <!-- ============== REPORT AN ISSUE MODAL ============== -->
  <div class="modal-overlay" id="reportOverlay" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
    <div class="modal-box">
      <h3 id="reportTitle">Report an Issue</h3>
      <p class="modal-sub">Having trouble signing in or spotted a bug? Let us know and the system administrator will follow up.</p>

      <div class="modal-msg" id="reportMsg"></div>

      <form id="reportForm">
        <?php echo csrf_field(); ?>
        <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off">
        <input type="hidden" name="page" value="loginv2.php">

        <div class="form-group">
          <label>Category</label>
          <select name="category">
            <option value="login">Can't sign in</option>
            <option value="bug">Something looks broken</option>
            <option value="account">Account / record issue</option>
            <option value="performance">Slow or unresponsive</option>
            <option value="other" selected>Other</option>
          </select>
        </div>
        <div class="form-group">
          <label>Your name (optional)</label>
          <input type="text" name="name" placeholder="So we know who to follow up with">
        </div>
        <div class="form-group">
          <label>Your email (optional, for a reply)</label>
          <input type="email" name="email" placeholder="you@example.com">
        </div>
        <div class="form-group">
          <label>What happened?</label>
          <textarea name="message" required placeholder="Describe the issue — what you expected, and what happened instead."></textarea>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-ghost" id="closeReportModal">Cancel</button>
          <button type="submit" class="btn-primary" id="reportSubmitBtn">Send Report</button>
        </div>
      </form>
    </div>
  </div>

<script>
(function(){
  const pw  = document.getElementById('pw-input');
  const ic  = document.getElementById('pw-icon');
  const btn = document.getElementById('pw-toggle');
  if(!pw||!ic||!btn) return;
  btn.addEventListener('click', function(e){
    e.preventDefault();
    if(pw.type==='password'){ pw.type='text'; ic.className='ti ti-eye-off'; btn.setAttribute('aria-label','Hide password'); }
    else { pw.type='password'; ic.className='ti ti-eye'; btn.setAttribute('aria-label','Show password'); }
  });
})();

(function(){
  // Role pills here are just a visual hint (no showcase panel to drive
  // anymore) — kept for the hover color accent on the card border.
  const pills = document.querySelectorAll('.role-pill');
  pills.forEach(pill=>{
    const role = pill.dataset.role;
    pill.addEventListener('mouseenter', ()=>{
      document.querySelector('.auth-card').style.setProperty('--role-accent', 'var(--role-'+role+')');
    });
  });
})();

(function(){
  const overlay = document.getElementById('reportOverlay');
  const openBtn = document.getElementById('openReportModal');
  const closeBtn = document.getElementById('closeReportModal');
  const form = document.getElementById('reportForm');
  const msg = document.getElementById('reportMsg');
  const submitBtn = document.getElementById('reportSubmitBtn');
  if(!overlay||!openBtn) return;

  function open(){ overlay.classList.add('is-open'); msg.className='modal-msg'; msg.textContent=''; }
  function close(){ overlay.classList.remove('is-open'); }

  openBtn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', (e)=>{ if(e.target === overlay) close(); });
  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape' && overlay.classList.contains('is-open')) close(); });

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending...';
    try {
      const res = await fetch('api/report_issue.php', { method:'POST', body:new FormData(form) });
      const data = await res.json();
      if (data.status === 'ok') {
        msg.className = 'modal-msg ok';
        msg.textContent = data.message || 'Thanks — your report was sent.';
        form.reset();
        setTimeout(close, 1800);
      } else {
        msg.className = 'modal-msg err';
        msg.textContent = data.message || 'Something went wrong — please try again.';
      }
    } catch (err) {
      msg.className = 'modal-msg err';
      msg.textContent = 'Network error — please check your connection and try again.';
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Send Report';
    }
  });
})();
</script>
<?php include __DIR__ . '/includes/cookie_notice.php'; ?>
</body>
</html>