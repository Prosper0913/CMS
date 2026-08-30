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
require_once __DIR__ . '/../includes/sync_to_tooltrack.php';
require_once __DIR__ . '/../includes/sync_to_guidance.php';

$teacher_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';


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

// ── ADD student — disabled. Teachers can no longer create student
//    accounts; this is handled by Admin → Students. Server-side
//    block kept as defense in depth even though the form is gone. ──
if (isset($_POST['add_student'])) {
    $error_msg = "Adding students is now handled by an administrator.";
}

// ── DELETE student ───────────────────────────────────────────
if (isset($_GET['delete'])) {
    $del_id = trim($_GET['delete']);
    if (!teacherOwnsStudent($conn, $teacher_id, $del_id)) {
        $error_msg = "You can only delete students enrolled in one of your own subjects.";
    } else {
        $conn->begin_transaction();
        try {
            $d1 = $conn->prepare("DELETE FROM users WHERE student_id=?");
            $d1->bind_param("s",$del_id); $d1->execute();
            $d2 = $conn->prepare("DELETE FROM students WHERE student_id=?");
            $d2->bind_param("s",$del_id); $d2->execute();
            $conn->commit();
            // Keep Tooltrack/Guidance in sync — same as admin/students.php.
            // Failures never break the delete.
            push_student_deletion_to_tooltrack($del_id);
            push_student_deletion_to_guidance($del_id);
            header("Location: students.php?msg=deleted"); exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Could not delete: ".$e->getMessage();
        }
    }
}

// ── Editing student info — disabled. Teachers can no longer edit
//    student records; this is handled by Admin → Students. Server-side
//    block kept as defense in depth even though the edit form is gone. ──
if (isset($_GET['edit']) || isset($_POST['update_student'])) {
    $error_msg = "Editing student information is now handled by an administrator.";
}

// ── RESET password ───────────────────────────────────────────
if (isset($_POST['reset_password'])) {
    $student_id  = trim($_POST['student_id']);
    $new_password= trim($_POST['new_password']);

    if (!teacherOwnsStudent($conn, $teacher_id, $student_id)) {
        $error_msg = "You can only reset passwords for students enrolled in one of your own subjects.";
    } elseif (strlen($new_password) < 6) {
        $error_msg = "Password must be at least 6 characters.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
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
        "SELECT DISTINCT s.*,
            (SELECT COUNT(*) FROM subject_enrollments e WHERE e.student_id=s.student_id) AS subject_count
         FROM students s
         JOIN subject_enrollments se ON se.student_id = s.student_id
         JOIN subjects sub ON sub.id = se.subject_id
         WHERE sub.teacher_id = ?
           AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.student_id LIKE ? OR s.username LIKE ?)
         ORDER BY s.last_name ASC"
    );
    $res->bind_param("issss",$teacher_id,$like,$like,$like,$like);
    $res->execute();
    $students = $res->get_result();
} else {
    $stmt = $conn->prepare(
        "SELECT DISTINCT s.*,
            (SELECT COUNT(*) FROM subject_enrollments e WHERE e.student_id=s.student_id) AS subject_count
         FROM students s
         JOIN subject_enrollments se ON se.student_id = s.student_id
         JOIN subjects sub ON sub.id = se.subject_id
         WHERE sub.teacher_id = ?
         ORDER BY s.last_name ASC"
    );
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $students = $stmt->get_result();
}

$total_stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT s.student_id) AS c
     FROM students s
     JOIN subject_enrollments se ON se.student_id = s.student_id
     JOIN subjects sub ON sub.id = se.subject_id
     WHERE sub.teacher_id = ?"
);
$total_stmt->bind_param("i", $teacher_id);
$total_stmt->execute();
$total_students = $total_stmt->get_result()->fetch_assoc()['c'];

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
    <link rel="stylesheet" href="/classroomv2/assets/style.css">

</head>
<body class="page-teacher-students">
<div class="app-shell">


<!-- NAVBAR -->
<?php $active_nav = 'students'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <!-- <div class="stats-row"> 
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Students</div>
      <div class="stat-value"><?php echo $total_students; ?></div>
      <div class="stat-sub">in the system</div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Enrolled in Subjects</div>
      <?php
      $enrolled_count = $conn->query(
          "SELECT COUNT(DISTINCT student_id) AS c FROM subject_enrollments"
      )->fetch_assoc()['c'];
      ?>
      <div class="stat-value"><?php echo $enrolled_count; ?></div>
      <div class="stat-sub">across all subjects</div>
    </div>
  </div>-->

  <div class="two-col" style="justify-content: center;">

    <!-- ── FORM PANEL (read-only info — teachers can no longer add/edit students) ── -->
    <div>
      <div class="card">
        <p class="card-title"><i class="ti ti-info-circle"></i> Student Records</p>
        <p style="font-size:13px;color:var(--text2);line-height:1.6;">
          Adding new students and editing student information is now handled by an
          administrator, under <strong>Admin &rarr; Students</strong>.
        </p>
        <p style="font-size:13px;color:var(--text2);line-height:1.6;margin-top:10px;">
          To add or remove which students are in your classes, use
          <a href="manage_sections.php" style="color:var(--accent)">Manage Sections</a>.
        </p>
      </div>
    </div>

      <!-- Info box -->
      <!-- <div class="card"> 
        <p>
          <i class="ti ti-info-circle red-font"></i> Note on Sections
        </p>
        <p>
          Students are no longer assigned a fixed section here.
          Instead, enroll them into specific subjects when you
          <a href="/classroom/teacher/add_subject.php" style="color:var(--yellow);">create a subject</a>.
          A student can be enrolled in multiple subjects across different sections.
        </p>
      </div>-->
    </div>

    <!-- ── STUDENT LIST PANEL ── -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <p class="card-title" style="margin:0;"><i class="ti ti-list"></i> All Students</p>
        <span class="black-font"><?php echo $total_students; ?> total</span>
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
                        <span><?php echo htmlspecialchars($s['middle_initial']); ?></span>
                      <?php endif; ?>
                    </div>
                    <div>
                      <?php echo htmlspecialchars($s['email'] ?: '—'); ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="td-mono"><?php echo htmlspecialchars($s['student_id']); ?></td>
              <td>
                <span>
                  <?php echo htmlspecialchars($s['username']); ?>
                </span>
              </td>
              <td>
                <?php if ($s['subject_count'] > 0): ?>
                  <span class="badge badge-green"><?php echo $s['subject_count']; ?> subject<?php echo $s['subject_count']>1?'s':''; ?></span>
                <?php else: ?>
                  <span style="font-size:11px;color:var(--text7);">Not enrolled</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <button type="button"
                    class="btn btn-sm btn-yellow"
                    onclick="openResetModal('<?php echo htmlspecialchars($s['student_id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['last_name'].', '.$s['first_name'],ENT_QUOTES); ?>')">
                    <i class="ti ti-key" style="color: var(--yellow2);"></i>
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
</main>
</div>
</body>
</html>