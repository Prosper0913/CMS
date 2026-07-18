<?php
session_start();
require_once 'config/db.php';

if (isset($_SESSION['role'])) {
    header("Location: " . ($_SESSION['role']==='teacher'
        ? '/classroom/teacher/dashboard.php'
        : '/classroom/student/dashboard.php'));
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
            header("Location: " . ($user['role']==='teacher'
                ? '/classroom/teacher/dashboard.php'
                : '/classroom/student/dashboard.php'));
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Sign In — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">

</head>
<body class="page-login">
<div class="login-wrap">
  <div class="login-brand">
    <div class="brand-icon"><img src="assets/images/TCM logo (2).png" alt="TCM Logo" width="220px"></div>
    <div class="brand-title">TCM Classroom Management System</div>
    <div class="brand-sub">Sign in to your account</div>
  </div>

  <div class="login-card">
    <?php if ($error): ?>
    <div class="alert"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

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
      <div class="form-group" style="margin-bottom:24px;">
        <label>Password</label>
        <div class="input-wrap">
          <i class="ti ti-lock"></i>
          <input type="password" name="password" id="pw" placeholder="Enter your password" required>
            <button type="button" class="show-pw" onclick="togglePw()">
             &ensp;&emsp;<i class="ti ti-eye"></i> &nbsp;<i class=id="pw-icon"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn-login" style="color:var(--text2);">
        <i class="ti ti-login"></i> Sign In
      </button>
    </form>
  </div>

  <div class="login-footer">
    TCM Classroom Management System &nbsp;·&nbsp; <?php echo date('Y'); ?>
  </div>
</div>

<script>
function togglePw(){
  const pw=document.getElementById('pw');
  const ic=document.getElementById('pw-icon');
  if(pw.type==='password'){pw.type='text';ic.className='ti ti-eye-off';}
  else{pw.type='password';ic.className='ti ti-eye';}
}
</script>
</body>
</html>
