<?php
// ============================================================
//  teacher/manage_teachers.php
//  Lets any logged-in teacher create additional teacher accounts
//  and manage them. Think of it as a lightweight "admin panel"
//  for teacher accounts.
//
//  Only teachers can access this page (requireRole enforces it).
//  Any teacher can add another teacher; there's no separate admin role.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

$me          = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// ── ADD teacher ─────────────────────────────────────────────
if (isset($_POST['add_teacher'])) {
    $username     = trim($_POST['username']);
    $display_name = trim($_POST['display_name']);
    $password     = trim($_POST['password']);
    $confirm      = trim($_POST['confirm_password']);

    if ($username === '' || $password === '' || $display_name === '') {
        $error_msg = 'Username, display name, and password are all required.';
    } elseif ($password !== $confirm) {
        $error_msg = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error_msg = 'Password must be at least 6 characters.';
    } else {
        // Check duplicate
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param('s', $username);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "Username <strong>" . htmlspecialchars($username) . "</strong> is already taken.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $ins = $conn->prepare(
                "INSERT INTO users (username, password, role, display_name)
                 VALUES (?, ?, 'teacher', ?)"
            );
            $ins->bind_param('sss', $username, $hashed, $display_name);
            if ($ins->execute()) {
                $success_msg = "Teacher account <strong>" . htmlspecialchars($display_name)
                    . "</strong> created. They can log in as <code>" . htmlspecialchars($username) . "</code>.";
            } else {
                $error_msg = "Database error: " . $conn->error;
            }
        }
    }
}

// ── RESET teacher password ────────────────────────────────
if (isset($_POST['reset_password'])) {
    $tid      = (int)$_POST['teacher_id'];
    $newpass  = trim($_POST['new_password']);
    if ($tid === $me && strlen($newpass) < 6) {
        $error_msg = 'Password must be at least 6 characters.';
    } elseif ($newpass === '') {
        $error_msg = 'New password is required.';
    } else {
        $hashed = password_hash($newpass, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'teacher'");
        $upd->bind_param('si', $hashed, $tid);
        $upd->execute();
        $success_msg = "Password reset successfully.";
    }
}

// ── DELETE teacher ────────────────────────────────────────
if (isset($_GET['delete']) && (int)$_GET['delete'] !== $me) {
    $tid = (int)$_GET['delete'];
    // Verify it's a teacher, not the current user
    $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
    $chk->bind_param('i', $tid);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $del = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
        $del->bind_param('i', $tid);
        $del->execute();
        header("Location: manage_teachers.php?msg=deleted"); exit;
    }
}
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success_msg = "Teacher account deleted.";
}

// ── LOAD all teacher accounts ────────────────────────────
$teachers_res = $conn->query(
    "SELECT id, username, display_name, created_at
     FROM users WHERE role = 'teacher'
     ORDER BY display_name ASC"
);

// ── Navbar subjects ───────────────────────────────────────
$nav_subs = getTeacherSubjects($conn, $me);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Manage Teachers — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroom/assets/style.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">

</head>
<body class="page-teacher-manage_teachers">

<!-- NAVBAR -->
<?php
// Inline navbar matching existing style
$nav_subs_arr = [];
while ($ns = $nav_subs->fetch_assoc()) $nav_subs_arr[] = $ns;
?>
<nav class="navbar" style="height:56px;background:var(--bg2);border-bottom:1px solid var(--border);
  display:flex;align-items:center;padding:0 28px;gap:4px;position:sticky;top:0;z-index:100;">
  <a class="brand" href="/classroom/teacher/dashboard.php"
    style="font-family:var(--font-head);font-size:15px;font-weight:700;color:var(--text);
    text-decoration:none;display:flex;align-items:center;gap:8px;flex-shrink:0;margin-right:8px;">
    <span style="width:7px;height:7px;border-radius:50%;background:var(--accent);
      box-shadow:0 0 8px var(--accent);display:inline-block;"></span>
    Classroom CMS
  </a>
  <div style="width:1px;height:20px;background:var(--border2);margin:0 6px;"></div>
  <a href="/classroom/teacher/dashboard.php"
    style="font-size:13px;font-weight:500;color:var(--text2);text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;"
    class="nav-link"><i class="ti ti-layout-dashboard"></i> Dashboard</a>
  <a href="/classroom/teacher/manage_sections.php"
    style="font-size:13px;font-weight:500;color:var(--text2);text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;"
    class="nav-link"><i class="ti ti-building-community"></i> Sections</a>
  <a href="/classroom/teacher/manage_teachers.php"
    style="font-size:13px;font-weight:500;background:var(--bg3);color:var(--text);text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;"
    class="nav-link active"><i class="ti ti-user-star"></i> Teachers</a>
  <a href="/classroom/teacher/students.php"
    style="font-size:13px;font-weight:500;color:var(--text2);text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;"
    class="nav-link"><i class="ti ti-users"></i> Students</a>
  <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
    <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;
      padding:3px 9px;border-radius:99px;background:rgba(91,141,238,.12);color:var(--accent);
      border:1px solid rgba(91,141,238,.25);">Teacher</span>
    <span style="font-size:13px;color:var(--text2);"><?= htmlspecialchars($_SESSION['username']) ?></span>
    <a href="/classroom/logout.php"
      style="font-size:12px;padding:5px 12px;border-radius:8px;background:transparent;
      border:1px solid var(--border2);color:var(--text2);cursor:pointer;text-decoration:none;
      display:inline-flex;align-items:center;gap:5px;">
      <i class="ti ti-logout"></i> Logout
    </a>
  </div>
</nav>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-user-star" style="color:var(--accent)"></i> Teacher Accounts</h1>
    <p>Create and manage teacher logins. All teachers share access to this system.</p>
  </div>

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i><div><?= $success_msg ?></div></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i><div><?= $error_msg ?></div></div>
  <?php endif; ?>

  <div class="two-col">

    <!-- ── Add Teacher Form ── -->
    <div>
      <div class="card">
        <p class="card-title"><i class="ti ti-user-plus"></i> Add New Teacher</p>
        <form method="POST">
          <div class="form-group">
            <label>Display Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="display_name" class="form-control"
              placeholder="e.g. Prof. Anthony Dances"
              value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label>Username <span style="color:var(--red)">*</span></label>
            <input type="text" name="username" class="form-control"
              placeholder="e.g. prof_reyes"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required
              autocomplete="off">
          </div>
          <div class="form-group">
            <label>Password <span style="color:var(--red)">*</span></label>
            <input type="password" name="password" class="form-control"
              placeholder="Minimum 6 characters" required autocomplete="new-password">
          </div>
          <div class="form-group">
            <label>Confirm Password <span style="color:var(--red)">*</span></label>
            <input type="password" name="confirm_password" class="form-control"
              placeholder="Repeat password" required autocomplete="new-password">
          </div>
          <div class="alert alert-info" style="margin-bottom:16px;font-size:12px;">
            <i class="ti ti-info-circle" style="flex-shrink:0;"></i>
            <div>New teachers get full access: they can create subjects, manage students, and view reports.
              There is no separate admin — all teachers are equal.</div>
          </div>
          <button type="submit" name="add_teacher" class="btn btn-primary">
            <i class="ti ti-user-plus"></i> Create Teacher Account
          </button>
        </form>
      </div>
    </div>

    <!-- ── Teacher List ── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-users"></i>
        All Teachers (<?= $teachers_res->num_rows ?>)
      </p>

      <?php if ($teachers_res->num_rows === 0): ?>
      <div class="empty-state">
        <i class="ti ti-user-off"></i>
        No teacher accounts found.
      </div>
      <?php else: ?>

      <?php while ($t = $teachers_res->fetch_assoc()):
        $initials = strtoupper(substr($t['display_name'] ?? $t['username'], 0, 2));
        $is_me    = ((int)$t['id'] === $me);
      ?>
      <div class="teacher-row">
        <div class="teacher-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="teacher-info">
          <div class="teacher-name">
            <?= htmlspecialchars($t['display_name'] ?: $t['username']) ?>
            <?php if ($is_me): ?><span class="teacher-you">You</span><?php endif; ?>
          </div>
          <div class="teacher-uname">@<?= htmlspecialchars($t['username']) ?></div>
        </div>
        <div class="teacher-actions">
          <button class="btn btn-sm btn-outline"
            onclick="openReset(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['username'])) ?>')">
            <i class="ti ti-key"></i> Reset
          </button>
          <?php if (!$is_me): ?>
          <a href="?delete=<?= $t['id'] ?>"
            class="btn btn-sm btn-danger"
            onclick="return confirm('Delete teacher <?= htmlspecialchars(addslashes($t['username'])) ?>? Their subjects will remain but be unowned.')">
            <i class="ti ti-trash"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endwhile; ?>
      <?php endif; ?>
    </div>

  </div><!-- end two-col -->
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <div class="modal-title">
      <i class="ti ti-key"></i> Reset Password — <span id="resetUsername"></span>
    </div>
    <form method="POST">
      <input type="hidden" name="teacher_id" id="resetTeacherId">
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" id="resetPwField" class="form-control"
          placeholder="Minimum 6 characters" required>
      </div>
      <div class="modal-btns">
        <button type="submit" name="reset_password" class="btn btn-primary">
          <i class="ti ti-check"></i> Set Password
        </button>
        <button type="button" class="btn btn-outline" onclick="closeReset()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openReset(id, uname) {
  document.getElementById('resetTeacherId').value = id;
  document.getElementById('resetUsername').textContent = uname;
  document.getElementById('resetPwField').value = '';
  document.getElementById('resetModal').classList.add('open');
}
function closeReset() {
  document.getElementById('resetModal').classList.remove('open');
}
document.getElementById('resetModal').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeReset();
});
</script>
</body>
</html>
