<?php
// ============================================================
//  teacher/manage_sections.php
//  Section dashboard for a teacher's own sections.
//  Teachers are the only creators of sections. Every section belongs to
//  the teacher who made it (sections.teacher_id is never NULL), and names
//  only need to be unique per teacher.
//  Features:
//    - Create / rename / delete your own sections
//    - View students per section
//    - Add an existing student (by Student ID) / remove students
//    - Quick-enroll an entire section into any of your own subjects
//  Brand-new students are created from teacher/students.php.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
require_once __DIR__ . '/../includes/sync_to_tooltrack.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$teacher_id  = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// ── Access helper ─────────────────────────────────────────────
// A teacher can manage a section ONLY if they own it (sections.teacher_id
// = their id). Anyone else's section is off-limits.
function sectionAccessible($conn, $section_id, $teacher_id) {
    $q = $conn->prepare(
        "SELECT id FROM sections WHERE id = ? AND teacher_id = ? LIMIT 1"
    );
    $q->bind_param('ii', $section_id, $teacher_id);
    $q->execute();
    $q->store_result();
    return $q->num_rows > 0;
}

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
// ══════════════════════════════════════════════════════════════
//  POST HANDLERS
// ══════════════════════════════════════════════════════════════

// ── RENAME section ───────────────────────────────────────────
if (isset($_POST['rename_section'])) {
    $sec_id   = (int)$_POST['sec_id'];
    $new_name = trim($_POST['new_name']);
    $new_desc = trim($_POST['new_desc'] ?? '');
    if ($new_name === '') {
        $error_msg = "Section name cannot be empty.";
    } elseif (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "You can only edit sections you created.";
    } else {
        $upd = $conn->prepare("UPDATE sections SET section_name = ?, description = ? WHERE id = ?");
        $upd->bind_param('ssi', $new_name, $new_desc, $sec_id);
        $upd->execute();
        header("Location: manage_sections.php?sec={$sec_id}&msg=updated");
        exit;
    }
}

// ── DELETE section ───────────────────────────────────────────
if (isset($_POST['delete_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    if (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "You can only delete sections you created.";
    } else {
    // Remove from section_students, then delete section
    $conn->prepare("DELETE FROM section_students WHERE section_id = ?")->bind_param('i', $sec_id) && null;
    $d1 = $conn->prepare("DELETE FROM section_students WHERE section_id = ?");
    $d1->bind_param('i', $sec_id);
    $d1->execute();
    $d1b = $conn->prepare("DELETE FROM section_access_requests WHERE section_id = ?");
    $d1b->bind_param('i', $sec_id);
    $d1b->execute();
    $d2 = $conn->prepare("DELETE FROM sections WHERE id = ?");
    $d2->bind_param('i', $sec_id);
    $d2->execute();
    // Note: subject_enrollments rows that had this section_id are left intact
    // (students stay enrolled in subjects; only the section tag is orphaned)
    header("Location: manage_sections.php?msg=deleted");
    exit;
    }
}

$nav_subs = getTeacherSubjects($conn, $teacher_id);
$type_cfg = [
    'General Education'      => ['color'=>'#1e5f4e','label'=>'GE'],
    'Professional Education' => ['color'=>'#1e5f4e','label'=>'PE'],
    'Major Subject'          => ['color'=>'#1e5f4e','label'=>'MAJ'],
];

// ── ADD an existing student to one of my sections ────────────
// Any existing student (found by Student ID) can be added — students are
// shared across teachers, and adding one only creates THIS teacher's own
// roster link; it never changes the student's record. Brand-new students
// are created from the Students page instead.
if (isset($_POST['add_to_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $sid    = trim($_POST['student_id'] ?? '');
    if ($sid === '') {
        $error_msg = "Please enter a Student ID.";
    } elseif (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "Only the section's creator can add students to it.";
    } else {
        $sq = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? LIMIT 1");
        $sq->bind_param('s', $sid);
        $sq->execute();
        if (!$sq->get_result()->fetch_assoc()) {
            $error_msg = "No student with that ID exists yet. Create them from the <a href=\"students.php\" class=\"text-accent\">Students</a> page first.";
        } else {
            $chk = $conn->prepare(
                "SELECT id FROM section_students WHERE section_id = ? AND student_id = ? LIMIT 1"
            );
            $chk->bind_param('is', $sec_id, $sid);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $error_msg = "Student is already in this section.";
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO section_students (section_id, student_id) VALUES (?, ?)"
                );
                $ins->bind_param('is', $sec_id, $sid);
                $ins->execute();
                backfillSubjectEnrollmentsForSection($conn, $sec_id, $sid);
                auto_enroll_student_in_fpst_subjects($conn, $sec_id, $sid);
                push_all_fpst_subjects_for_section($conn, $sec_id);
                header("Location: manage_sections.php?sec={$sec_id}&msg=student_added");
                exit;
            }
        }
    }
}


// ── REMOVE student from section ──────────────────────────────
if (isset($_POST['remove_from_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $sid    = trim($_POST['student_id']);
    if (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "Only the section's creator can remove students from it.";
    } else {
    $del = $conn->prepare(
        "DELETE FROM section_students WHERE section_id = ? AND student_id = ?"
    );
    $del->bind_param('is', $sec_id, $sid);
    $del->execute();
    header("Location: manage_sections.php?sec={$sec_id}&msg=student_removed");
    exit;
    }
}

// ── BULK ENROLL section into subject ─────────────────────────
if (isset($_POST['enroll_into_subject'])) {
    $sec_id     = (int)$_POST['sec_id'];
    $subject_id = (int)$_POST['subject_id'];
    // Verify teacher owns this subject
    $own = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ? LIMIT 1");
    $own->bind_param('ii', $subject_id, $teacher_id);
    $own->execute();
    $own->store_result();
    if ($own->num_rows === 0) {
        $error_msg = "Subject not found or access denied.";
    } elseif (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "You can only enroll sections that you own.";
    } else {
        $sq = $conn->prepare("SELECT student_id FROM section_students WHERE section_id = ?");
        $sq->bind_param('i', $sec_id);
        $sq->execute();
        $sr    = $sq->get_result();
        $added = 0;
        while ($row = $sr->fetch_assoc()) {
            $sid = $row['student_id'];
            $e1  = $conn->prepare(
                "INSERT IGNORE INTO subject_enrollments (subject_id, student_id, section_id) VALUES (?, ?, ?)"
            );
            $e1->bind_param('isi', $subject_id, $sid, $sec_id);
            $e1->execute();
            $e2 = $conn->prepare(
                "INSERT IGNORE INTO subject_grades (subject_id, student_id) VALUES (?, ?)"
            );
            $e2->bind_param('is', $subject_id, $sid);
            $e2->execute();
            $added++;
        }
        $success_msg = "Enrolled <strong>{$added}</strong> student(s) into the selected subject.";
        header("Location: manage_sections.php?sec={$sec_id}&msg=enrolled&count={$added}");
        exit;
    }
}

// ── CREATE section ───────────────────────────────────────────
// Teachers are the only creators of sections. A section is always
// owned by the teacher who makes it (teacher_id is never NULL), and
// names only need to be unique per teacher — two teachers can each
// have their own "BSIT1-A".
if (isset($_POST['create_section'])) {
    $name   = trim($_POST['section_name'] ?? '');
    $desc   = trim($_POST['section_desc'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $year   = (int)($_POST['year_level'] ?? 1);
    $sy     = trim($_POST['school_year'] ?? '');

    if ($name === '') {
        $error_msg = "Section name is required.";
    } elseif (!in_array($course, ['BSIT','LAED','BSBA','BSN','FPST','BSA'], true)) {
        $error_msg = "Please select a valid course.";
    } elseif ($year < 1 || $year > 6) {
        $error_msg = "Year level must be between 1 and 6.";
    } else {
        $chk = $conn->prepare("SELECT id FROM sections WHERE section_name = ? AND teacher_id = ? LIMIT 1");
        $chk->bind_param('si', $name, $teacher_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "You already have a section named <strong>" . htmlspecialchars($name) . "</strong>.";
        } else {
            $ins = $conn->prepare(
                "INSERT INTO sections (section_name, description, course, year_level, school_year, teacher_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->bind_param('sssisii', $name, $desc, $course, $year, $sy, $teacher_id, $teacher_id);
            $ins->execute();
            header("Location: manage_sections.php?sec=" . $conn->insert_id . "&msg=created");
            exit;
        }
    }
}

// ══════════════════════════════════════════════════════════════
//  FLASH MESSAGES (from PRG redirects)
// ══════════════════════════════════════════════════════════════
switch ($_GET['msg'] ?? '') {
    case 'created':         $success_msg = "Section created successfully."; break;
    case 'updated':         $success_msg = "Section updated."; break;
    case 'deleted':         $success_msg = "Section deleted. Students remain enrolled in any subjects they were in."; break;
    case 'student_added':   $success_msg = "Student added to section."; break;
    case 'student_removed': $success_msg = "Student removed from section."; break;
    case 'enrolled':
        $cnt = (int)($_GET['count'] ?? 0);
        $success_msg = "Enrolled <strong>{$cnt}</strong> student(s) into the selected subject."; break;
}

// ══════════════════════════════════════════════════════════════
//  DATA
// ══════════════════════════════════════════════════════════════

// Active section panel (from GET)
$active_sec_id = (int)($_GET['sec'] ?? 0);
$view = ($_GET['view'] ?? 'roster') === 'analytics' ? 'analytics' : 'roster';

// My sections, with student counts. Sections are always teacher-owned now,
// so there's nothing to browse across teachers.
$sections_stmt = $conn->prepare(
    "SELECT s.id, s.section_name, s.description, s.teacher_id, s.course, s.year_level, s.school_year,
            COUNT(ss.student_id) AS student_count
     FROM sections s
     LEFT JOIN section_students ss ON ss.section_id = s.id
     WHERE s.teacher_id = ?
     GROUP BY s.id, s.section_name, s.description, s.teacher_id, s.course, s.year_level, s.school_year
     ORDER BY s.section_name ASC"
);
$sections_stmt->bind_param('i', $teacher_id);
$sections_stmt->execute();
$all_sections = $sections_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$my_sections  = $all_sections;

// Active section data (only ever one of my own sections)
$active_section = null;
$access_denied  = false;
$section_students_list = [];
if ($active_sec_id) {
    foreach ($all_sections as $s) {
        if ((int)$s['id'] === $active_sec_id) { $active_section = $s; break; }
    }
    if ($active_section) {
        $ss_res = $conn->prepare(
            "SELECT s.student_id, s.last_name, s.first_name, s.middle_initial
             FROM section_students ss
             JOIN students s ON ss.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
             WHERE ss.section_id = ?
             ORDER BY s.last_name ASC, s.first_name ASC"
        );
        $ss_res->bind_param('i', $active_sec_id);
        $ss_res->execute();
        $section_students_list = $ss_res->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Analytics for the active section (computed only when that tab is open) ──
$analytics_subjects = [];
$analytics_overall  = ['avg_grade' => null, 'attendance_rate' => null, 'passing_rate' => null, 'student_count' => 0];
if ($active_section && $view === 'analytics') {
    $subs = $conn->prepare(
        "SELECT id, subject_code, subject_name FROM subjects
         WHERE teacher_id = ? AND TRIM(section) = TRIM(?) AND is_active = 1
         ORDER BY subject_name ASC"
    );
    $subs->bind_param('is', $teacher_id, $active_section['section_name']);
    $subs->execute();
    $subject_rows = $subs->get_result()->fetch_all(MYSQLI_ASSOC);

    $sum_grade = 0; $n_grade = 0; $n_pass = 0;
    $sum_present = 0; $n_att = 0;

    foreach ($subject_rows as $subj) {
        $sub_id = $subj['id'];

        $gq = $conn->prepare("SELECT final_grade FROM subject_grades WHERE subject_id = ? AND final_grade IS NOT NULL AND final_grade > 0");
        $gq->bind_param('i', $sub_id);
        $gq->execute();
        $grades  = array_column($gq->get_result()->fetch_all(MYSQLI_ASSOC), 'final_grade');
        $avg     = $grades ? array_sum($grades) / count($grades) : null;
        $passing = $grades ? count(array_filter($grades, fn($g) => $g >= 75)) : 0;

        $aq = $conn->prepare("SELECT status, COUNT(*) AS c FROM attendance WHERE subject_id = ? GROUP BY status");
        $aq->bind_param('i', $sub_id);
        $aq->execute();
        $att_counts  = $aq->get_result()->fetch_all(MYSQLI_ASSOC);
        $att_total   = 0;
        $att_present = 0;
        foreach ($att_counts as $row) {
            $att_total += (int)$row['c'];
            if (in_array($row['status'], ['Present', 'Late'], true)) $att_present += (int)$row['c'];
        }
        $att_rate = $att_total > 0 ? ($att_present / $att_total) * 100 : null;

        $eq = $conn->prepare("SELECT COUNT(*) AS c FROM subject_enrollments WHERE subject_id = ?");
        $eq->bind_param('i', $sub_id);
        $eq->execute();
        $enrolled = (int)$eq->get_result()->fetch_assoc()['c'];

        $analytics_subjects[] = [
            'subject_code' => $subj['subject_code'],
            'subject_name' => $subj['subject_name'],
            'enrolled'     => $enrolled,
            'avg_grade'    => $avg,
            'passing'      => $passing,
            'graded_count' => count($grades),
            'att_rate'     => $att_rate,
        ];

        if ($grades)        { $sum_grade += array_sum($grades); $n_grade += count($grades); $n_pass += $passing; }
        if ($att_total > 0) { $sum_present += $att_present; $n_att += $att_total; }
    }

    $analytics_overall['student_count']   = count($section_students_list);
    $analytics_overall['avg_grade']       = $n_grade > 0 ? $sum_grade / $n_grade : null;
    $analytics_overall['passing_rate']    = $n_grade > 0 ? ($n_pass / $n_grade) * 100 : null;
    $analytics_overall['attendance_rate'] = $n_att > 0 ? ($sum_present / $n_att) * 100 : null;
}

// Teacher's subjects for bulk-enroll dropdown
$subj_res = $conn->prepare(
    "SELECT id, subject_code, subject_name, section
     FROM subjects WHERE teacher_id = ? AND is_active = 1
     ORDER BY subject_name ASC"
);
$subj_res->bind_param('i', $teacher_id);
$subj_res->execute();
$teacher_subjects_list = $subj_res->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary counts (mine only)
$total_sections = count($all_sections);
$tis = $conn->prepare(
    "SELECT COUNT(DISTINCT ss.student_id) AS c FROM section_students ss
     JOIN sections sec ON sec.id = ss.section_id WHERE sec.teacher_id = ?"
);
$tis->bind_param('i', $teacher_id);
$tis->execute();
$total_in_sections = $tis->get_result()->fetch_assoc()['c'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Manage Sections — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroomv2/assets/style.css">

</head>
<body class="page-teacher-manage_sections">
<div class="app-shell">


<!-- ── NAVBAR ── -->
<?php $active_nav = 'sections'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <!-- ── ALERTS ── -->
  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- ── STAT CHIPS ── -->
  <div class="stat-chips">
    <div class="stat-chip a">
      <div class="stat-chip-val"><?php echo $total_sections; ?></div>
      <div class="stat-chip-lbl">Total Sections</div>
    </div>
    <div class="stat-chip g">
      <div class="stat-chip-val"><?php echo $total_in_sections; ?></div>
      <div class="stat-chip-lbl">Students in Sections</div>
    </div>
  </div>

  <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <button type="button" class="btn btn-primary" onclick="openCreateModal()">
      <i class="ti ti-plus"></i> New Section
    </button>
  </div>

  <hr class="thin-line" style="margin-bottom:20px;">

  <!-- ── MY SECTIONS TABLE ── -->
  <div class="card" style="margin-bottom:24px;">
    <p class="card-title" style="margin-bottom:14px;"><i class="ti ti-building-community"></i> My Sections</p>

    <?php if (empty($my_sections)): ?>
      <div class="empty-state">
        <i class="ti ti-building-off"></i>
        <p style="color: var(--text7);">No sections yet. Use <strong>New Section</strong> to create your first one.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Section</th>
            <th>Course</th>
            <th>Year</th>
            <th>School Year</th>
            <th>Students</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($my_sections as $sec): ?>
          <tr style="<?php echo (int)$sec['id'] === $active_sec_id ? 'background:var(--bg6);' : ''; ?>">
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <i class="ti ti-users" style="color:var(--text7);"></i>
                <span style="font-weight:500;"><?php echo htmlspecialchars($sec['section_name']); ?></span>
              </div>
              <?php if ($sec['description']): ?>
                <div style="font-size:11px;color:var(--text7);margin-top:2px;"><?php echo htmlspecialchars($sec['description']); ?></div>
              <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($sec['course'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars((string)($sec['year_level'] ?: '—')); ?></td>
            <td><?php echo htmlspecialchars($sec['school_year'] ?: '—'); ?></td>
            <td><span class="badge badge-blue"><?php echo (int)$sec['student_count']; ?></span></td>
            <td>
              <div class="td-actions" style="display:flex;gap:6px;">
                <a href="manage_sections.php?sec=<?php echo (int)$sec['id']; ?>" class="btn btn-sm btn-outline">
                  <i class="ti ti-settings"></i> Manage
                </a>
                <button type="button" class="btn btn-sm btn-delete"
                  onclick="openDeleteModal('<?php echo (int)$sec['id']; ?>','<?php echo htmlspecialchars(addslashes($sec['section_name'])); ?>')">
                  <i class="ti ti-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($active_section): ?>

      <!-- ── TAB STRIP — made visually distinct so Roster/Analytics are easy to tell apart ── -->
      <div class="tab-strip" style="display:flex;gap:10px;margin-bottom:24px;">
        <a href="manage_sections.php?sec=<?php echo $active_sec_id; ?>&view=roster"
           style="display:flex;align-items:center;gap:8px;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;transition:all .15s;
                  <?php echo $view === 'roster'
                      ? 'background:var(--bg);color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15);'
                      : 'background:var(--bg6);color:var(--text7);border:1px solid var(--border2);'; ?>">
          <i class="ti ti-list" style="font-size:17px;"></i> Roster
        </a>
        <a href="manage_sections.php?sec=<?php echo $active_sec_id; ?>&view=analytics"
           style="display:flex;align-items:center;gap:8px;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;transition:all .15s;
                  <?php echo $view === 'analytics'
                      ? 'background:var(--bg);color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15);'
                      : 'background:var(--bg6);color:var(--text7);border:1px solid var(--border2);'; ?>">
          <i class="ti ti-chart-bar" style="font-size:17px;"></i> Analytics
        </a>
      </div>

      <!-- ── SECTION HERO ── -->
      <div class="section-hero" style="margin-bottom:24px;background:linear-gradient(135deg,rgba(19, 95, 63, 0.75) 0%,transparent 100%);border-color:rgba(36, 90, 31, 0.2);">
        <div style="flex:1;">
          <div class="sh-name"><?php echo htmlspecialchars($active_section['section_name']); ?></div>
          <?php if ($active_section['description']): ?>
            <div class="sh-desc"><?php echo htmlspecialchars($active_section['description']); ?></div>
          <?php endif; ?>
          <div class="sh-meta">
            <span class="sh-meta-item">
              <i class="ti ti-users"></i>
              <?php echo count($section_students_list); ?> student<?php echo count($section_students_list) !== 1 ? 's' : ''; ?>
            </span>
            <span class="sh-meta-item">
              <i class="ti ti-id"></i>
              Section ID: <?php echo $active_section['id']; ?>
            </span>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;align-items:center;">
          <button class="btn btn-outline btn-sm" onclick="openEditModal()">
            <i class="ti ti-edit"></i> Edit
          </button>
          <button class="btn btn-danger btn-sm"
            onclick="openDeleteModal('<?php echo $active_sec_id; ?>','<?php echo htmlspecialchars(addslashes($active_section['section_name'])); ?>')">
            <i class="ti ti-trash"></i> Delete
          </button>
        </div>
      </div>

      <?php if ($view === 'roster'): ?>

      <!-- ── BULK ENROLL INTO SUBJECT ── -->
      <?php if ($teacher_subjects_list): ?>
      <div class="card">
        <p class="card-title">
          <i class="ti ti-rocket"></i> Bulk Enroll into Subject
          <span style="font-size:11px;font-weight:400;color:var(--text7);margin-left:4px;">
            Enroll all <?php echo count($section_students_list); ?> students at once
          </span>
        </p>
        <?php if (empty($section_students_list)): ?>
          <p style="font-size:13px;color:var(--text3);">Add students to this section first before enrolling them into a subject.</p>
        <?php else: ?>
        <form method="POST">
          <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
          <div class="enroll-strip">
            <i class="ti ti-books" style="color:var(--bg5);font-size:16px;flex-shrink:0;"></i>
            <label>Enroll all students into:</label>
            <select name="subject_id" class="form-control" required>
              <option value="">— Choose a subject —</option>
              <?php foreach ($teacher_subjects_list as $subj): ?>
              <option value="<?php echo $subj['id']; ?>">
                <?php echo htmlspecialchars($subj['subject_code'] . ' — ' . $subj['subject_name'] . ' (' . $subj['section'] . ')'); ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" name="enroll_into_subject" class="btn btn-green"
              onclick="return confirm('Enroll all <?php echo count($section_students_list); ?> students from this section into the selected subject?')">
              <i class="ti ti-users-plus"></i> Enroll Section
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- ── STUDENT ROSTER ── -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
          <p class="card-title" style="margin:0;">
            <i class="ti ti-list-details"></i> Student Roster
            <span style="font-size:11px;font-weight:400;color:var(--text7);margin-left:4px;">
              <?php echo count($section_students_list); ?> student<?php echo count($section_students_list) !== 1 ? 's' : ''; ?>
            </span>
          </p>
          <button class="btn btn-primary btn-sm" onclick="openAddStudentModal()">
            <i class="ti ti-user-plus"></i> Add Student
          </button>
        </div>

        <!-- Search roster -->
        <?php if (count($section_students_list) > 4): ?>
        <div class="search-wrap">
          <i class="ti ti-search" style="color: var(--text6);"></i>
          <input type="text" id="rosterSearch" class="form-control" placeholder="Search students…" oninput="filterRoster()">
        </div>
        <?php endif; ?>

        <?php if (empty($section_students_list)): ?>
          <div class="empty-state">
            <i class="ti ti-user-off"></i>
            <p>No students in this section yet.<br>Use the Add Student button to add some.</p>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th>
                <th>Student ID</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="rosterTable">
              <?php foreach ($section_students_list as $st):
                $initials = strtoupper(substr($st['last_name'],0,1) . substr($st['first_name'],0,1));
              ?>
              <tr data-name="<?php echo strtolower($st['last_name'] . ' ' . $st['first_name'] . ' ' . $st['student_id']); ?>">
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div class="avatar"><?php echo $initials; ?></div>
                    <div>
                      <div style="font-weight:500;"><?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name']); ?></div>
                      <?php if ($st['middle_initial']): ?>
                        <div style="font-size:11px;color:var(--text7);"><?php echo htmlspecialchars($st['middle_initial']); ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="td-mono"><?php echo htmlspecialchars($st['student_id']); ?></td>
                <td style="text-align:right;">
                  <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($st['first_name'])); ?> from this section?')">
                    <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($st['student_id']); ?>">
                    <button type="submit" name="remove_from_section" class="btn btn-xs btn-danger">
                      <i class="ti ti-user-minus"></i> Remove
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php else: /* ── ANALYTICS VIEW ── */ ?>

      <div class="stats-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:20px;">
        <div class="stat-card" style="width:auto;min-width:0;place-items:stretch;">
          <div class="stat-label">Students</div>
          <div class="stat-value"><?php echo (int)$analytics_overall['student_count']; ?></div>
        </div>
        <div class="stat-card" style="width:auto;min-width:0;place-items:stretch;">
          <div class="stat-label">Average Grade</div>
          <div class="stat-value"><?php echo $analytics_overall['avg_grade'] !== null ? number_format($analytics_overall['avg_grade'], 1) : '—'; ?></div>
        </div>
        <div class="stat-card" style="width:auto;min-width:0;place-items:stretch;">
          <div class="stat-label">Passing Rate</div>
          <div class="stat-value"><?php echo $analytics_overall['passing_rate'] !== null ? number_format($analytics_overall['passing_rate'], 1) . '%' : '—'; ?></div>
        </div>
        <div class="stat-card" style="width:auto;min-width:0;place-items:stretch;">
          <div class="stat-label">Attendance Rate</div>
          <div class="stat-value"><?php echo $analytics_overall['attendance_rate'] !== null ? number_format($analytics_overall['attendance_rate'], 1) . '%' : '—'; ?></div>
        </div>
      </div>

      <div class="card">
        <p class="card-title"><i class="ti ti-books"></i> By Subject</p>
        <?php if (empty($analytics_subjects)): ?>
          <div class="empty-state">
            <i class="ti ti-chart-bar"></i>
            <p>No subjects have been created for this section yet.<br>Analytics will appear once you add subjects and start recording grades/attendance.</p>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Subject</th>
                <th>Enrolled</th>
                <th>Graded</th>
                <th>Average</th>
                <th>Passing</th>
                <th>Attendance</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($analytics_subjects as $as): ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($as['subject_code']); ?></strong> — <?php echo htmlspecialchars($as['subject_name']); ?></td>
                <td><?php echo (int)$as['enrolled']; ?></td>
                <td><?php echo (int)$as['graded_count']; ?></td>
                <td><?php echo $as['avg_grade'] !== null ? number_format($as['avg_grade'], 1) : '—'; ?></td>
                <td><?php echo $as['graded_count'] > 0 ? (int)$as['passing'] . ' / ' . (int)$as['graded_count'] : '—'; ?></td>
                <td><?php echo $as['att_rate'] !== null ? number_format($as['att_rate'], 1) . '%' : '—'; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; /* end roster/analytics view */ ?>

  <?php endif; /* end $active_section */ ?>

</div><!-- end page-wrap -->

<!-- ══════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════ -->

<!-- Edit Section Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <h3><i class="ti ti-edit text-accent"></i> Edit Section</h3>
    <p class="modal-sub">Update the section name or description.</p>
    <form method="POST">
      <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
      <div class="form-group">
        <label>Section Name <span class="text-red">*</span></label>
        <input type="text" name="new_name" class="form-control" id="editNameInput"
          value="<?php echo htmlspecialchars($active_section['section_name'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <input type="text" name="new_desc" class="form-control" id="editDescInput"
          value="<?php echo htmlspecialchars($active_section['description'] ?? ''); ?>">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="rename_section" class="btn btn-primary btn-fill">
          <i class="ti ti-check"></i> Save Changes
        </button>
        <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3><i class="ti ti-alert-triangle text-red"></i> Delete Section</h3>
    <p class="modal-sub" id="deleteModalMsg">Are you sure? Students in this section will not be deleted — they will remain enrolled in any subjects they were added to.</p>
    <form method="POST">
      <input type="hidden" name="sec_id" id="deleteSectionId">
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="delete_section" class="btn btn-danger btn-fill">
          <i class="ti ti-trash"></i> Yes, Delete Section
        </button>
        <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Create Section Modal -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <h3><i class="ti ti-plus" style="color:var(--text6);"></i> New Section</h3>
    <p class="modal-sub">Sections you create are yours alone — other teachers can have their own section with the same name.</p>
    <form method="POST">
      <div class="form-group">
        <label>Section name <span class="text-red">*</span></label>
        <input type="text" name="section_name" class="form-control" required maxlength="80" placeholder="e.g. BSIT1-A">
      </div>
      <div class="form-group">
        <label>Course <span class="text-red">*</span></label>
        <select name="course" class="form-control" required>
          <option value="">Select course</option>
          <?php foreach (['BSIT','LAED','BSBA','BSN','FPST','BSA'] as $c): ?>
          <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Year level</label>
        <input type="number" name="year_level" class="form-control" value="1" min="1" max="6">
      </div>
      <div class="form-group">
        <label>School year</label>
        <input type="text" name="school_year" class="form-control" placeholder="e.g. 2026-2027">
      </div>
      <div class="form-group">
        <label>Description (optional)</label>
        <input type="text" name="section_desc" class="form-control" maxlength="255">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="create_section" class="btn btn-primary btn-fill">
          <i class="ti ti-check"></i> Create Section
        </button>
        <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="addStudentModal">
  <div class="modal">
    <h3><i class="ti ti-user-plus" style="color:var(--bg5);"></i> Add Student to Section</h3>
    <p class="modal-sub">
      Enter the Student ID of an existing student to add them to
      <strong><?php echo htmlspecialchars($active_section['section_name'] ?? ''); ?></strong>.
      Brand-new students are created from the <a href="students.php" class="text-accent">Students</a> page.
    </p>
    <form method="POST">
      <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
      <div class="form-group">
        <label>Student ID</label>
        <input type="text" name="student_id" id="addStudentId" class="form-control" required
          autocomplete="off" placeholder="Enter student ID">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="add_to_section" class="btn btn-primary btn-fill">
          <i class="ti ti-user-plus"></i> Add to Section
        </button>
        <button type="button" class="btn btn-outline" onclick="closeAddStudentModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>
    <div style="text-align:center;margin-top:20px;">
      <p style="font-size:12px;color:var(--text7);margin-top:-14px;margin-bottom:20px;">
      You create and own your sections. Students are shared across teachers by Student ID —
      adding one only links them to your section.
      </p>
    </div>

<script>
// ── Modal helpers ────────────────────────────────────
function openEditModal()    { document.getElementById('editModal').classList.add('open'); }
function closeEditModal()   { document.getElementById('editModal').classList.remove('open'); }
function openAddStudentModal()    { document.getElementById('addStudentModal').classList.add('open'); document.getElementById('addStudentId')?.focus(); }
function closeAddStudentModal()   { document.getElementById('addStudentModal').classList.remove('open'); }
function openDeleteModal(id, name) {
  document.getElementById('deleteSectionId').value = id;
  document.getElementById('deleteModalMsg').innerHTML =
    'Delete section <strong>' + name + '</strong>? Students will not be deleted — they remain enrolled in any subjects they were added to.';
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
function openCreateModal()  { document.getElementById('createModal').classList.add('open'); }
function closeCreateModal() { document.getElementById('createModal').classList.remove('open'); }

// Close modals on backdrop click
['editModal','deleteModal','addStudentModal','createModal'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

// ── Roster live search ───────────────────────────────
function filterRoster() {
  const q = document.getElementById('rosterSearch').value.toLowerCase();
  document.querySelectorAll('#rosterTable tr').forEach(row => {
    row.style.display = row.dataset.name?.includes(q) ? '' : 'none';
  });
}

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