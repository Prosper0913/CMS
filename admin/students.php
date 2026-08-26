<?php
// ============================================================
//  admin/students.php
//  View and manage every student in the system. Under the
//  section-based ownership model, a teacher never "owns" a
//  student — a section does (see admin/sections.php for roster
//  management). This page still handles the core student record
//  (name, login, password) and can optionally drop a newly
//  created student straight into a section. Admin-only.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';
// Push-based sync to tooltrack (no-op for non-FPST, never throws).
require_once __DIR__ . '/../includes/sync_to_tooltrack.php';
// Push-based sync to Guidance Appointment System (ALL students sync).
require_once __DIR__ . '/../includes/sync_to_guidance.php';

$admin_id    = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';
$edit_mode   = false;
$edit_data   = [];

// ── Sections list (for the optional "add to section" dropdown) ─
$sections_list = $conn->query(
    "SELECT id, section_name FROM sections ORDER BY section_name ASC"
)->fetch_all(MYSQLI_ASSOC);

// ── ADD student ─────────────────────────────────────────────
if (isset($_POST['add_student'])) {
    $student_id     = trim($_POST['student_id']);
    $last_name      = trim($_POST['last_name']);
    $first_name     = trim($_POST['first_name']);
    $middle_initial = trim($_POST['middle_initial']);
    $email          = trim($_POST['email']);
    $course         = trim($_POST['course'] ?? '');
    $username       = trim($_POST['username']);
    $password       = trim($_POST['password']);
    $section_id     = ($_POST['section_id'] !== '') ? (int)$_POST['section_id'] : null;

    if ($student_id===''||$last_name===''||$first_name===''||$username===''||$password==='') {
        $error_msg = "Student ID, name, username, and password are all required.";
    } else {
        $chk = $conn->prepare("SELECT id FROM students WHERE student_id=? OR username=? LIMIT 1");
        $chk->bind_param("ss",$student_id,$username);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $error_msg = "Student ID or username already exists. Please use a unique value.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $conn->begin_transaction();
            try {
                $ins = $conn->prepare(
                    "INSERT INTO students
                        (student_id,last_name,first_name,middle_initial,email,course,username,password,created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $ins->bind_param("ssssssssi",
                    $student_id,$last_name,$first_name,$middle_initial,$email,$course,$username,$hashed,$admin_id
                );
                $ins->execute();

                $ins2 = $conn->prepare(
                    "INSERT INTO users (username,password,role,student_id) VALUES (?,?,'student',?)"
                );
                $ins2->bind_param("sss",$username,$hashed,$student_id);
                $ins2->execute();

                if ($section_id) {
                    $ins3 = $conn->prepare("INSERT INTO section_students (section_id, student_id) VALUES (?, ?)");
                    $ins3->bind_param("is", $section_id, $student_id);
                    $ins3->execute();
                }

                $conn->commit();
                // ── Auto-enroll + push to tooltrack: if the new student was
                // placed in a section whose course is FPST, we need to (1)
                // auto-enroll them in every FPST subject taught to that
                // section (mirrors the approval-time bulk-enroll flow —
                // without this, the masterlist query won't pick them up),
                // then (2) push the updated rosters to tooltrack. Non-FPST
                // and no-section cases are no-ops. Failures never break the add.
                if ($section_id) {
                    auto_enroll_student_in_fpst_subjects($conn, $section_id, $student_id);
                    push_all_fpst_subjects_for_section($conn, $section_id);
                }
                // ── Push to Guidance: ALL students sync (no course filter).
                // Pushes the student's full current state — basic info,
                // profile (from their section), and subject enrollments.
                // Failures never break the add.
                push_student_to_guidance($conn, $student_id);
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

// ── LOAD edit mode ──────────────────────────────────────────
if (isset($_GET['edit'])) {
    $edit_mode = true;
    $edit_id = trim($_GET['edit']);
    $es = $conn->prepare("SELECT * FROM students WHERE student_id=?");
    $es->bind_param("s",$edit_id); $es->execute();
    $edit_data = $es->get_result()->fetch_assoc();
    if (!$edit_data) {
        $edit_mode = false;
    } else {
        // Sections this student currently belongs to (read-only here —
        // membership is managed from admin/sections.php)
        $sq = $conn->prepare(
            "SELECT sec.id, sec.section_name FROM section_students ss
             JOIN sections sec ON sec.id = ss.section_id
             WHERE ss.student_id = ? ORDER BY sec.section_name ASC"
        );
        $sq->bind_param("s", $edit_id);
        $sq->execute();
        $edit_sections = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// ── UPDATE student ────────────────────────────────────────────
if (isset($_POST['update_student'])) {
    $student_id     = trim($_POST['student_id']);
    $last_name      = trim($_POST['last_name']);
    $first_name     = trim($_POST['first_name']);
    $middle_initial = trim($_POST['middle_initial']);
    $email          = trim($_POST['email']);
    $course         = trim($_POST['course'] ?? '');
    $username       = trim($_POST['username']);

    $upd = $conn->prepare(
        "UPDATE students SET
            last_name=?,first_name=?,middle_initial=?,email=?,course=?,username=?
         WHERE student_id=?"
    );
    $upd->bind_param("sssssss",
        $last_name,$first_name,$middle_initial,$email,$course,$username,$student_id
    );
    $upd->execute();

    $upd2 = $conn->prepare("UPDATE users SET username=? WHERE student_id=?");
    $upd2->bind_param("ss",$username,$student_id);
    $upd2->execute();

    // ── Push to tooltrack: re-sync every FPST subject this student is
    // currently enrolled in, so tooltrack sees the updated name. Non-FPST
    // enrollments are skipped. Failures never break the update.
    push_all_fpst_subjects_for_student($conn, $student_id);
    // ── Push to Guidance: re-push the student's full state so Guidance
    // sees the updated name/username/password. ALL students sync.
    push_student_to_guidance($conn, $student_id);

    header("Location: students.php?msg=updated"); exit;
}

// ── RESET password ───────────────────────────────────────────
if (isset($_POST['reset_password'])) {
    $student_id   = trim($_POST['student_id']);
    $new_password = trim($_POST['new_password']);
    if (strlen($new_password) < 6) {
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
        // ── Push to tooltrack: soft-deactivate the borrower row + all
        // their borrower_enrollments rows. We do NOT delete the borrower
        // row in tooltrack because transactions reference it (and we want
        // old transaction history to keep showing the student's name).
        // Failures never break the delete.
        push_student_deletion_to_tooltrack($del_id);
        // ── Push to Guidance: disable the user + deactivate all their
        // student_enrollments rows. We do NOT delete the user row because
        // appointments/referrals reference it. Failures never break the delete.
        push_student_deletion_to_guidance($del_id);
        header("Location: students.php?msg=deleted"); exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Could not delete: ".$e->getMessage();
    }
}

// ── Flash messages ───────────────────────────────────────────
if (isset($_GET['msg'])) {
    $msgs = ['deleted'=>'Student deleted.','updated'=>'Student updated.'];
    $success_msg = $msgs[$_GET['msg']] ?? '';
}

// ── Filter by section (optional) ──────────────────────────────
$filter_section = isset($_GET['section']) && $_GET['section'] !== '' ? $_GET['section'] : null;
// 'unassigned' is a special filter value for "not in any section"

// ── Fetch students (search + section filter) ──────────────────
$search = trim($_GET['search'] ?? '');
$where  = [];
$types  = '';
$params = [];

if ($search !== '') {
    $where[] = "(s.last_name LIKE ? OR s.first_name LIKE ? OR s.student_id LIKE ? OR s.username LIKE ?)";
    $like = "%{$search}%";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($filter_section === 'unassigned') {
    $where[] = "NOT EXISTS (SELECT 1 FROM section_students ss WHERE ss.student_id = s.student_id)";
} elseif ($filter_section !== null) {
    $where[] = "EXISTS (SELECT 1 FROM section_students ss WHERE ss.student_id = s.student_id AND ss.section_id = ?)";
    $types  .= 'i';
    $params[] = (int)$filter_section;
}

$sql = "SELECT s.*,
            (SELECT COUNT(*) FROM subject_enrollments e WHERE e.student_id=s.student_id) AS subject_count,
            (SELECT GROUP_CONCAT(sec.section_name SEPARATOR ', ')
             FROM section_students ss JOIN sections sec ON sec.id = ss.section_id
             WHERE ss.student_id = s.student_id) AS section_names
         FROM students s";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY s.last_name ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result();

$total_students = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];

$active_nav = 'students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Manage Students — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-students">
<div class="app-shell">


<?php $active_nav = 'students'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h1><i class="ti ti-users" style="color:var(--accent)"></i> Manage Students</h1>
      <p>Every student in the system. <?php echo (int)$total_students; ?> total.</p>
    </div>
    <a href="import_students.php" class="btn btn-outline btn-sm">
      <i class="ti ti-file-import"></i> Import from CSV
    </a>
  </div>
<hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i><div><?php echo $success_msg; ?></div></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i><div><?php echo $error_msg; ?></div></div>
  <?php endif; ?>

  <div class="two-col" style="margin-left: -120px;">

    <!-- ── Add / Edit Student Form ── -->
    <div>
      <div class="card">
        <?php if ($edit_mode): ?>
        <p class="card-title"><i class="ti ti-edit"></i> Edit Student</p>
        <form method="POST">
          <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($edit_data['student_id']); ?>">
          <div class="form-group">
            <label>Last Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="last_name" class="form-control"
              value="<?php echo htmlspecialchars($edit_data['last_name']); ?>" required>
          </div>
          <div class="form-group">
            <label>First Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="first_name" class="form-control"
              value="<?php echo htmlspecialchars($edit_data['first_name']); ?>" required>
          </div>
          <div class="form-group">
            <label>Middle Initial</label>
            <input type="text" name="middle_initial" class="form-control"
              value="<?php echo htmlspecialchars($edit_data['middle_initial'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control"
              value="<?php echo htmlspecialchars($edit_data['email'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>Course</label>
            <input type="text" name="course" class="form-control" placeholder="e.g. BSIT"
              value="<?php echo htmlspecialchars($edit_data['course'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>Username <span style="color:var(--red)">*</span></label>
            <input type="text" name="username" class="form-control"
              value="<?php echo htmlspecialchars($edit_data['username']); ?>" required autocomplete="off">
          </div>
          <div class="form-group">
            <label>Section(s)</label>
            <?php if (!empty($edit_sections)): ?>
              <div style="display:flex;flex-wrap:wrap;gap:6px;">
                <?php foreach ($edit_sections as $es): ?>
                <span class="badge badge-blue"><?php echo htmlspecialchars($es['section_name']); ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p style="font-size:12px;color:var(--text7);margin:0;">Not in any section yet.</p>
            <?php endif; ?>
            <p style="font-size:11px;color:var(--text7);margin-top:6px;">
              Section membership is managed from <a href="sections.php" style="color:var(--accent)">Manage Sections</a>.
            </p>
          </div>
          <div style="display:flex;gap:8px;">
            <button type="submit" name="update_student" class="btn btn-primary">
              <i class="ti ti-check"></i> Save Changes
            </button>
            <a href="students.php" class="btn btn-outline">Cancel</a>
          </div>
        </form>
        <?php else: ?>
        <p class="card-title"><i class="ti ti-user-plus"></i> Add Student</p>
        <form method="POST">
          <div class="form-group">
            <label>Student ID <span style="color:var(--red)">*</span></label>
            <input type="text" name="student_id" class="form-control" placeholder="Enter student ID" required>
          </div>
          <div class="form-group">
            <label>Last Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="last_name" class="form-control" placeholder="Enter last name" required>
          </div>
          <div class="form-group">
            <label>First Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="first_name" class="form-control" placeholder="Enter first name" required>
          </div>
          <div class="form-group">
            <label>Middle Initial</label>
            <input type="text" name="middle_initial" class="form-control" placeholder="Enter middle initial">
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control" placeholder="Enter email">
          </div>
          <div class="form-group">
            <label>Course</label>
            <input type="text" name="course" class="form-control" placeholder="e.g. BSIT">
            <p style="font-size:11px;color:var(--text7);margin-top:4px;">
              Used to filter which students show up when enrolling into a section — leave blank if unsure.
            </p>
          </div>
          <div class="form-group">
            <label>Username <span style="color:var(--red)">*</span></label>
            <input type="text" name="username" class="form-control" placeholder="Enter username" required autocomplete="off">
          </div>
          <div class="form-group">
            <label>Password <span style="color:var(--red)">*</span></label>
            <input type="password" name="password" class="form-control" placeholder="Enter password" required autocomplete="new-password">
          </div>
          <div class="form-group">
            <label>Add to Section (optional)</label>
            <select name="section_id" class="form-control">
              <option value="">— None yet —</option>
              <?php foreach ($sections_list as $sec): ?>
              <option value="<?php echo (int)$sec['id']; ?>">
                <?php echo htmlspecialchars($sec['section_name']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" name="add_student" class="btn btn-primary">
            <i class="ti ti-user-plus"></i> Add Student
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── STUDENT LIST PANEL ── -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <p class="card-title" style="margin:0;"><i class="ti ti-list"></i> All Students</p>
        <span style="font-size:12px;color:var(--text7);"><?php echo $students->num_rows; ?> shown</span>
      </div>

      <!-- Search + owner filter -->
      <div class="search-bar">
        <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;">
          <div class="input-wrap" style="flex:1;min-width:160px;">
            <i class="ti ti-search"></i>
            <input type="text" name="search" class="form-control"
              placeholder="Search by name, ID, or username…"
              value="<?php echo htmlspecialchars($search); ?>">
          </div>
          <select name="section" class="form-control" style="max-width:220px;">
            <option value="">All Sections</option>
            <option value="unassigned" <?php echo $filter_section==='unassigned'?'selected':''; ?>>Unassigned Only</option>
            <?php foreach ($sections_list as $sec): ?>
            <option value="<?php echo (int)$sec['id']; ?>" <?php echo ((string)$filter_section===(string)$sec['id'])?'selected':''; ?>>
              <?php echo htmlspecialchars($sec['section_name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline btn-sm">Filter</button>
          <?php if ($search || $filter_section !== null): ?>
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
              <th>Course</th>
              <th>Username</th>
              <th>Section(s)</th>
              <th>Subjects</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($students->num_rows === 0): ?>
            <tr><td colspan="7">
              <div class="empty-state" style="color: var(--text7);">
                <i class="ti ti-users-off"></i>
                <p><?php echo $search ? "No students matched \"$search\"" : "No students found."; ?></p>
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
                        <span style="color:var(--text7)"><?php echo htmlspecialchars($s['middle_initial']); ?></span>
                      <?php endif; ?>
                      <?php if (!empty($s['has_active_referral'])): ?>
                        <span style="display:inline-block;font-size:10px;font-weight:600;padding:2px 7px;border-radius:99px;background:rgba(234,88,12,.12);color:#c2410c;border:1px solid rgba(234,88,12,.25);margin-left:6px;vertical-align:middle;" title="This student has an active referral in the Guidance Appointment System. Last synced: <?php echo htmlspecialchars($s['referral_flag_synced_at'] ?? 'unknown'); ?>">
                          <i class="ti ti-alert-triangle" style="font-size:10px;vertical-align:-1px;"></i> Active Referral
                        </span>
                      <?php endif; ?>
                    </div>
                    <div style="font-size:11px;color:var(--text7);">
                      <?php echo htmlspecialchars($s['email'] ?: '—'); ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="td-mono"><?php echo htmlspecialchars($s['student_id']); ?></td>
              <td style="font-size:12px;color:var(--text7);"><?php echo htmlspecialchars($s['course'] ?: '—'); ?></td>
              <td>
                <span style="font-family:var(--font-mono);font-size:12px;background:var(--bg5);padding:2px 8px;border-radius:5px;color:var(--text7);border:1px solid var(--text7)">
                  <?php echo htmlspecialchars($s['username']); ?>
                </span>
              </td>
              <td style="font-size:12px;">
                <?php if ($s['section_names']): ?>
                  <?php echo htmlspecialchars($s['section_names']); ?>
                <?php else: ?>
                  <span style="color:var(--text7);">Unassigned</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($s['subject_count'] > 0): ?>
                  <span class="badge badge-green"><?php echo $s['subject_count']; ?></span>
                <?php else: ?>
                  <span style="font-size:11px;color:var(--text7);">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <a href="students.php?edit=<?php echo urlencode($s['student_id']); ?>" class="btn btn-sm btn-edit">
                    <i class="ti ti-edit"></i> Edit
                  </a>
                  <button type="button" class="btn btn-sm btn-yellow"
                    onclick="openResetModal('<?php echo htmlspecialchars($s['student_id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['last_name'].', '.$s['first_name'],ENT_QUOTES); ?>')">
                    <i class="ti ti-key"></i>
                  </button>
                  <a href="students.php?delete=<?php echo urlencode($s['student_id']); ?>" class="btn btn-sm btn-delete"
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
</div>

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
        <button type="button" class="btn btn-sm btn-outline" onclick="closeResetModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openResetModal(sid,name){
  document.getElementById('resetStudentId').value=sid;
  document.getElementById('resetModalName').textContent='Student: '+name;
  document.getElementById('resetPw').value='';
  document.getElementById('resetModal').classList.add('open');
}
function closeResetModal(){
  document.getElementById('resetModal').classList.remove('open');
}
document.getElementById('resetModal').addEventListener('click',function(e){
  if(e.target===this) closeResetModal();
});
</script>
</main>
</div>
</body>
</html>