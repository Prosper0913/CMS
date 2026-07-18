<?php
// ============================================================
//  teacher/add_subject.php  — v3 (section-aware enrollment)
//
//  Changes from v2:
//    - Enrollment panel has two tabs: "By Section" and "Individual"
//    - Enrolling by section bulk-enrolls all students in that section
//    - ?prefill_section=ID pre-selects a section (from manage_sections.php)
//    - All existing behaviour (weights, type selector, etc.) preserved
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

$teacher_id     = $_SESSION['user_id'];
$success_msg    = '';
$error_msg      = '';
$new_subject_id = null;

// ── SAVE new subject ─────────────────────────────────────────
if (isset($_POST['save_subject'])) {
    $subject_code    = trim($_POST['subject_code']);
    $subject_name    = trim($_POST['subject_name']);
    $section         = trim($_POST['section']);
    $subject_type    = trim($_POST['subject_type']);
    $school_year     = trim($_POST['school_year']);
    $semester        = trim($_POST['semester']);
    $exam_pct        = (float)$_POST['exam_pct'];
    $written_pct     = (float)$_POST['written_pct'];
    $performance_pct = (float)$_POST['performance_pct'];
    $attendance_pct  = (float)$_POST['attendance_pct'];

    // Enrollees can come from:
    //   a) individual checkboxes  (enrollees[])
    //   b) section bulk-enroll    (enroll_section_id)
    $enroll_mode   = $_POST['enroll_mode'] ?? 'individual'; // 'section' or 'individual'
    $section_id_en = (int)($_POST['enroll_section_id'] ?? 0);
    $enrollees     = $_POST['enrollees'] ?? [];

    $valid_types = ['General Education','Professional Education','Major Subject'];
    $valid_sems  = ['1st','2nd','Summer'];
    $total_pct   = $exam_pct + $written_pct + $performance_pct;

    if ($subject_code===''||$subject_name==='') {
        $error_msg = "Subject code and name are required.";
    } elseif (!in_array($subject_type,$valid_types)) {
        $error_msg = "Please select a valid subject type.";
    } elseif (!in_array($semester,$valid_sems)) {
        $error_msg = "Please select a valid semester.";
    } elseif (round($total_pct,2) !== 100.00) {
        $error_msg = "Grade weights must total exactly 100%. Current total: <strong>{$total_pct}%</strong>";
    } elseif ($attendance_pct <= 0) {
        $error_msg = "Attendance % must be greater than 0.";
    } elseif ($attendance_pct >= $performance_pct) {
        $error_msg = "Attendance % ({$attendance_pct}%) must be less than Performance % ({$performance_pct}%).";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Insert subject
            $ins = $conn->prepare(
                "INSERT INTO subjects
                   (teacher_id,subject_code,subject_name,section,subject_type,
                    school_year,semester,exam_pct,written_pct,performance_pct,attendance_pct)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->bind_param("issssssdddd",
                $teacher_id,$subject_code,$subject_name,$section,$subject_type,
                $school_year,$semester,$exam_pct,$written_pct,$performance_pct,$attendance_pct
            );
            $ins->execute();
            $new_subject_id = $conn->insert_id;

            // 2. Build final list of student IDs to enroll
            $to_enroll   = []; // [ ['student_id'=>..., 'section_id'=>...] ]

            if ($enroll_mode === 'section' && $section_id_en > 0) {
                // ── ACCESS CONTROL (server-side, do NOT skip this) ──
                // Never trust that $section_id_en is one of "my" sections
                // just because the dropdown only showed my own — a POST
                // request can be crafted/replayed with ANY section_id,
                // bypassing whatever the UI displayed. Re-check ownership
                // here, right before using it, with the same rule as the
                // display query above (owner OR legacy/no-owner).
                $sec_chk = $conn->prepare(
                    "SELECT id FROM sections
                     WHERE id = ? AND (teacher_id = ? OR teacher_id IS NULL)
                     LIMIT 1"
                );
                $sec_chk->bind_param('ii', $section_id_en, $teacher_id);
                $sec_chk->execute();
                $sec_chk->store_result();

                if ($sec_chk->num_rows === 0) {
                    // Not their section — abort the whole save rather than
                    // silently enrolling nobody, so the teacher notices.
                    throw new Exception("You don't have access to that section.");
                }

                // Pull all students in that section
                $sq = $conn->prepare(
                    "SELECT student_id FROM section_students WHERE section_id = ?"
                );
                $sq->bind_param('i', $section_id_en);
                $sq->execute();
                $srows = $sq->get_result();
                while ($sr = $srows->fetch_assoc()) {
                    $to_enroll[] = ['student_id'=>$sr['student_id'],'section_id'=>$section_id_en];
                }
            } else {
                // Individual checkboxes
                foreach ($enrollees as $sid) {
                    $sid = trim($sid);
                    if ($sid==='') continue;
                    $to_enroll[] = ['student_id'=>$sid,'section_id'=>null];
                }
            }

            // 3. Enroll each student
            foreach ($to_enroll as $en_row) {
                $sid    = $en_row['student_id'];
                $sec_en = $en_row['section_id'];
                $e1 = $conn->prepare(
                    "INSERT IGNORE INTO subject_enrollments (subject_id,student_id,section_id)
                     VALUES (?,?,?)"
                );
                $e1->bind_param("isi",$new_subject_id,$sid,$sec_en);
                $e1->execute();
                $e2 = $conn->prepare(
                    "INSERT IGNORE INTO subject_grades (subject_id,student_id) VALUES (?,?)"
                );
                $e2->bind_param("is",$new_subject_id,$sid);
                $e2->execute();
            }

            $conn->commit();
            $enrolled_count = count($to_enroll);
            $success_msg = "Subject <strong>{$subject_code} — {$subject_name}</strong> deployed "
                         . "with {$enrolled_count} student(s) enrolled.";

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error saving subject: " . $e->getMessage();
        }
    }
}

// ── Load data ─────────────────────────────────────────────────
$all_students = $conn->query(
    "SELECT student_id,last_name,first_name,middle_initial
     FROM students ORDER BY last_name ASC, first_name ASC"
);
$student_count = $all_students->num_rows;

// Sections list
// ── ACCESS CONTROL: only show sections this teacher can actually use ──
// A section is usable here if either:
//   (a) s.teacher_id = $teacher_id   → this teacher created it, OR
//   (b) s.teacher_id IS NULL         → a legacy section from before
//                                      ownership existed (kept visible
//                                      to everyone for backward compat)
// Without this WHERE clause, EVERY teacher's sections show up for
// EVERY other teacher — that was the bug. This is the same rule used
// in manage_sections.php's sectionAccessible() helper; whenever you
// query the `sections` table anywhere in the app, re-apply this same
// filter (or better, factor it into a shared helper function/include
// so it can't be forgotten in a new file).
$sections_stmt = $conn->prepare(
    "SELECT s.id, s.section_name, COUNT(ss.student_id) AS sc
     FROM sections s
     LEFT JOIN section_students ss ON ss.section_id = s.id
     WHERE s.teacher_id = ? OR s.teacher_id IS NULL
     GROUP BY s.id
     ORDER BY s.section_name ASC"
);
$sections_stmt->bind_param('i', $teacher_id);
$sections_stmt->execute();
$sections_res = $sections_stmt->get_result();
$sections_list = [];
while ($sr = $sections_res->fetch_assoc()) $sections_list[] = $sr;

// Prefill section from manage_sections.php
$prefill_section = (int)($_GET['prefill_section'] ?? 0);

// Nav subjects
$nav_subs = getTeacherSubjects($conn, $teacher_id);
$type_cfg = [
    'General Education'      => ['color'=>'#6c8dda','label'=>'GE'],
    'Professional Education' => ['color'=>'#ff2407','label'=>'PE'],
    'Major Subject'          => ['color'=>'#00ff1a','label'=>'MAJ'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Add Subject — Classroom Management System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">

</head>
<body class="page-teacher-add_subject">

<nav class="navbar">
  <a class="brand" href="/classroom/teacher/dashboard.php">
    <img src="/classroom/assets/images/TCM logo (2).png" alt="Classroom CMS" width="32" height="32"></span>Classroom Management System
  </a>
  <div class="nav-sep"></div>
  <a href="/classroom/teacher/dashboard.php" class="nav-link"><i class="ti ti-layout-dashboard"></i> Dashboard</a>
  <?php if ($nav_subs->num_rows > 0): ?>
  <div style="position:relative;" id="ddWrap">
    <button style="display:flex;align-items:center;gap:5px;padding:5px 11px;border-radius:var(--radius);
      font-size:13px;font-weight:500;color:var(--text2);background:transparent;border:none;cursor:pointer;
      font-family:inherit;" id="ddBtn" onclick="toggleDD()">
      <i class="ti ti-books"></i> My Subjects <i class="ti ti-chevron-down" style="font-size:11px;"></i>
    </button>
    <div id="ddMenu" style="display:none;position:absolute;top:calc(100% + 6px);left:0;min-width:250px;
      background:var(--bg2);border:1px solid var(--border2);border-radius:var(--radius-lg);padding:5px;
      box-shadow:var(--shadow);z-index:200;max-height:380px;overflow-y:auto;">
      <?php while ($ns=$nav_subs->fetch_assoc()): $dc=$type_cfg[$ns['subject_type']]['color']??'#00ff1a'; ?>
      <a href="/classroom/teacher/subject_view.php?id=<?= $ns['id'] ?>"
        style="display:flex;align-items:center;gap:8px;padding:7px 9px;border-radius:var(--radius);text-decoration:none;">
        <span style="width:6px;height:6px;border-radius:50%;background:<?= $dc ?>;flex-shrink:0;"></span>
        <span style="font-size:13px;font-weight:500;color:var(--text);flex:1;"><?= htmlspecialchars($ns['subject_code'].' — '.$ns['subject_name']) ?></span>
        <span style="font-size:11px;color:var(--text3);"><?= htmlspecialchars($ns['section']) ?></span>
      </a>
      <?php endwhile; ?>
    </div>
  </div>
  <?php endif; ?>
  <a href="/classroom/teacher/add_subject.php" class="nav-link active"><i class="ti ti-book-plus"></i> Add Subject</a>
  <a href="/classroom/teacher/manage_sections.php" class="nav-link"><i class="ti ti-building-community"></i> Sections</a>
  <a href="/classroom/teacher/students.php" class="nav-link"><i class="ti ti-users"></i> Students</a>
  <div class="nav-right">
    <span class="nav-role">Teacher</span>
    <span style="font-size:13px;color:var(--text2);"><?= htmlspecialchars($_SESSION['username']) ?></span>
    <a href="/classroom/logout.php" class="btn-logout"><i class="ti ti-logout"></i> Logout</a>
  </div>
</nav>

<div class="page-wrap">
  <div class="page-header">
    <h1 style="font-family: var(--font-head); font-size: 35px;"> Add New Subject</h1>
    <p style="font-size: 15px; color: var(--text); padding-top: 15px;">Set up a subject-section with its grade composition, then enroll students by section or individually.</p>
  </div>

<hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg && $new_subject_id): ?>
  <div class="alert alert-success">
    <i class="ti ti-circle-check"></i>
    <div>
      <?= $success_msg ?>
      <br>
      <a href="/classroom/teacher/subject_view.php?id=<?= $new_subject_id ?>" class="btn-goto">
        <i class="ti ti-arrow-right"></i> Open Subject
      </a>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error">
    <i class="ti ti-alert-circle"></i><div><?= $error_msg ?></div>
  </div>
  <?php endif; ?>

  <form method="POST" id="subjectForm">
  <!-- hidden: which enroll mode was active -->
  <input type="hidden" name="enroll_mode" id="enroll_mode_input" value="<?= $prefill_section ? 'section' : 'individual' ?>">

  <div class="two-col">

    <!-- ════ LEFT COLUMN ════ -->
    <div>

      <!-- Subject info -->
      <div class="card" style="margin-bottom:20px;">
        <p class="card-title"><i class="ti ti-info-circle"></i> Subject Information</p>
        <div class="form-row">
          <div class="form-group">
            <label>Subject Code <span style="color:var(--red)">*</span></label>
            <input type="text" name="subject_code" class="form-control"
              placeholder="e.g. CC201"
              value="<?= htmlspecialchars($_POST['subject_code']??'') ?>" required>
          </div>
          <div class="form-group">
            <label>Section</label>
            <input type="text" name="section" class="form-control"
              placeholder="e.g. BSIT 2A"
              value="<?= htmlspecialchars($_POST['section']??'') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Subject Name <span style="color:var(--red)">*</span></label>
          <input type="text" name="subject_name" class="form-control"
            placeholder="e.g. Data Structures and Algorithms"
            value="<?= htmlspecialchars($_POST['subject_name']??'') ?>" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>School Year</label>
            <input type="text" name="school_year" class="form-control"
              placeholder="2024-2025"
              value="<?= htmlspecialchars($_POST['school_year']??date('Y').'-'.(date('Y')+1)) ?>" required>
          </div>
          <div class="form-group">
            <label>Semester</label>
            <select name="semester" class="form-control" required>
              <?php foreach(['1st','2nd','Summer'] as $sem): ?>
              <option value="<?= $sem ?>" <?= ($_POST['semester']??'1st')===$sem?'selected':'' ?>>
                <?= $sem ?> Semester
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <!-- Subject type -->
      <div class="card" style="margin-bottom:20px;">
        <p class="card-title"><i class="ti ti-category"></i> Subject Type</p>
        <p style="font-size:12px;color:var(--text2);margin-bottom:14px;">
          Selecting a type auto-fills the grade weights below.
        </p>
        <input type="hidden" name="subject_type" id="subject_type_hidden"
          value="<?= htmlspecialchars($_POST['subject_type']??'') ?>" required>
        <div class="type-selector">
          <?php
          $type_options = [
            'General Education'      => ['color'=>'#6c8dda','e'=>30,'w'=>30,'p'=>40,'desc'=>'30 / 30 / 40'],
            'Professional Education' => ['color'=>'#ff2407','e'=>25,'w'=>25,'p'=>50,'desc'=>'25 / 25 / 50'],
            'Major Subject'          => ['color'=>'#00ff1a','e'=>40,'w'=>20,'p'=>40,'desc'=>'40 / 20 / 40'],
          ];
          foreach($type_options as $type=>$cfg):
            $sel = ($_POST['subject_type']??'')===$type;
          ?>
          <div class="type-option">
            <input type="radio" name="_type_radio" id="type_<?= md5($type) ?>"
              value="<?= $type ?>" <?= $sel?'checked':'' ?>
              onchange="selectType('<?= addslashes($type) ?>')">
            <label for="type_<?= md5($type) ?>" class="type-label"
              id="tlbl_<?= md5($type) ?>"
              style="<?= $sel?'border-color:'.htmlspecialchars($cfg['color']).';background:'.htmlspecialchars($cfg['color']).'14;':'' ?>">
              <span class="tl-dot" style="background:<?= $cfg['color'] ?>;"></span>
              <span class="tl-name"><?= $type ?></span>
              <span class="tl-weights"><?= $cfg['desc'] ?></span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Grade weights -->
      <div class="card" style="margin-bottom:20px;">
        <p class="card-title"><i class="ti ti-percentage"></i> Grade Composition</p>
        <p style="font-size:12px;color:var(--text2);margin-bottom:14px;">
          Must total exactly <strong style="color:var(--text)">100%</strong>.
          Attendance is a sub-component inside Performance Tasks.
        </p>
        <div class="weight-grid">
          <div class="form-group">
            <label><span style="color:#7aa3ff;width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;background:#7aa3ff;"></span>Major Exams %</label>
            <input type="number" id="exam_pct" name="exam_pct" class="form-control"
              min="0" max="100" step="1"
              value="<?= (int)($_POST['exam_pct']??30) ?>"
              oninput="updateWeights()" required>
          </div>
          <div class="form-group">
            <label><span style="color:#34d399;width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;background:#34d399;"></span>Written Works %</label>
            <input type="number" id="written_pct" name="written_pct" class="form-control"
              min="0" max="100" step="1"
              value="<?= (int)($_POST['written_pct']??30) ?>"
              oninput="updateWeights()" required>
          </div>
          <div class="form-group">
            <label><span style="color:#fbbf24;width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;background:#fbbf24;"></span>Performance %</label>
            <input type="number" id="performance_pct" name="performance_pct" class="form-control"
              min="0" max="100" step="1"
              value="<?= (int)($_POST['performance_pct']??40) ?>"
              oninput="updateWeights()" required>
          </div>
          <div class="form-group">
            <label><span style="color:#a78bfa;width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;background:#a78bfa;"></span>Attendance % <span style="font-weight:400;font-size:10px;color:var(--text3);">(inside Perf)</span></label>
            <input type="number" id="attendance_pct" name="attendance_pct" class="form-control"
              min="1" max="50" step="1"
              value="<?= (int)($_POST['attendance_pct']??10) ?>" required>
          </div>
        </div>
        <div id="weight_total" class="weight-total valid">
          <i class="ti ti-circle-check"></i> Total: 100% ✓
        </div>
        <div class="weight-viz">
          <div class="weight-bar">
            <div class="weight-bar-seg" id="bar_e" style="width:30%;background:#7aa3ff;"></div>
            <div class="weight-bar-seg" id="bar_w" style="width:30%;background:#34d399;"></div>
            <div class="weight-bar-seg" id="bar_p" style="width:40%;background:#fbbf24;"></div>
          </div>
          <div class="weight-legend">
            <span class="wleg"><span class="wleg-dot" style="background:#7aa3ff;"></span><span id="lbl_e">Exam 30%</span></span>
            <span class="wleg"><span class="wleg-dot" style="background:#34d399;"></span><span id="lbl_w">Written 30%</span></span>
            <span class="wleg"><span class="wleg-dot" style="background:#fbbf24;"></span><span id="lbl_p">Perf 40%</span></span>
            <span class="wleg"><span class="wleg-dot" style="background:#a78bfa;"></span><span id="lbl_a">Attendance 10%</span></span>
          </div>
        </div>
      </div>
    </div>

    <!-- ════ RIGHT COLUMN: Enrollment ════ -->
    <div class="card" style="position:sticky;top:68px;">
      <p class="card-title"><i class="ti ti-users"></i> Enroll Students</p>

      <!-- Mode tabs -->
      <div class="enroll-tabs">
        <div class="enroll-tab <?= (!$prefill_section && ($sections_list===[] || ($_POST['enroll_mode']??'individual')==='individual')) ? 'active' : '' ?>"
          id="tab-section" onclick="switchTab('section')">
          <i class="ti ti-building-community"></i> By Section
        </div>
        <div class="enroll-tab <?= (!$prefill_section && ($sections_list!==[] || ($_POST['enroll_mode']??'individual')==='individual')) ? '' : 'active' ?>"
          id="tab-individual" onclick="switchTab('individual')">
          <i class="ti ti-user"></i> Individual
        </div>
      </div>

      <!-- Panel: By Section -->
      <div class="enroll-panel <?= ($prefill_section || ($_POST['enroll_mode']??'section')==='section') ? 'active' : '' ?>"
        id="panel-section">
        <?php if (!$sections_list): ?>
        <div class="empty-state" style="padding:24px;">
          <i class="ti ti-building-community"></i>
          <p>No sections yet.<br>
            <a href="/classroom/teacher/manage_sections.php" style="color:var(--accent)">Create sections first →</a>
          </p>
        </div>
        <?php else: ?>
        <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">
          Select a section to enroll all its students at once.
        </p>
        <input type="hidden" name="enroll_section_id" id="enroll_section_id" value="<?= $prefill_section ?>">
        <div id="sectionList">
          <?php foreach ($sections_list as $sec): ?>
          <div class="sec-option <?= ($prefill_section && (int)$sec['id']===$prefill_section) ? 'selected' : '' ?>"
            id="secopt-<?= $sec['id'] ?>"
            onclick="selectSection(<?= $sec['id'] ?>)">
            <div>
              <div class="sec-option-name"><?= htmlspecialchars($sec['section_name']) ?></div>
              <div class="sec-option-count"><?= $sec['sc'] ?> students</div>
            </div>
            <i class="ti ti-<?= ($prefill_section && (int)$sec['id']===$prefill_section) ? 'circle-check' : 'circle' ?>"
              id="secicon-<?= $sec['id'] ?>"
              style="font-size:18px;color:<?= ($prefill_section && (int)$sec['id']===$prefill_section) ? 'var(--green)' : 'var(--text3)' ?>;"></i>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;font-size:12px;color:var(--text2);">
          <i class="ti ti-info-circle"></i>
          Can't find your section?
          <a href="/classroom/teacher/manage_sections.php" style="color:var(--yellow);">Manage sections →</a>
        </div>
        <?php endif; ?>
      </div>

      <!-- Panel: Individual -->
      <div class="enroll-panel <?= ($prefill_section || ($_POST['enroll_mode']??'section')==='section') ? '' : 'active' ?>"
        id="panel-individual">
        <?php if ($student_count === 0): ?>
        <div class="empty-state">
          <i class="ti ti-users-off"></i>
          <p>No students yet.<br>
            <a href="/classroom/teacher/students.php" style="color:var(--accent)">Add students first →</a>
          </p>
        </div>
        <?php else: ?>
        <div class="checklist-header">
          <div class="search-input-wrap">
            <i class="ti ti-search"></i>
            <input type="text" id="srch" placeholder="Search students…" oninput="filterStudents()">
          </div>
          <div class="checklist-actions">
            <button type="button" class="btn btn-sm btn-outline" onclick="selectAll(true)">
              <i class="ti ti-check"></i> All
            </button>
            <button type="button" class="btn btn-sm btn-outline" onclick="selectAll(false)">
              <i class="ti ti-x"></i> None
            </button>
          </div>
        </div>
        <div class="selected-counter">
          <i class="ti ti-users" style="font-size:13px;color:var(--text2);"></i>
          <span id="sel_count" style="color:var(--text2);">0</span> of <?= $student_count ?> selected
        </div>
        <div class="student-list" id="studentList">
          <?php while ($s = $all_students->fetch_assoc()):
            $checked  = in_array($s['student_id'], $_POST['enrollees']??[]);
            $initials = strtoupper(substr($s['last_name'],0,1).substr($s['first_name'],0,1));
          ?>
          <label class="enrollee-row <?= $checked?'selected':'' ?>"
            data-name="<?= strtolower($s['last_name'].' '.$s['first_name'].' '.$s['student_id']) ?>"
            onclick="toggleRow(this)">
            <input type="checkbox" name="enrollees[]"
              value="<?= htmlspecialchars($s['student_id']) ?>"
              class="enr-check"
              <?= $checked?'checked':'' ?>
              onchange="countSelected()" onclick="event.stopPropagation()">
            <div class="enr-avatar"><?= $initials ?></div>
            <div style="flex:1;">
              <div class="enr-name"><?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?></div>
              <div class="enr-id"><?= htmlspecialchars($s['student_id']) ?></div>
            </div>
          </label>
          <?php endwhile; ?>
        </div>
        <?php endif; ?>
      </div><!-- end panel-individual -->

      <div class="divider"></div>
      <button type="submit" name="save_subject" class="btn btn-primary" id="deployBtn">
        <i class="ti ti-rocket"></i> Deploy Subject
      </button>
    </div><!-- end right card -->

  </div>
  </form>
</div>

<script>
const typeDefaults = {
  'General Education':      {e:30,w:30,p:40,a:10},
  'Professional Education': {e:25,w:25,p:50,a:10},
  'Major Subject':          {e:40,w:20,p:40,a:10},
};
const typeColors = {
  'General Education':'#6c8dda',
  'Professional Education':'#ff2407',
  'Major Subject':'#00ff1a',
};

function selectType(type) {
  document.getElementById('subject_type_hidden').value = type;
  document.querySelectorAll('.type-label').forEach(l => { l.style.borderColor=''; l.style.background=''; });
  const radio = document.querySelector(`input[value="${CSS.escape(type)}"]`);
  if (radio) {
    const lbl = radio.nextElementSibling;
    const col = typeColors[type] || '#5b8dee';
    lbl.style.borderColor = col; lbl.style.background = col + '14';
  }
  const d = typeDefaults[type]; if (!d) return;
  document.getElementById('exam_pct').value        = d.e;
  document.getElementById('written_pct').value     = d.w;
  document.getElementById('performance_pct').value = d.p;
  document.getElementById('attendance_pct').value  = d.a;
  updateWeights();
}

function updateWeights() {
  const e = parseFloat(document.getElementById('exam_pct').value)        || 0;
  const w = parseFloat(document.getElementById('written_pct').value)     || 0;
  const p = parseFloat(document.getElementById('performance_pct').value) || 0;
  const a = parseFloat(document.getElementById('attendance_pct').value)  || 0;
  const t = e + w + p;
  const el = document.getElementById('weight_total');
  const valid = Math.round(t*100) === 10000;
  el.className = 'weight-total ' + (valid?'valid':'invalid');
  el.innerHTML = valid
    ? '<i class="ti ti-circle-check"></i> Total: 100% ✓'
    : `<i class="ti ti-alert-circle"></i> Total: ${t}% — must equal 100%`;
  document.getElementById('bar_e').style.width = e+'%';
  document.getElementById('bar_w').style.width = w+'%';
  document.getElementById('bar_p').style.width = p+'%';
  document.getElementById('lbl_e').textContent = `Exam ${e}%`;
  document.getElementById('lbl_w').textContent = `Written ${w}%`;
  document.getElementById('lbl_p').textContent = `Perf ${p}%`;
  document.getElementById('lbl_a').textContent = `Attendance ${a}%`;
}

// ── Enrollment tabs ──────────────────────────────────────────
let activeTab = document.getElementById('enroll_mode_input').value === 'section' ? 'section' : 'individual';

function switchTab(tab) {
  activeTab = tab;
  document.getElementById('enroll_mode_input').value = tab;
  document.getElementById('tab-section').classList.toggle('active',   tab==='section');
  document.getElementById('tab-individual').classList.toggle('active', tab==='individual');
  document.getElementById('panel-section').classList.toggle('active',   tab==='section');
  document.getElementById('panel-individual').classList.toggle('active', tab==='individual');
}

// ── Section selection ────────────────────────────────────────
let selectedSectionId = <?= $prefill_section ?: 0 ?>;

function selectSection(id) {
  // Deselect previous
  if (selectedSectionId) {
    const prev = document.getElementById('secopt-'+selectedSectionId);
    const prevIcon = document.getElementById('secicon-'+selectedSectionId);
    if (prev) prev.classList.remove('selected');
    if (prevIcon) { prevIcon.className='ti ti-circle'; prevIcon.style.color='var(--text3)'; }
  }
  // Toggle
  if (selectedSectionId === id) {
    selectedSectionId = 0;
    document.getElementById('enroll_section_id').value = 0;
    return;
  }
  selectedSectionId = id;
  document.getElementById('enroll_section_id').value = id;
  const card = document.getElementById('secopt-'+id);
  const icon = document.getElementById('secicon-'+id);
  if (card) card.classList.add('selected');
  if (icon) { icon.className='ti ti-circle-check'; icon.style.color='var(--green)'; }
}

// ── Individual checklist ─────────────────────────────────────
function countSelected() {
  const n = document.querySelectorAll('.enr-check:checked').length;
  const el = document.getElementById('sel_count');
  if (el) el.textContent = n;
}
function selectAll(val) {
  document.querySelectorAll('.enrollee-row').forEach(row => {
    if (row.style.display==='none') return;
    const cb = row.querySelector('.enr-check');
    if (!cb) return;
    cb.checked = val;
    row.classList.toggle('selected', val);
  });
  countSelected();
}
function toggleRow(row) {
  const cb = row.querySelector('.enr-check');
  if (!cb) return;
  cb.checked = !cb.checked;
  row.classList.toggle('selected', cb.checked);
  countSelected();
}
function filterStudents() {
  const q = document.getElementById('srch').value.toLowerCase();
  document.querySelectorAll('.enrollee-row').forEach(row => {
    row.style.display = row.dataset.name.includes(q) ? '' : 'none';
  });
}

// ── Navbar dropdown ──────────────────────────────────────────
function toggleDD() {
  const m = document.getElementById('ddMenu');
  if (m) m.style.display = m.style.display==='block' ? 'none' : 'block';
}
document.addEventListener('click', e => {
  const w = document.getElementById('ddWrap');
  if (w && !w.contains(e.target)) {
    const m = document.getElementById('ddMenu');
    if (m) m.style.display = 'none';
  }
});

// ── Init ─────────────────────────────────────────────────────
updateWeights();
countSelected();
switchTab(activeTab);
const savedType = document.getElementById('subject_type_hidden').value;
if (savedType) selectType(savedType);
</script>
</body>
</html>
