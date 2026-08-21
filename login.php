<?php
session_start();
require_once 'config/db.php';

if (isset($_SESSION['role'])) {
    header("Location: " . match ($_SESSION['role']) {
        'teacher' => '/classroomv2/teacher/dashboard.php',
        'admin'   => '/classroomv2/admin/dashboard.php',
        default   => '/classroomv2/student/dashboard.php',
    });
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username===''||$password==='') {
        $error = "Please enter both username and password.";
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
            header("Location: " . match ($user['role']) {
                'teacher' => '/classroomv2/teacher/dashboard.php',
                'admin'   => '/classroomv2/admin/dashboard.php',
                default   => '/classroomv2/student/dashboard.php',
            });
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
}
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
  <!-- <link rel="stylesheet" href="/classroomv2/assets/style.css"> -->

  <style>
    :root{
      /* school brand tokens — pulled from the site's own variables */
      --school-bg:  var(--bg, #1e5f4e);   /* school green */
      --school-text: var(--text, #FFFFFF); /* white */
      --school-text6: var(--text6, #000000); /* black */
      --school-text7: var(--text7, #6B7280); /* grey */
      --school-bg5: var(--bg5, #FFFFFF);  /* white */

      --muted-on-dark: rgba(255,255,255,.75);
      --border-on-dark: rgba(255,255,255,.18);
      --border-on-light: color-mix(in srgb, var(--school-text7) 30%, transparent);
      --surface-tint: color-mix(in srgb, var(--school-bg5) 93%, var(--school-text7) 7%);

      --role-default:#1e5f4e;
      --role-student: #00c22a;
      --role-instructor: #E3A857;
      --role-admin: #d42937;

      --role-accent: var(--role-default);
    }

    *{box-sizing:border-box;}

    body.page-login{
      margin:0;
      min-height:100vh;
      background:var(--school-bg);
      color:var(--school-text);
      font-family:'DM Sans',sans-serif;
      display:flex;
      align-items:stretch;
      justify-content:center;
    }

    .auth-shell{
      width:100%;
      min-height:100vh;
      display:grid;
      grid-template-columns:minmax(0,3fr) minmax(0,2fr);
    }

    /* ---------- LEFT SHOWCASE PANEL ---------- */
   .showcase-panel{
      position:relative;
      overflow:hidden;
      background:var(--school-bg);
      display:flex;
      flex-direction:column;
      padding:56px 56px 64px;
      transition:box-shadow .6s ease;
    }
 
    .showcase-bg{position:absolute;inset:0;z-index:0;}
    .bg-layer{
      position:absolute;inset:-10%;
      opacity:0;
      transition:opacity .75s cubic-bezier(.4,0,.2,1);
    }
    .bg-layer[data-role="default"]{
      --layer-color:var(--role-default);
      opacity:1;
      background:
        radial-gradient(circle at 18% 12%, color-mix(in srgb, var(--layer-color) 55%, transparent) 0%, transparent 42%),
        radial-gradient(circle at 85% 88%, color-mix(in srgb, var(--layer-color) 35%, transparent) 0%, transparent 50%);
    }
    .bg-layer[data-role="student"]{
      --layer-color:var(--role-student);
      background:
        radial-gradient(circle at 12% 85%, color-mix(in srgb, var(--layer-color) 55%, transparent) 0%, transparent 42%),
        radial-gradient(circle at 78% 15%, color-mix(in srgb, var(--layer-color) 35%, transparent) 0%, transparent 50%);
    }
    .bg-layer[data-role="instructor"]{
      --layer-color:var(--role-instructor);
      background:
        radial-gradient(circle at 88% 10%, color-mix(in srgb, var(--layer-color) 55%, transparent) 0%, transparent 42%),
        radial-gradient(circle at 15% 75%, color-mix(in srgb, var(--layer-color) 35%, transparent) 0%, transparent 50%);
    }
    .bg-layer[data-role="admin"]{
      --layer-color:var(--role-admin);
      background:
        radial-gradient(circle at 50% 92%, color-mix(in srgb, var(--layer-color) 55%, transparent) 0%, transparent 42%),
        radial-gradient(circle at 82% 25%, color-mix(in srgb, var(--layer-color) 35%, transparent) 0%, transparent 50%);
    }
    .bg-layer.is-active{opacity:1;}
     .showcase-noise{
      position:absolute;inset:0;z-index:1;
      background-image:radial-gradient(rgba(255,255,255,.035) 1px, transparent 1px);
      background-size:3px 3px;
      pointer-events:none;
      mix-blend-mode:overlay;
    }

    .showcase-inner{position:relative;z-index:2;height:100%;}

    /* idle state: shown only when no role is hovered — big centered logo + title */
    .showcase-idle{
      position:absolute;
      inset:0;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      text-align:center;
      pointer-events:none;
    }
    .idle-logo{
      width:128px;
      height:auto;
      display:block;
      margin-bottom:24px;
      filter:drop-shadow(0 4px 18px rgba(0,0,0,.35));
    }
    .idle-title{
      font-family:'Syne',sans-serif;
      font-weight:800;
      font-size:clamp(26px,2.7vw,36px);
      line-height:1.24;
      max-width:340px;
      letter-spacing:.005em;
    }

    .showcase-idle > *{
      opacity:0;
      transform:translateY(16px);
      filter:blur(6px);
      transition:opacity .5s cubic-bezier(.22,1,.36,1), transform .5s cubic-bezier(.22,1,.36,1), filter .5s cubic-bezier(.22,1,.36,1);
    }
    .showcase-idle.is-active > *{opacity:1;transform:translateY(0);filter:blur(0);}
    .showcase-idle.is-active > *:nth-child(1){transition-delay:.05s;}
    .showcase-idle.is-active > *:nth-child(2){transition-delay:.14s;}
    .showcase-idle.is-leaving > *{
      transition-delay:0s !important;
      opacity:0;
      transform:translateY(-14px);
      filter:blur(9px);
      transition-duration:.28s;
    }

    /* hover state: role content, larger, sitting in the upper portion of the panel */
    .showcase-stage{
      position:absolute;
      inset:0;
      padding-top:9%;
    }

    .showcase-slide{
      position:absolute;
      left:0;right:0;top:0;
      pointer-events:none;
    }
    .showcase-slide.is-active{pointer-events:auto;}

    .showcase-slide .eyebrow{
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-family:'DM Sans',sans-serif;
      font-size:13px;
      font-weight:500;
      letter-spacing:.14em;
      text-transform:uppercase;
      color:var(--role-accent);
      transition:color .5s ease;
      margin-bottom:16px;
    }
    .showcase-slide .eyebrow i{font-size:16px;}

    .showcase-slide h1{
      font-family:'Syne',sans-serif;
      font-weight:800;
      font-size:clamp(32px,3.6vw,46px);
      line-height:1.1;
      margin:0 0 20px;
      letter-spacing:-.01em;
    }

    .feature-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:13px;max-width:400px;}
    .feature-list li{
      display:flex;
      align-items:flex-start;
      gap:10px;
      font-size:16px;
      line-height:1.5;
      color:var(--muted-on-dark);
    }
    .feature-list li i{
      color:var(--role-accent);
      transition:color .5s ease;
      font-size:18px;
      margin-top:1px;
      flex-shrink:0;
    }

    /* animated children: rise + blur, staggered on enter, snap-out on leave */
    .showcase-slide > *{
      opacity:0;
      transform:translateY(16px);
      filter:blur(6px);
      transition:opacity .45s cubic-bezier(.22,1,.36,1), transform .45s cubic-bezier(.22,1,.36,1), filter .45s cubic-bezier(.22,1,.36,1);
    }
    .showcase-slide.is-active > *{opacity:1;transform:translateY(0);filter:blur(0);}
    .showcase-slide.is-active > *:nth-child(1){transition-delay:.05s;}
    .showcase-slide.is-active > *:nth-child(2){transition-delay:.12s;}
    .showcase-slide.is-active > *:nth-child(3){transition-delay:.2s;}

    .showcase-slide .feature-list li{
      opacity:0;transform:translateY(12px);filter:blur(5px);
      transition:opacity .4s cubic-bezier(.22,1,.36,1), transform .4s cubic-bezier(.22,1,.36,1), filter .4s cubic-bezier(.22,1,.36,1);
    }
    .showcase-slide.is-active .feature-list li{opacity:1;transform:translateY(0);filter:blur(0);}
    .showcase-slide.is-active .feature-list li:nth-child(1){transition-delay:.26s;}
    .showcase-slide.is-active .feature-list li:nth-child(2){transition-delay:.33s;}
    .showcase-slide.is-active .feature-list li:nth-child(3){transition-delay:.4s;}

    .showcase-slide.is-leaving > *,
    .showcase-slide.is-leaving .feature-list li{
      transition-delay:0s !important;
      opacity:0;
      transform:translateY(-14px);
      filter:blur(9px);
      transition-duration:.28s;
    }

    /* ---------- RIGHT AUTH PANEL ---------- */
    .auth-panel{
      display:flex;
      align-items:center;
      justify-content:center;
      padding:40px 32px;
      background:var(--school-bg5);
      color:var(--school-text6);
    }

    .auth-card{width:100%;max-width:380px;}

    .auth-card .kicker{
      font-size:12px;
      font-weight:500;
      letter-spacing:.14em;
      text-transform:uppercase;
      color:var(--school-text7);
      margin-bottom:8px;
    }
    .auth-card h2{
      font-family:'Syne',sans-serif;
      font-size:26px;
      font-weight:700;
      margin:0 0 28px;
      color:var(--school-text6);
    }

    .role-strip{display:flex;gap:8px;margin-bottom:28px;}
    .role-pill{
      flex:1;
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:6px;
      padding:12px 6px;
      border-radius:12px;
      border:1px solid var(--border-on-light);
      background:var(--school-bg5);
      color:var(--school-text7);
      cursor:pointer;
      font-family:'DM Sans',sans-serif;
      font-size:12.5px;
      font-weight:500;
      transition:border-color .3s ease, background .3s ease, color .3s ease, transform .3s ease;
    }
    .role-pill i{font-size:19px;transition:color .3s ease;}
    .role-pill:hover,
    .role-pill:focus-visible{
      color:var(--school-text6);
      border-color:var(--pill-color, var(--role-default));
      background:var(--surface-tint);
      transform:translateY(-2px);
      outline:none;
    }
    .role-pill:hover i,
    .role-pill:focus-visible i{color:var(--pill-color, var(--role-default));}
    .role-pill[data-role="student"]{--pill-color:var(--role-student);}
    .role-pill[data-role="instructor"]{--pill-color:var(--role-instructor);}
    .role-pill[data-role="admin"]{--pill-color:var(--role-admin);}

    .auth-card form{display:flex;flex-direction:column;gap:18px;}

    .form-group label{
      display:block;
      font-size:12.5px;
      font-weight:500;
      color:var(--school-text7);
      margin-bottom:7px;
    }
    .input-wrap{
      position:relative;
      display:flex;
      align-items:center;
      gap:10px;
      background:var(--surface-tint);
      border:1px solid var(--border-on-light);
      border-radius:10px;
      padding:12px 14px;
      transition:border-color .25s ease;
    }
    .input-wrap:focus-within{border-color:var(--school-bg);}
    .input-wrap i{color:var(--school-text7);font-size:17px;}
    .input-wrap input{
      flex:1;
      background:transparent;
      border:none;
      outline:none;
      color:var(--school-text6);
      font-family:'DM Sans',sans-serif;
      font-size:14.5px;
    }
    .input-wrap input::placeholder{color:color-mix(in srgb, var(--school-text7) 75%, transparent);}
    #pw-input{padding-right:30px;}
    .pw-toggle-btn{
      position:absolute;
      right:14px;
      top:50%;
      transform:translateY(-50%);
      background:none;
      border:none;
      color:var(--school-text7);
      cursor:pointer;
      display:flex;
      align-items:center;
      padding:0;
      z-index:2;
    }
    .pw-toggle-btn i{font-size:17px;}
    .pw-toggle-btn:hover{color:var(--school-text6);}

    .btn-login{
      margin-top:6px;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding:13px;
      border-radius:10px;
      border:none;
      background:var(--school-bg);
      color:var(--school-text);
      font-family:'DM Sans',sans-serif;
      font-weight:600;
      font-size:14.5px;
      cursor:pointer;
      transition:filter .2s ease, transform .2s ease;
    }
    .btn-login:hover{filter:brightness(1.12);transform:translateY(-1px);}

    .alert{
      display:flex;align-items:center;gap:8px;
      background:rgba(224,97,109,.1);
      border:1px solid rgba(224,97,109,.35);
      color:#B3232E;
      padding:11px 14px;
      border-radius:10px;
      font-size:13.5px;
      margin-bottom:20px;
    }

    .auth-footer{margin-top:28px;font-size:12px;color:var(--school-text7);text-align:center;}

    /* ---------- RESPONSIVE ---------- */
    @media (max-width:920px){
      .auth-shell{grid-template-columns:1fr;}
      .showcase-panel{min-height:280px;padding:32px 28px;}
      .idle-logo{width:72px;margin-bottom:14px;}
      .idle-title{font-size:20px;max-width:260px;}
      .showcase-stage{padding-top:6%;}
      .showcase-slide h1{font-size:24px;}
      .feature-list{display:none;}
      .auth-panel{padding:32px 24px 48px;}
    }

    @media (prefers-reduced-motion:reduce){
      .bg-layer,
      .showcase-idle > *,
      .showcase-slide > *,
      .showcase-slide .feature-list li{transition:opacity .2s linear !important;transform:none !important;filter:none !important;transition-delay:0s !important;}
    }
  </style>
</head>
<body class="page-login">

<div class="auth-shell">

  <!-- LEFT: showcase panel -->
  <div class="showcase-panel" id="showcasePanel">
    <div class="showcase-bg" id="showcaseBg">
      <span class="bg-layer is-active" data-role="default"></span>
      <span class="bg-layer" data-role="student"></span>
      <span class="bg-layer" data-role="instructor"></span>
      <span class="bg-layer" data-role="admin"></span>
    </div>
    <div class="showcase-noise"></div>

    <div class="showcase-inner">
      <div class="showcase-idle is-active" id="showcaseIdle">
        <img class="idle-logo" src="assets/images/TCM logo (2).png" alt="TCM Logo">
        <div class="idle-title">TCM Classroom<br>Management System</div>
      </div>

      <div class="showcase-stage" id="showcaseStage">

        <div class="showcase-slide" data-role="student">
          <span class="eyebrow"><i class="ti ti-backpack"></i> For Students</span>
          <h1>Track your progress<br>at a glance.</h1>
          <ul class="feature-list">
            <li><i class="ti ti-chart-bar"></i> Check grades in real time.</li>
            <li><i class="ti ti-eye"></i> View all outputs -- Quiz, Exam, Attendance, Activity</li>
            <!-- <li><i class="ti ti-calendar-event"></i> See section schedules and requirements.</li> -->
          </ul>
        </div>

        <div class="showcase-slide" data-role="instructor">
          <span class="eyebrow"><i class="ti ti-chalkboard"></i> For Instructors</span>
          <h1>Run your classroom<br>on the cloud</h1>
          <ul class="feature-list">
            <li><i class="ti ti-clipboard-check"></i> Create subjects and manipulate grade weights. </li>
            <li><i class="ti ti-clipboard-check"></i> Record grades and manage class lists.</li>
            <li><i class="ti ti-report"></i> Generate reports across every class.</li>
            <li><i class="ti ti-fingerprint"></i> Take attendance automatically via biometric scan.</li>
            <li><i class="ti ti-report"></i> Export class records anytime.</li>
          </ul>
        </div>

        <div class="showcase-slide" data-role="admin">
          <span class="eyebrow"><i class="ti ti-shield-lock"></i> For Admins</span>
          <h1>Oversee the whole<br>system, in one place.</h1>
          <ul class="feature-list">
            <li><i class="ti ti-users"></i> Manage accounts, students, and access requests.</li>
            <li><i class="ti ti-device-desktop"></i> Monitor biometric devices and system health.</li>
            <li><i class="ti ti-file-spreadsheet"></i> Import students in bulk from CSV or Excel.</li>

          </ul>
        </div>

      </div>
    </div>
  </div>

  <!-- RIGHT: auth panel -->
  <div class="auth-panel">
    <div class="auth-card">
      <div class="kicker">Welcome back</div>
      <h2>Sign in to your account</h2>
  
      
      <?php if ($error): ?>
      <div class="alert"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <div class="role-strip" id="roleStrip">
        <button type="button" class="role-pill" data-role="student">
          <i class="ti ti-backpack" ></i> Student
        </button>
        <button type="button" class="role-pill" data-role="instructor">
          <i class="ti ti-chalkboard"></i> Instructor
        </button>
        <button type="button" class="role-pill" data-role="admin">
          <i class="ti ti-shield-lock"></i> Admin
        </button>
      </div>

      <form method="POST" autocomplete="off">
        <div class="form-group">
          <label>Username</label>
          <div class="input-wrap">
            <i class="ti ti-user"></i>
            <input type="text" name="username" placeholder="Enter your username"
              value="<?php echo htmlspecialchars($_POST['username']??''); ?>"
              required autofocus>
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
        <button type="submit" class="btn-login">
          <i class="ti ti-login"></i> Sign In
        </button>
      </form>

      <div class="auth-footer">
        TCM Classroom Management System &nbsp;·&nbsp; <?php echo date('Y'); ?>
      </div>
    </div>
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
    if(pw.type==='password'){
      pw.type='text';
      ic.className='ti ti-eye-off';
      btn.setAttribute('aria-label','Hide password');
    } else {
      pw.type='password';
      ic.className='ti ti-eye';
      btn.setAttribute('aria-label','Show password');
    }
  });
})();

(function(){
  const pills   = document.querySelectorAll('.role-pill');
  const idle    = document.getElementById('showcaseIdle');
  let currentRole = 'default';
  let leaveTimer  = null;
  let idleTimer   = null;

  function setRole(role){
    if(role === currentRole) return;
    clearTimeout(leaveTimer);
    clearTimeout(idleTimer);

    const activeSlide = document.querySelector('.showcase-slide.is-active');
    const nextSlide    = role === 'default' ? null : document.querySelector('.showcase-slide[data-role="'+role+'"]');
    const activeLayer  = document.querySelector('.bg-layer.is-active');
    const nextLayer    = document.querySelector('.bg-layer[data-role="'+role+'"]');

    if(activeSlide && activeSlide !== nextSlide){
      activeSlide.classList.remove('is-active');
      activeSlide.classList.add('is-leaving');
      leaveTimer = setTimeout(()=> activeSlide.classList.remove('is-leaving'), 320);
    }
    if(nextSlide) nextSlide.classList.add('is-active');

    if(role === 'default'){
      idle.classList.remove('is-leaving');
      idle.classList.add('is-active');
    } else if(idle.classList.contains('is-active')){
      idle.classList.remove('is-active');
      idle.classList.add('is-leaving');
      idleTimer = setTimeout(()=> idle.classList.remove('is-leaving'), 320);
    }

    if(activeLayer && activeLayer !== nextLayer) activeLayer.classList.remove('is-active');
    if(nextLayer) nextLayer.classList.add('is-active');

    document.getElementById('showcasePanel').style.setProperty('--role-accent', 'var(--role-'+role+')');
    currentRole = role;
  }

  pills.forEach(pill=>{
    const role = pill.dataset.role;
    pill.addEventListener('mouseenter', ()=> setRole(role));
    pill.addEventListener('focus', ()=> setRole(role));
  });

  document.getElementById('roleStrip').addEventListener('mouseleave', ()=> setRole('default'));
  document.getElementById('roleStrip').addEventListener('focusout', (e)=>{
    if(!e.currentTarget.contains(e.relatedTarget)) setRole('default');
  });
})();
</script>
</body>
</html>