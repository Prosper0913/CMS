<?php
// ============================================================
//  teacher/students.php
//  Add, edit, delete student accounts.
//  NOTE: The students table no longer has a 'section' column.
//  Section is handled at the subject level (subject_enrollments).
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

$teacher_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';
$edit_mode   = false;
$edit_data   = [];


// ── Subjects with per-subject stats ─────────────────────────
$subjects_stmt = $conn->prepare(
    "SELECT s.*,
        (SELECT COUNT(*)
         FROM subject_enrollments
         WHERE subject_id = s.id)                                          AS enrollee_count,
        (SELECT ROUND(AVG(final_grade),1)
         FROM subject_grades
         WHERE subject_id = s.id AND final_grade > 0)                     AS class_avg,
        (SELECT COUNT(*)
         FROM subject_grades
         WHERE subject_id = s.id AND final_grade >= 75)                   AS passing,
        (SELECT COUNT(*)
         FROM subject_grades
         WHERE subject_id = s.id AND final_grade > 0 AND final_grade < 75) AS failing,
        (SELECT COUNT(DISTINCT date)
         FROM attendance
         WHERE subject_id = s.id)                                         AS class_days
     FROM subjects s
     WHERE s.teacher_id = ? AND s.is_active = 1
     ORDER BY s.semester DESC, s.subject_name ASC"
);
$subjects_stmt->bind_param("i", $teacher_id);
$subjects_stmt->execute();
$all_subs = $subjects_stmt->get_result();

// ── ADD student ─────────────────────────────────────────────
if (isset($_POST['add_student'])) {
    $student_id    = trim($_POST['student_id']);
    $last_name     = trim($_POST['last_name']);
    $first_name    = trim($_POST['first_name']);
    $middle_initial= trim($_POST['middle_initial']);
    $email         = trim($_POST['email']);
    $username      = trim($_POST['username']);
    $password      = trim($_POST['password']);

    if ($student_id===''||$last_name===''||$first_name===''||$username===''||$password==='') {
        $error_msg = "Student ID, name, username, and password are all required.";
    } else {
        // Check duplicate student_id or username
        $chk = $conn->prepare(
            "SELECT id FROM students WHERE student_id=? OR username=? LIMIT 1"
        );
        $chk->bind_param("ss",$student_id,$username);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $error_msg = "Student ID or username already exists. Please use a unique value.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $conn->begin_transaction();
            try {
                // Insert into students
                $ins = $conn->prepare(
                    "INSERT INTO students
                        (student_id,last_name,first_name,middle_initial,email,username,password)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $ins->bind_param("sssssss",
                    $student_id,$last_name,$first_name,$middle_initial,$email,$username,$hashed
                );
                $ins->execute();

                // Insert into users (so they can log in)
                $ins2 = $conn->prepare(
                    "INSERT INTO users (username,password,role,student_id)
                     VALUES (?,?,'student',?)"
                );
                $ins2->bind_param("sss",$username,$hashed,$student_id);
                $ins2->execute();

                $conn->commit();
                $success_msg = "Student <strong>"
                    .htmlspecialchars($last_name.', '.$first_name)
                    ."</strong> added. They can now log in as <code>{$username}</code>.";
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Database error: ".$e->getMessage();
            }
        }
    }
}

// ── DELETE student ───────────────────────────────────────────
if (isset($_GET['delete'])) {
    $del_id = trim($_GET['delete']);
    $conn->begin_transaction();
    try {
        $d1 = $conn->prepare("DELETE FROM users WHERE student_id=?");
        $d1->bind_param("s",$del_id); $d1->execute();
        $d2 = $conn->prepare("DELETE FROM students WHERE student_id=?");
        $d2->bind_param("s",$del_id); $d2->execute();
        $conn->commit();
        header("Location: students.php?msg=deleted"); exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Could not delete: ".$e->getMessage();
    }
}

// ── LOAD edit mode ───────────────────────────────────────────
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $es = $conn->prepare("SELECT * FROM students WHERE student_id=?");
    $es->bind_param("s",$_GET['edit']); $es->execute();
    $edit_data = $es->get_result()->fetch_assoc();
    if (!$edit_data) { $edit_mode = false; }
}

// ── UPDATE student ───────────────────────────────────────────
if (isset($_POST['update_student'])) {
    $student_id    = trim($_POST['student_id']);
    $last_name     = trim($_POST['last_name']);
    $first_name    = trim($_POST['first_name']);
    $middle_initial= trim($_POST['middle_initial']);
    $email         = trim($_POST['email']);
    $username      = trim($_POST['username']);

    $upd = $conn->prepare(
        "UPDATE students SET
            last_name=?,first_name=?,middle_initial=?,email=?,username=?
         WHERE student_id=?"
    );
    $upd->bind_param("ssssss",
        $last_name,$first_name,$middle_initial,$email,$username,$student_id
    );
    $upd->execute();

    // Keep username in sync in users table
    $upd2 = $conn->prepare("UPDATE users SET username=? WHERE student_id=?");
    $upd2->bind_param("ss",$username,$student_id);
    $upd2->execute();

    header("Location: students.php?msg=updated"); exit;
}

// ── RESET password ───────────────────────────────────────────
if (isset($_POST['reset_password'])) {
    $student_id  = trim($_POST['student_id']);
    $new_password= trim($_POST['new_password']);
    if (strlen($new_password) < 6) {
        $error_msg = "Password must be at least 6 characters.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $conn->prepare("UPDATE students SET password=? WHERE student_id=?")
             ->bind_param("ss",$hashed,$student_id) && null;
        $p1 = $conn->prepare("UPDATE students SET password=? WHERE student_id=?");
        $p1->bind_param("ss",$hashed,$student_id); $p1->execute();
        $p2 = $conn->prepare("UPDATE users SET password=? WHERE student_id=?");
        $p2->bind_param("ss",$hashed,$student_id); $p2->execute();
        $success_msg = "Password reset successfully.";
    }
}

$nav_subs = getTeacherSubjects($conn, $teacher_id);
$type_cfg = [
    'General Education'      => ['color'=>'#6c8dda','label'=>'GE'],
    'Professional Education' => ['color'=>'#ff2407','label'=>'PE'],
    'Major Subject'          => ['color'=>'#00ff1a','label'=>'MAJ'],
];

// ── Flash messages ───────────────────────────────────────────
if (isset($_GET['msg'])) {
    $msgs = ['deleted'=>'Student deleted.','updated'=>'Student updated.'];
    $success_msg = $msgs[$_GET['msg']] ?? '';
}

// ── Fetch all students ───────────────────────────────────────
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = "%{$search}%";
    $res = $conn->prepare(
        "SELECT s.*,
            (SELECT COUNT(*) FROM subject_enrollments e WHERE e.student_id=s.student_id) AS subject_count
         FROM students s
         WHERE s.last_name LIKE ? OR s.first_name LIKE ? OR s.student_id LIKE ? OR s.username LIKE ?
         ORDER BY s.last_name ASC"
    );
    $res->bind_param("ssss",$like,$like,$like,$like);
    $res->execute();
    $students = $res->get_result();
} else {
    $students = $conn->query(
        "SELECT s.*,
            (SELECT COUNT(*) FROM subject_enrollments e WHERE e.student_id=s.student_id) AS subject_count
         FROM students s ORDER BY s.last_name ASC"
    );
}

$total_students = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];

$page_title = "Students";
$active_nav = "students";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Students — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">

</head>
<body class="page-teacher-students">

<!-- NAVBAR -->
<nav class="navbar">
  <a class="brand" href="/classroom/teacher/dashboard.php">
    <img src="/classroom/assets/images/TCM logo (2).png" alt="TCM Logo " width="32" height="32">Classroom Management System
  </a>
  <div class="nav-sep"></div>
  <a href="/classroom/teacher/dashboard.php" class="nav-link"><i class="ti ti-layout-dashboard"></i> Dashboard</a>
  <!-- Subject dropdown (only if subjects exist) -->
  <?php if ($all_subs->num_rows > 0): ?>
  <div class="nav-dropdown">
    <button class="nav-dd-btn" id="ddBtn" onclick="toggleDD()">
      <i class="ti ti-books"></i> My Subjects
      <i class="ti ti-chevron-down"></i>
    </button>
    <div class="nav-dd-menu" id="ddMenu">
      <?php
      $all_subs->data_seek(0);
      while ($ns = $all_subs->fetch_assoc()):
        $dc = $type_cfg[$ns['subject_type']]['color'] ?? '#7aa3ff';
      ?>
      <a href="/classroom/teacher/subject_view.php?id=<?php echo $ns['id']; ?>" class="dd-item">
        <span class="dd-dot" style="background:<?php echo $dc; ?>;"></span>
        <span class="dd-main"><?php echo htmlspecialchars($ns['subject_code'].' — '.$ns['subject_name']); ?></span>
        <span class="dd-sub"><?php echo htmlspecialchars($ns['section']); ?></span>
      </a>
      <?php endwhile; ?>
      <div class="dd-divider"></div>
      <a href="/classroom/teacher/add_subject.php" class="dd-item">
        <i class="ti ti-plus" style="color:var(--accent);font-size:13px;"></i>
        <span class="dd-main" style="color:var(--accent);">Add New Subject</span>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <a href="/classroom/teacher/add_subject.php" class="nav-link"><i class="ti ti-book-plus"></i> Add Subject</a>
    <a href="/classroom/teacher/manage_sections.php" class="nav-link"><i class="ti ti-layout-dashboard"></i> Sections</a>
  <a href="/classroom/teacher/students.php" class="nav-link active"><i class="ti ti-users"></i> Students</a>
  <div class="nav-right">
    <span class="nav-role">Teacher</span>
    <span style="font-size:13px;color:var(--text2);"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="/classroom/logout.php" class="btn-logout"><i class="ti ti-logout"></i> Logout</a>
  </div>
</nav>

<div class="page-wrap">

  <!-- Page header -->
  <div class="page-header">
    <h1><i class="ti ti-users" style="color:var(--bg4)"></i> Student Management</h1>
    <p>Add and manage student accounts. Enroll them into subjects from the Add Subject page.</p>
  </div>

<hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Students</div>
      <div class="stat-value"><?php echo $total_students; ?></div>
      <div class="stat-sub">in the system</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Enrolled in Subjects</div>
      <?php
      $enrolled_count = $conn->query(
          "SELECT COUNT(DISTINCT student_id) AS c FROM subject_enrollments"
      )->fetch_assoc()['c'];
      ?>
      <div class="stat-value"><?php echo $enrolled_count; ?></div>
      <div class="stat-sub">across all subjects</div>
    </div>
  </div>

  <div class="two-col">

    <!-- ── FORM PANEL ── -->
    <div>
      <div class="card">
        <p class="card-title">
          <i class="ti ti-<?php echo $edit_mode?'edit':'user-plus'; ?>"></i>
          <?php echo $edit_mode ? 'Edit Student' : 'Add New Student'; ?>
        </p>

        <form method="POST" autocomplete="off">

          <?php if ($edit_mode): ?>
            <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($edit_data['student_id']); ?>">
            <!-- Show student ID as read-only when editing -->
            <div class="form-group">
              <label>Student ID</label>
              <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_data['student_id']); ?>" disabled style="opacity:.5;">
            </div>
          <?php else: ?>
            <div class="form-group">
              <label>Student ID <span style="color:var(--red);">*</span></label>
              <div class="input-wrap">
                <i class="ti ti-id-badge"></i>
                <input type="text" name="student_id" class="form-control"
                  placeholder="e.g. STU-001"
                  value="<?php echo htmlspecialchars($_POST['student_id']??''); ?>" required>
              </div>
            </div>
          <?php endif; ?>

          <div class="form-row">
            <div class="form-group">
              <label>Last Name <span style="color:var(--red);">*</span></label>
              <input type="text" name="last_name" class="form-control"
                placeholder="Dances"
                value="<?php echo htmlspecialchars($edit_mode?$edit_data['last_name']:($_POST['last_name']??'')); ?>" required>
            </div>
            <div class="form-group">
              <label>First Name <span style="color:var(--red);">*</span></label>
              <input type="text" name="first_name" class="form-control"
                placeholder="Anthony"
                value="<?php echo htmlspecialchars($edit_mode?$edit_data['first_name']:($_POST['first_name']??'')); ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label>Middle Initial</label>
            <input type="text" name="middle_initial" class="form-control"
              placeholder="C." maxlength="5"
              value="<?php echo htmlspecialchars($edit_mode?($edit_data['middle_initial']??''):($_POST['middle_initial']??'')); ?>">
          </div>

          <div class="form-group">
            <label>Email Address</label>
            <div class="input-wrap">
              <i class="ti ti-mail"></i>
              <input type="email" name="email" class="form-control"
                placeholder="anthonydances@school.edu.ph"
                value="<?php echo htmlspecialchars($edit_mode?($edit_data['email']??''):($_POST['email']??'')); ?>">
            </div>
          </div>

          <div class="divider"></div>
          <p style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text3);margin-bottom:12px;">Login Credentials</p>

          <div class="form-group">
            <label>Username <span style="color:var(--red);">*</span></label>
            <div class="input-wrap">
              <i class="ti ti-at"></i>
              <input type="text" name="username" class="form-control"
                placeholder="anthony.dances"
                value="<?php echo htmlspecialchars($edit_mode?$edit_data['username']:($_POST['username']??'')); ?>" required>
            </div>
          </div>

          <?php if (!$edit_mode): ?>
          <div class="form-group">
            <label>Password <span style="color:var(--red);">*</span></label>
            <div class="input-wrap" style="position:relative;">
              <i class="ti ti-lock"></i>
              <input type="password" name="password" id="pw-input" class="form-control"
                placeholder="Set initial password" required>
              <button type="button" class="show-pw" onclick="togglePw()"
                style="position:absolute;right:15px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;font-size:15px;">
                 &ensp;<i class="ti ti-eye"></i> &nbsp;&emsp;<i id="pw-icon"></i>
              </button>
            </div>
          </div>
          <?php else: ?>
          <div style="background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.2);border-radius:var(--radius);padding:10px 14px;font-size:12px;color:var(--yellow);display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <i class="ti ti-info-circle"></i>
            To change the password, use the Reset Password button in the student list.
          </div>
          <?php endif; ?>

          <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px;">
            <?php if ($edit_mode): ?>
              <button type="submit" name="update_student" class="btn btn-primary">
                <i class="ti ti-check"></i> Update Student
              </button>
              <a href="students.php" class="btn btn-cancel">
                <i class="ti ti-x"></i> Cancel
              </a>
            <?php else: ?>
              <button type="submit" name="add_student" class="btn btn-primary">
                <i class="ti ti-user-plus"></i> Add Student
              </button>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Info box -->
      <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px 18px; margin-top: 25px;">
        <p style="font-size:12px;font-weight:600;color:var(--text2);margin-bottom:8px;display:flex;align-items:center;gap:6px;">
          <i class="ti ti-info-circle" style="color:var(--bg5);"></i> Note on Sections
        </p>
        <p style="font-size:12px;color:var(--text3);line-height:1.7;">
          Students are no longer assigned a fixed section here.
          Instead, enroll them into specific subjects when you
          <a href="/classroom/teacher/add_subject.php" style="color:var(--yellow);">create a subject</a>.
          A student can be enrolled in multiple subjects across different sections.
        </p>
      </div>
    </div>

    <!-- ── STUDENT LIST PANEL ── -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <p class="card-title" style="margin:0;"><i class="ti ti-list"></i> All Students</p>
        <span style="font-size:12px;color:var(--text2);"><?php echo $total_students; ?> total</span>
      </div>

      <!-- Search -->
      <div class="search-bar">
        <form method="GET" style="display:flex;gap:8px;flex:1;">
          <div class="input-wrap" style="flex:1;">
            <i class="ti ti-search"></i>
            <input type="text" name="search" class="form-control"
              placeholder="Search by name, ID, or username…"
              value="<?php echo htmlspecialchars($search); ?>">
          </div>
          <button type="submit" class="btn btn-outline btn-sm">Search</button>
          <?php if ($search): ?>
            <a href="students.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i></a>
          <?php endif; ?>
        </form>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Student</th>
              <th>Student ID</th>
              <th>Username</th>
              <th>Subjects</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($students->num_rows === 0): ?>
            <tr><td colspan="5">
              <div class="empty-state">
                <i class="ti ti-users-off"></i>
                <p><?php echo $search ? "No students matched \"$search\"" : "No students yet. Add one using the form."; ?></p>
              </div>
            </td></tr>
            <?php endif; ?>

            <?php while ($s = $students->fetch_assoc()):
              $initials = strtoupper(substr($s['last_name'],0,1).substr($s['first_name'],0,1));
            ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div class="avatar"><?php echo $initials; ?></div>
                  <div>
                    <div style="font-weight:500;">
                      <?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?>
                      <?php if ($s['middle_initial']): ?>
                        <span style="color:var(--text2)"><?php echo htmlspecialchars($s['middle_initial']); ?></span>
                      <?php endif; ?>
                    </div>
                    <div style="font-size:11px;color:var(--text3);">
                      <?php echo htmlspecialchars($s['email'] ?: '—'); ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="td-mono"><?php echo htmlspecialchars($s['student_id']); ?></td>
              <td>
                <span style="font-family:var(--font-mono);font-size:12px;background:var(--bg3);padding:2px 8px;border-radius:5px;color:var(--text2);">
                  <?php echo htmlspecialchars($s['username']); ?>
                </span>
              </td>
              <td>
                <?php if ($s['subject_count'] > 0): ?>
                  <span class="badge badge-green"><?php echo $s['subject_count']; ?> subject<?php echo $s['subject_count']>1?'s':''; ?></span>
                <?php else: ?>
                  <span style="font-size:11px;color:var(--text3);">Not enrolled</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <a href="students.php?edit=<?php echo urlencode($s['student_id']); ?>"
                     class="btn btn-sm btn-edit">
                    <i class="ti ti-edit"></i> Edit
                  </a>
                  <button type="button"
                    class="btn btn-sm btn-yellow"
                    onclick="openResetModal('<?php echo htmlspecialchars($s['student_id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['last_name'].', '.$s['first_name'],ENT_QUOTES); ?>')">
                    <i class="ti ti-key"></i>
                  </button>
                  <a href="students.php?delete=<?php echo urlencode($s['student_id']); ?>"
                     class="btn btn-sm btn-delete"
                     onclick="return confirm('Delete <?php echo htmlspecialchars(addslashes($s['first_name'])); ?>? This also removes their scores and attendance.')">
                    <i class="ti ti-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- end two-col -->
</div><!-- end page-wrap -->

<!-- ── PASSWORD RESET MODAL ── -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <h3><i class="ti ti-key" style="color:var(--yellow);"></i> Reset Password</h3>
    <p id="resetModalName" style="margin-bottom:4px;"></p>
    <p>Enter a new password for this student.</p>
    <form method="POST">
      <input type="hidden" name="student_id" id="resetStudentId">
      <div class="form-group">
        <label>New Password</label>
        <div class="input-wrap">
          <i class="ti ti-lock"></i>
          <input type="password" name="new_password" id="resetPw" class="form-control"
            placeholder="Min. 6 characters" required minlength="6">
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="reset_password" class="btn btn-sm btn-yellow" style="flex:1;justify-content:center;">
          <i class="ti ti-check"></i> Reset Password
        </button>
        <button type="button" class="btn btn-sm btn-outline" onclick="closeResetModal()">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function togglePw(){
  const pw=document.getElementById('pw-input');
  const ic=document.getElementById('pw-icon');
  if(!pw||!ic)return;
  if(pw.type==='password'){pw.type='text';ic.className='ti ti-eye-off';}
  else{pw.type='password';ic.className='ti ti-eye';}
}
function openResetModal(sid,name){
  document.getElementById('resetStudentId').value=sid;
  document.getElementById('resetModalName').textContent='Student: '+name;
  document.getElementById('resetPw').value='';
  document.getElementById('resetModal').classList.add('open');
}
function closeResetModal(){
  document.getElementById('resetModal').classList.remove('open');
}
// Close on backdrop click
document.getElementById('resetModal').addEventListener('click',function(e){
  if(e.target===this) closeResetModal();
});
</script>
<script>
function toggleDD(){
  document.getElementById('ddMenu').classList.toggle('open');
  document.getElementById('ddBtn').classList.toggle('open');
}
document.addEventListener('click',e=>{
  const dd=document.querySelector('.nav-dropdown');
  if(dd&&!dd.contains(e.target)){
    document.getElementById('ddMenu')?.classList.remove('open');
    document.getElementById('ddBtn')?.classList.remove('open');
  }
});
</script>
</body>
</html>
