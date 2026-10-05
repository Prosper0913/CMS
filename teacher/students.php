<?php
// ============================================================
//  teacher/students.php
//  Teachers are the only creators of student accounts now.
//
//  Student ID is the one canonical identity. Adding a student
//  always targets one of THIS teacher's own sections:
//    - If the Student ID doesn't exist anywhere yet, this creates
//      the real account (name/course/etc.) and puts them in the
//      chosen section. Default password is auto-generated —
//      last name + the student's last 4 ID digits — never typed
//      in by the teacher.
//    - If the Student ID already belongs to an existing student
//      (created by this teacher or any other), nothing about that
//      student's identity is touched — this just adds them to the
//      chosen section (and backfills any subjects already created
//      for it). Two teachers can each have "their own" enrollment
//      of the same real student without creating a duplicate.
//
//  Teachers can only ADD (create-or-attach) and DELETE students —
//  never edit their details or reset their password. Students
//  manage their own contact info and password from their own
//  Settings page now. Delete only works on a student who is
//  actually in one of this teacher's sections or subjects
//  (teacherOwnsStudent()), and is scoped: it only removes this
//  teacher's own section/subject links. The underlying account is
//  only fully deleted if, after that, no other teacher has any
//  link to the student left at all.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
require_once __DIR__ . '/../includes/sync_to_tooltrack.php';
require_once __DIR__ . '/../includes/sync_to_guidance.php';

$teacher_id  = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

const STUDENT_COURSES = ['BSIT','LAED','BSBA','BSN','FPST','BSA'];

// Contact number is optional. Allow digits, spaces, dashes, parentheses and a
// leading +, with 7-15 digits in total. Returns '' when fine, else the error text.
function student_contact_error(string $c): string {
    if ($c === '') return '';
    $digits = strlen(preg_replace('/\D/', '', $c));
    if (strlen($c) > 20 || !preg_match('/^\+?[0-9\s\-()]+$/', $c) || $digits < 7 || $digits > 15) {
        return "Contact number must be 7-15 digits (spaces, dashes, brackets and a leading + are allowed).";
    }
    return '';
}

// LastName + last 4 digits of the Student ID (digits only; if the ID has
// fewer than 4 digits total, use whatever digits it has). Never shown to
// the teacher as an editable field — generated once, at creation.
function generate_default_password(string $last_name, string $student_id): string {
    $clean = preg_replace('/[^A-Za-z]/', '', $last_name);
    if ($clean === '') $clean = 'Student';
    $clean = ucfirst(strtolower($clean));
    $digits = preg_replace('/\D/', '', $student_id);
    $suffix = substr($digits, -4);
    if ($suffix === '') $suffix = '0000';
    return $clean . $suffix;
}

// ── My own sections, for the "Section" dropdown ────────────────
$my_sections = $conn->prepare(
    "SELECT id, section_name, course, year_level FROM sections
     WHERE teacher_id = ? ORDER BY section_name ASC"
);
$my_sections->bind_param("i", $teacher_id);
$my_sections->execute();
$my_sections_list = $my_sections->get_result()->fetch_all(MYSQLI_ASSOC);

// ── ADD student (create-or-attach) ───────────────────────────
if (isset($_POST['add_student'])) {
    $student_id = trim($_POST['student_id'] ?? '');
    $section_id = (int)($_POST['section_id'] ?? 0);

    // The chosen section must be one of this teacher's own.
    $sec_ok = false;
    foreach ($my_sections_list as $sec) {
        if ((int)$sec['id'] === $section_id) { $sec_ok = true; break; }
    }

    if ($student_id === '') {
        $error_msg = "Please enter a Student ID.";
    } elseif (!$sec_ok) {
        $error_msg = "Please choose one of your own sections.";
    } else {
        $existing = $conn->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
        $existing->bind_param("s", $student_id);
        $existing->execute();
        $found = $existing->get_result()->fetch_assoc();

        if ($found) {
            // ── ATTACH: student already exists — never touch their
            // identity fields here. Just add them to this section.
            $chk = $conn->prepare("SELECT id FROM section_students WHERE section_id=? AND student_id=? LIMIT 1");
            $chk->bind_param("is", $section_id, $student_id);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $error_msg = "That student is already in this section.";
            } else {
                $ins = $conn->prepare("INSERT INTO section_students (section_id, student_id) VALUES (?, ?)");
                $ins->bind_param("is", $section_id, $student_id);
                $ins->execute();
                $backfilled = backfillSubjectEnrollmentsForSection($conn, $section_id, $student_id);
                auto_enroll_student_in_fpst_subjects($conn, $section_id, $student_id);
                push_all_fpst_subjects_for_section($conn, $section_id);
                $success_msg = "Added <strong>" . htmlspecialchars($found['last_name'] . ', ' . $found['first_name'])
                    . "</strong> (existing student) to this section."
                    . ($backfilled > 0 ? " Also enrolled in {$backfilled} existing subject(s) for this section." : "");
            }
        } else {
            // ── CREATE: brand-new Student ID — make the real account.
            $last_name      = trim($_POST['last_name'] ?? '');
            $first_name     = trim($_POST['first_name'] ?? '');
            $middle_initial = trim($_POST['middle_initial'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $contact        = trim($_POST['contact_number'] ?? '');
            $gender         = trim($_POST['gender'] ?? '');
            $contact_db     = $contact !== '' ? $contact : null;
            $gender_db      = $gender  !== '' ? $gender  : null;
            $course         = trim($_POST['course'] ?? '');

            if ($last_name === '' || $first_name === '') {
                $error_msg = "Last name and first name are required for a new student.";
            } elseif (!in_array($course, STUDENT_COURSES, true)) {
                $error_msg = "Please select a valid course.";
            } elseif (($contact_err = student_contact_error($contact)) !== '') {
                $error_msg = $contact_err;
            } elseif (!in_array($gender, ['', 'Male', 'Female'], true)) {
                $error_msg = "Please choose Male or Female for gender.";
            } else {
                // Student ID doubling as a login must not collide with any
                // teacher/admin username either.
                $clash = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $clash->bind_param("s", $student_id);
                $clash->execute();
                if ($clash->get_result()->fetch_assoc()) {
                    $error_msg = "That Student ID is already used as a login by another account.";
                } else {
                    $password = generate_default_password($last_name, $student_id);
                    $hashed   = password_hash($password, PASSWORD_DEFAULT);
                    $username = $student_id;

                    $conn->begin_transaction();
                    try {
                        $ins = $conn->prepare(
                            "INSERT INTO students
                                (student_id,last_name,first_name,middle_initial,email,contact_number,gender,course,username,password,created_by)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                        );
                        $ins->bind_param("ssssssssssi",
                            $student_id,$last_name,$first_name,$middle_initial,$email,$contact_db,$gender_db,$course,$username,$hashed,$teacher_id
                        );
                        $ins->execute();

                        $ins2 = $conn->prepare("INSERT INTO users (username,password,role,student_id) VALUES (?,?,'student',?)");
                        $ins2->bind_param("sss", $username, $hashed, $student_id);
                        $ins2->execute();

                        $ins3 = $conn->prepare("INSERT INTO section_students (section_id, student_id) VALUES (?, ?)");
                        $ins3->bind_param("is", $section_id, $student_id);
                        $ins3->execute();

                        $conn->commit();

                        backfillSubjectEnrollmentsForSection($conn, $section_id, $student_id);
                        // Same side-effects admin/students.php used to trigger on create.
                        auto_enroll_student_in_fpst_subjects($conn, $section_id, $student_id);
                        push_all_fpst_subjects_for_section($conn, $section_id);
                        push_student_to_guidance($conn, $student_id);

                        $success_msg = "Student <strong>" . htmlspecialchars($last_name . ', ' . $first_name)
                            . "</strong> created and added to this section. "
                            . "Their password is <code>" . htmlspecialchars($password) . "</code> "
                            . "(last name + last 4 digits of their Student ID) — write this down, it won't be shown again.";
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error_msg = "Database error: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Teachers can no longer edit a student's details or reset their password —
// students manage their own contact info and password from their own
// Settings page now. Teachers can still add (create-or-attach) and remove
// students from their own sections/subjects.

// ── DELETE (scoped) — removes this teacher's own links only; the
// underlying account is only fully deleted if, after that, no other
// teacher has any link to this student left. ──
if (isset($_GET['delete'])) {
    $del_id = trim($_GET['delete']);
    if (!teacherOwnsStudent($conn, $teacher_id, $del_id)) {
        $error_msg = "You can only remove students from one of your own sections or subjects.";
    } else {
        $conn->begin_transaction();
        try {
            // This teacher's own subject-scoped data for this student.
            $d1 = $conn->prepare(
                "DELETE FROM score_entries WHERE student_id=? AND subject_id IN
                    (SELECT id FROM subjects WHERE teacher_id=?)"
            );
            $d1->bind_param("si", $del_id, $teacher_id); $d1->execute();

            $d2 = $conn->prepare(
                "DELETE FROM attendance WHERE student_id=? AND subject_id IN
                    (SELECT id FROM subjects WHERE teacher_id=?)"
            );
            $d2->bind_param("si", $del_id, $teacher_id); $d2->execute();

            $d3 = $conn->prepare(
                "DELETE FROM subject_grades WHERE student_id=? AND subject_id IN
                    (SELECT id FROM subjects WHERE teacher_id=?)"
            );
            $d3->bind_param("si", $del_id, $teacher_id); $d3->execute();

            $d4 = $conn->prepare(
                "DELETE FROM subject_enrollments WHERE student_id=? AND subject_id IN
                    (SELECT id FROM subjects WHERE teacher_id=?)"
            );
            $d4->bind_param("si", $del_id, $teacher_id); $d4->execute();

            $d5 = $conn->prepare(
                "DELETE FROM section_students WHERE student_id=? AND section_id IN
                    (SELECT id FROM sections WHERE teacher_id=?)"
            );
            $d5->bind_param("si", $del_id, $teacher_id); $d5->execute();

            $fully_orphaned = !studentHasAnyLinkage($conn, $del_id);
            if ($fully_orphaned) {
                $d6 = $conn->prepare("DELETE FROM users WHERE student_id=?");
                $d6->bind_param("s", $del_id); $d6->execute();
                $d7 = $conn->prepare("DELETE FROM students WHERE student_id=?");
                $d7->bind_param("s", $del_id); $d7->execute();
            }

            $conn->commit();

            if ($fully_orphaned) {
                push_student_deletion_to_tooltrack($del_id);
                push_student_deletion_to_guidance($del_id);
                header("Location: students.php?msg=deleted");
            } else {
                header("Location: students.php?msg=removed");
            }
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Could not remove: " . $e->getMessage();
        }
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
    $msgs = [
        'deleted' => 'Student removed — no other teacher had them, so the account was deleted.',
        'removed' => 'Student removed from your classes. Their account is untouched (still used elsewhere).',
        'updated' => 'Student updated.',
    ];
    $success_msg = $msgs[$_GET['msg']] ?? '';
}

// ── Fetch my students: anyone in one of my sections OR enrolled in
// one of my subjects (covers regular roster members and irregular
// students added to a specific subject only). ──
$search = trim($_GET['search'] ?? '');

// Section filter — must be one of this teacher's own sections.
$filter_section = isset($_GET['section']) && $_GET['section'] !== '' ? (int)$_GET['section'] : null;
if ($filter_section !== null) {
    $owns_it = false;
    foreach ($my_sections_list as $sec) {
        if ((int)$sec['id'] === $filter_section) { $owns_it = true; break; }
    }
    if (!$owns_it) $filter_section = null; // ignore bogus/cross-teacher ids, fall back to unfiltered
}

$where_search = '';
$params = [$teacher_id, $teacher_id, $teacher_id];
$types  = 'iii';
if ($search !== '') {
    $where_search .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.student_id LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($filter_section !== null) {
    $where_search .= " AND EXISTS (SELECT 1 FROM section_students ss2 WHERE ss2.student_id = s.student_id AND ss2.section_id = ?)";
    $params[] = $filter_section;
    $types   .= 'i';
}
$sql = "SELECT DISTINCT s.*,
            (SELECT COUNT(*) FROM subject_enrollments e
             JOIN subjects su ON su.id = e.subject_id
             WHERE e.student_id = s.student_id AND su.teacher_id = ?) AS subject_count
         FROM students s
         WHERE (
            EXISTS (SELECT 1 FROM section_students ss JOIN sections sec ON sec.id=ss.section_id
                    WHERE ss.student_id = s.student_id AND sec.teacher_id = ?)
            OR EXISTS (SELECT 1 FROM subject_enrollments se JOIN subjects sub ON sub.id=se.subject_id
                       WHERE se.student_id = s.student_id AND sub.teacher_id = ?)
         )" . $where_search . " ORDER BY s.last_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result();

$total_stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT s.student_id) AS c FROM students s
     WHERE EXISTS (SELECT 1 FROM section_students ss JOIN sections sec ON sec.id=ss.section_id
                   WHERE ss.student_id = s.student_id AND sec.teacher_id = ?)
        OR EXISTS (SELECT 1 FROM subject_enrollments se JOIN subjects sub ON sub.id=se.subject_id
                   WHERE se.student_id = s.student_id AND sub.teacher_id = ?)"
);
$total_stmt->bind_param("ii", $teacher_id, $teacher_id);
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

<?php $active_nav = 'students'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-users text-accent"></i> My Students</h1>
    <p>Students in your sections and subjects. <?php echo (int)$total_students; ?> total.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i><div><?php echo $success_msg; ?></div></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i><div><?php echo htmlspecialchars($error_msg); ?></div></div>
  <?php endif; ?>

  <?php if (empty($my_sections_list)): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">
    <i class="ti ti-alert-circle"></i>
    <div>You don't have any sections yet. <a href="manage_sections.php" class="text-accent">Create one</a> before adding students.</div>
  </div>
  <?php endif; ?>

  <!-- ── IMPORT CALLOUT — made prominent on purpose, not a small corner link ──
       Note: a plain <span> is used for the "Import" pill instead of a <button>,
       since .btn-primary is width:100% globally and a real <button> nested
       inside this <a> would both fight that width and be invalid HTML
       (interactive-in-interactive). -->
  <a href="import_students.php" class="card" style="display:flex;align-items:center;gap:16px;margin-bottom:20px;text-decoration:none;color:inherit;border:1px solid var(--border2);transition:background .15s;flex-wrap:nowrap;"
     onmouseover="this.style.background='var(--bg6)';" onmouseout="this.style.background='';">
    <div style="flex-shrink:0;width:48px;height:48px;border-radius:50%;background:color-mix(in srgb, var(--bg) 12%, transparent);display:flex;align-items:center;justify-content:center;">
      <i class="ti ti-file-import" style="font-size:22px;color:var(--bg);"></i>
    </div>
    <div style="flex:1 1 auto;min-width:0;">
      <div style="font-weight:600;font-size:15px;">Import Students from CSV/Excel</div>
      <div style="font-size:12.5px;color:var(--text7);margin-top:2px;">Bulk-enroll a whole class at once instead of adding students one at a time.</div>
    </div>
    <span style="flex-shrink:0;display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:var(--radius);background:var(--bg);color:#fff;font-size:13px;font-weight:500;white-space:nowrap;">
      <i class="ti ti-arrow-right"></i> Import
    </span>
  </a>

  <div class="two-col">

    <div>
      <div class="card">
        <p class="card-title"><i class="ti ti-user-plus"></i> Add Student</p>
        <p style="font-size:12px;color:var(--text7);margin-top:-6px;margin-bottom:14px;">
          Enter a Student ID. If it already exists, they're just added to the section
          you pick below — nothing about their record changes. If it's new, you'll fill
          in their details and an account is created.
        </p>
        <form method="POST" id="addStudentForm">
          <div class="form-group">
            <label>Student ID <span class="text-red">*</span></label>
            <input type="text" name="student_id" id="addStudentId" class="form-control"
              placeholder="Enter student ID" required autocomplete="off">
          </div>
          <div id="newStudentFields">
            <p style="font-size:11px;color:var(--text7);margin:-4px 0 12px;">
              Only needed if this Student ID is new. If it already exists, these are ignored.
            </p>
            <div class="form-group">
              <label>Last Name <span class="text-red">*</span></label>
              <input type="text" name="last_name" class="form-control" placeholder="Enter last name">
            </div>
            <div class="form-group">
              <label>First Name <span class="text-red">*</span></label>
              <input type="text" name="first_name" class="form-control" placeholder="Enter first name">
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
              <label>Contact Number</label>
              <input type="tel" name="contact_number" class="form-control" maxlength="20" placeholder="e.g. 09171234567">
            </div>
            <div class="form-group">
              <label>Gender</label>
              <select name="gender" class="form-control">
                <option value="">Select gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="form-group">
              <label>Course <span class="text-red">*</span></label>
              <select name="course" class="form-control">
                <option value="">Select course</option>
                <?php foreach (STUDENT_COURSES as $c): ?>
                <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <p style="font-size:11px;color:var(--text7);margin-top:-8px;margin-bottom:14px;">
              Password is generated automatically — last name + the last 4 digits of the
              Student ID. It's shown once, right after you create the account.
            </p>
          </div>
          <div class="form-group">
            <label>Section <span class="text-red">*</span></label>
            <select name="section_id" class="form-control" required <?php echo empty($my_sections_list) ? 'disabled' : ''; ?>>
              <option value="">Select one of your sections</option>
              <?php foreach ($my_sections_list as $sec): ?>
              <option value="<?php echo (int)$sec['id']; ?>">
                <?php echo htmlspecialchars($sec['section_name'] . ' — ' . $sec['course'] . ' Y' . $sec['year_level']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" name="add_student" class="btn btn-primary" <?php echo empty($my_sections_list) ? 'disabled' : ''; ?>>
            <i class="ti ti-user-plus"></i> Add Student
          </button>
        </form>
      </div>
    </div>

    <!-- ── STUDENT LIST PANEL ── -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <p class="card-title" style="margin:0;"><i class="ti ti-list"></i> All Students</p>
        <span class="black-font"><?php echo $total_students; ?> total</span>
      </div>

      <div class="search-bar">
        <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;">
          <div class="input-wrap" style="flex:1;min-width:160px;">
            <i class="ti ti-search"></i>
            <input type="text" name="search" class="form-control"
              placeholder="Search by name or ID…"
              value="<?php echo htmlspecialchars($search); ?>">
          </div>
          <select name="section" class="form-control" style="max-width:220px;">
            <option value="">All sections</option>
            <?php foreach ($my_sections_list as $sec): ?>
            <option value="<?php echo (int)$sec['id']; ?>" <?php echo $filter_section === (int)$sec['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($sec['section_name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline btn-sm">Search</button>
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
              <th>Subjects</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($students->num_rows === 0): ?>
            <tr><td colspan="4">
              <div class="empty-state">
                <i class="ti ti-users-off"></i>
                <p style="color: var(--text7);"><?php echo $search ? "No students matched \"$search\"" : "No students yet. Add one using the form."; ?></p>
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
                    <div style="font-size:11px;color:var(--text7);">
                      <?php
                        $sub = [$s['email'] ?: '—'];
                        if (!empty($s['contact_number'])) $sub[] = $s['contact_number'];
                        if (!empty($s['gender']))         $sub[] = $s['gender'];
                        echo htmlspecialchars(implode(' · ', $sub));
                      ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="td-mono"><?php echo htmlspecialchars($s['student_id']); ?></td>
              <td>
                <?php if ($s['subject_count'] > 0): ?>
                  <span class="badge badge-green"><?php echo $s['subject_count']; ?> with you</span>
                <?php else: ?>
                  <span style="font-size:11px;color:var(--text7);">Roster only</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions" style="display:flex;gap:6px;">
                  <a href="students.php?delete=<?php echo urlencode($s['student_id']); ?>"
                     class="btn btn-sm btn-delete"
                     onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($s['first_name'])); ?> from your classes? If no other teacher has them, their account is deleted entirely.')">
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

  </div>
</div>

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
