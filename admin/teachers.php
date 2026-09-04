<?php
// ============================================================
//  admin/teachers.php
//  Full CRUD for teacher accounts. Admin-only.
//  (teacher/manage_teachers.php still exists as a lightweight
//  self-service page for teachers themselves — this is the
//  admin-side equivalent with full control.)
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';

$admin_id    = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';
$edit_mode   = false;
$edit_data   = [];

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

// ── LOAD edit mode ───────────────────────────────────────────
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $eid = (int)$_GET['edit'];
    $es = $conn->prepare("SELECT id, username, display_name FROM users WHERE id=? AND role='teacher'");
    $es->bind_param("i", $eid);
    $es->execute();
    $edit_data = $es->get_result()->fetch_assoc();
    if (!$edit_data) { $edit_mode = false; }
}

// ── UPDATE teacher ────────────────────────────────────────────
if (isset($_POST['update_teacher'])) {
    $tid          = (int)$_POST['teacher_id'];
    $username     = trim($_POST['username']);
    $display_name = trim($_POST['display_name']);

    if ($username === '' || $display_name === '') {
        $error_msg = 'Username and display name are required.';
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $chk->bind_param('si', $username, $tid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "Username <strong>" . htmlspecialchars($username) . "</strong> is already taken.";
        } else {
            $upd = $conn->prepare(
                "UPDATE users SET username=?, display_name=? WHERE id=? AND role='teacher'"
            );
            $upd->bind_param('ssi', $username, $display_name, $tid);
            $upd->execute();
            header("Location: teachers.php?msg=updated"); exit;
        }
    }
}

// ── RESET teacher password ────────────────────────────────
if (isset($_POST['reset_password'])) {
    $tid     = (int)$_POST['teacher_id'];
    $newpass = trim($_POST['new_password']);
    if (strlen($newpass) < 6) {
        $error_msg = 'Password must be at least 6 characters.';
    } else {
        $hashed = password_hash($newpass, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'teacher'");
        $upd->bind_param('si', $hashed, $tid);
        $upd->execute();
        $success_msg = "Password reset successfully.";
    }
}

// ── DELETE teacher ────────────────────────────────────────
if (isset($_GET['delete'])) {
    $tid = (int)$_GET['delete'];
    $chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
    $chk->bind_param('i', $tid);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        // Students/sections created by this teacher become "legacy"
        // (created_by / teacher_id set to NULL) instead of being
        // deleted, so nothing is lost — they just need reassigning.
        $conn->begin_transaction();
        try {
            $u1 = $conn->prepare("UPDATE students SET created_by = NULL WHERE created_by = ?");
            $u1->bind_param('i', $tid); $u1->execute();
            $u2 = $conn->prepare("UPDATE sections SET teacher_id = NULL WHERE teacher_id = ?");
            $u2->bind_param('i', $tid); $u2->execute();
            $del = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
            $del->bind_param('i', $tid); $del->execute();
            $conn->commit();
            header("Location: teachers.php?msg=deleted"); exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Could not delete: " . $e->getMessage();
        }
    } else {
        $error_msg = "Teacher not found.";
    }
}

// ── Flash messages ───────────────────────────────────────────
if (isset($_GET['msg'])) {
    $msgs = ['deleted'=>'Teacher account deleted.','updated'=>'Teacher account updated.'];
    $success_msg = $msgs[$_GET['msg']] ?? '';
}

// ── LOAD all teacher accounts with counts ────────────────────
$teachers_res = $conn->query(
    "SELECT u.id, u.username, u.display_name, u.created_at,
        (SELECT COUNT(*) FROM students st WHERE st.created_by = u.id) AS student_count,
        (SELECT COUNT(*) FROM subjects sub WHERE sub.teacher_id = u.id AND sub.is_active = 1) AS subject_count
     FROM users u
     WHERE u.role = 'teacher'
     ORDER BY u.display_name ASC, u.username ASC"
);
$active_nav = 'teachers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Manage Teachers — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-teachers">
<div class="app-shell">


<?php $active_nav = 'teachers'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-user-star text-accent"></i> Manage Teachers</h1>
    <p>Create, edit, and remove teacher accounts across the whole system.</p>
  </div>
<hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i><div><?php echo $success_msg; ?></div></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i><div><?php echo $error_msg; ?></div></div>
  <?php endif; ?>

  <div class="two-col">

    <!-- ── Add / Edit Teacher Form ── -->
    <div>
      <div class="card">
        <?php if ($edit_mode): ?>
        <p class="card-title"><i class="ti ti-edit"></i> Edit Teacher</p>
        <form method="POST">
          <input type="hidden" name="teacher_id" value="<?php echo (int)$edit_data['id']; ?>">
          <div class="form-group">
            <label>Display Name <span class="text-red">*</span></label>
            <input type="text" name="display_name" class="form-control"
              value="<?php echo htmlspecialchars($edit_data['display_name'] ?? ''); ?>" required>
          </div>
          <div class="form-group">
            <label>Username <span class="text-red">*</span></label>
            <input type="text" name="username" class="form-control"
              value="<?php echo htmlspecialchars($edit_data['username']); ?>" required autocomplete="off">
          </div>
          <div style="display:flex;gap:8px;">
            <button type="submit" name="update_teacher" class="btn btn-primary">
              <i class="ti ti-check"></i> Save Changes
            </button>
            <a href="teachers.php" class="btn btn-outline">Cancel</a>
          </div>
        </form>
        <?php else: ?>
        <p class="card-title"><i class="ti ti-user-plus"></i> Add New Teacher</p>
        <form method="POST">
          <div class="form-group">
            <label>Display Name <span class="text-red">*</span></label>
            <input type="text" name="display_name" class="form-control"
              placeholder="e.g. Prof. Anthony Dances"
              value="<?php echo htmlspecialchars($_POST['display_name'] ?? ''); ?>" required>
          </div>
          <div class="form-group">
            <label>Username <span class="text-red">*</span></label>
            <input type="text" name="username" class="form-control"
              placeholder="e.g. prof_reyes"
              value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required
              autocomplete="off">
          </div>
          <div class="form-group">
            <label>Password <span class="text-red">*</span></label>
            <input type="password" name="password" class="form-control"
              placeholder="Minimum 6 characters" required autocomplete="new-password">
          </div>
          <div class="form-group">
            <label>Confirm Password <span class="text-red">*</span></label>
            <input type="password" name="confirm_password" class="form-control"
              placeholder="Repeat password" required autocomplete="new-password">
          </div>
          <button type="submit" name="add_teacher" class="btn btn-primary">
            <i class="ti ti-user-plus"></i> Create Teacher Account
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Teacher List ── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-users"></i>
        All Teachers (<?php echo $teachers_res->num_rows; ?>)
      </p>

      <?php if ($teachers_res->num_rows === 0): ?>
      <div class="empty-state">
        <i class="ti ti-user-off"></i>
        No teacher accounts found.
      </div>
      <?php else: ?>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Teacher</th>
              <th>Students</th>
              <th>Subjects</th>
              <th>Actions</th>
            </tr>
          </thead>
                    <hr class="thin-line">
          <tbody>
            <?php while ($t = $teachers_res->fetch_assoc()):
              $initials = strtoupper(substr($t['display_name'] ?? $t['username'], 0, 2));
            ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div class="avatar"><?php echo htmlspecialchars($initials); ?></div>
                  <div>
                    <div style="font-weight:500;"><?php echo htmlspecialchars($t['display_name'] ?: $t['username']); ?></div>
                    <div style="font-size:11px;color:var(--text7);">@<?php echo htmlspecialchars($t['username']); ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge badge-green"><?php echo (int)$t['student_count']; ?></span></td>
              <td><span class="badge badge-blue"><?php echo (int)$t['subject_count']; ?></span></td>
              <td>
                <div class="td-actions">
                  <a href="teachers.php?edit=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-edit">
                    <i class="ti ti-edit"></i> Edit
                  </a>
                  <button type="button" class="btn btn-sm btn-yellow"
                    onclick="openReset(<?php echo (int)$t['id']; ?>, '<?php echo htmlspecialchars(addslashes($t['username'])); ?>')">
                    <i class="ti ti-key"></i>
                  </button>
                  <a href="teachers.php?delete=<?php echo (int)$t['id']; ?>" class="btn btn-sm btn-delete"
                    onclick="return confirm('Delete teacher <?php echo htmlspecialchars(addslashes($t['username'])); ?>? Their students and sections will remain but become unassigned.')">
                    <i class="ti ti-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- end two-col -->
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <h3><i class="ti ti-key" style="color:var(--yellow);"></i> Reset Password</h3>
    <p id="resetModalName" style="margin-bottom:4px;"></p>
    <form method="POST">
      <input type="hidden" name="teacher_id" id="resetTeacherId">
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" id="resetPwField" class="form-control"
          placeholder="Minimum 6 characters" required minlength="6">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="reset_password" class="btn btn-sm btn-yellow btn-fill">
          <i class="ti ti-check"></i> Set Password
        </button>
        <button type="button" class="btn btn-sm btn-outline" onclick="closeReset()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openReset(id, uname) {
  document.getElementById('resetTeacherId').value = id;
  document.getElementById('resetModalName').textContent = 'Teacher: ' + uname;
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
</main>
</div>
</body>
</html>