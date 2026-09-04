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
    $section_id      = (int)($_POST['section_id'] ?? 0);
    $subject_type    = trim($_POST['subject_type']);
    $school_year     = trim($_POST['school_year']);
    $semester        = trim($_POST['semester']);
    $exam_pct        = (float)$_POST['exam_pct'];
    $written_pct     = (float)$_POST['written_pct'];
    $performance_pct = (float)$_POST['performance_pct'];
    $attendance_pct  = (float)$_POST['attendance_pct'];
    $schedule_days   = implode(',', $_POST['schedule_days'] ?? []);
    $schedule_start  = trim($_POST['schedule_start_time'] ?? '');
    $schedule_end    = trim($_POST['schedule_end_time'] ?? '');

    $valid_types = ['General Education','Professional Education','Major Subject'];
    $valid_sems  = ['1st','2nd','Summer'];
    $valid_days  = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $total_pct   = $exam_pct + $written_pct + $performance_pct;

    if ($subject_code===''||$subject_name==='') {
        $error_msg = "Subject code and name are required.";
    } elseif ($section_id <= 0) {
        $error_msg = "Please select a section.";
    } elseif (!in_array($subject_type,$valid_types)) {
        $error_msg = "Please select a valid subject type.";
    } elseif (!in_array($semester,$valid_sems)) {
        $error_msg = "Please select a valid semester.";
    } elseif (empty($_POST['schedule_days']) || array_diff($_POST['schedule_days'], $valid_days)) {
        $error_msg = "Please select at least one valid class day.";
    } elseif ($schedule_start === '' || $schedule_end === '') {
        $error_msg = "Please set both a start and end time for the class schedule.";
    } elseif ($schedule_start >= $schedule_end) {
        $error_msg = "Class end time must be after the start time.";
    } elseif (round($total_pct,2) !== 100.00) {
        $error_msg = "Grade weights must total exactly 100%. Current total: <strong>{$total_pct}%</strong>";
    } elseif ($attendance_pct <= 0) {
        $error_msg = "Attendance % must be greater than 0.";
    } elseif ($attendance_pct >= $performance_pct) {
        $error_msg = "Attendance % ({$attendance_pct}%) must be less than Performance % ({$performance_pct}%).";
    } else {
        $conn->begin_transaction();
        try {
            // ── ACCESS CONTROL (server-side, do NOT skip this) ──
            // Never trust that section_id is one of "my" sections just
            // because the dropdown only showed my own — a POST request
            // can be crafted/replayed with ANY section_id. Re-check
            // ownership here, right before using it.
            $sec_chk = $conn->prepare(
                "SELECT id, section_name FROM sections
                 WHERE id = ? AND (teacher_id = ? OR teacher_id IS NULL)
                 LIMIT 1"
            );
            $sec_chk->bind_param('ii', $section_id, $teacher_id);
            $sec_chk->execute();
            $sec_row = $sec_chk->get_result()->fetch_assoc();

            if (!$sec_row) {
                throw new Exception("You don't have access to that section.");
            }
            $section = $sec_row['section_name'];

            // 1. Insert subject
            $ins = $conn->prepare(
                "INSERT INTO subjects
                   (teacher_id,subject_code,subject_name,section,subject_type,
                    school_year,semester,exam_pct,written_pct,performance_pct,attendance_pct,
                    schedule_days,schedule_start_time,schedule_end_time)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->bind_param("issssssddddsss",
                $teacher_id,$subject_code,$subject_name,$section,$subject_type,
                $school_year,$semester,$exam_pct,$written_pct,$performance_pct,$attendance_pct,
                $schedule_days,$schedule_start,$schedule_end
            );
            $ins->execute();
            $new_subject_id = $conn->insert_id;

            // 2. Auto-enroll every student currently in the selected section
            $sq = $conn->prepare(
                "SELECT student_id FROM section_students WHERE section_id = ?"
            );
            $sq->bind_param('i', $section_id);
            $sq->execute();
            $srows = $sq->get_result();
            $enrolled_count = 0;
            while ($sr = $srows->fetch_assoc()) {
                $sid = $sr['student_id'];
                $e1 = $conn->prepare(
                    "INSERT IGNORE INTO subject_enrollments (subject_id,student_id,section_id)
                     VALUES (?,?,?)"
                );
                $e1->bind_param("isi",$new_subject_id,$sid,$section_id);
                $e1->execute();
                $e2 = $conn->prepare(
                    "INSERT IGNORE INTO subject_grades (subject_id,student_id) VALUES (?,?)"
                );
                $e2->bind_param("is",$new_subject_id,$sid);
                $e2->execute();
                $enrolled_count++;
            }

            $conn->commit();
            $success_msg = "Subject <strong>{$subject_code} — {$subject_name}</strong> deployed "
                         . "with {$enrolled_count} student(s) enrolled from <strong>".htmlspecialchars($section)."</strong>.";

        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error saving subject: " . $e->getMessage();
        }
    }
}
// ── Load data ─────────────────────────────────────────────────

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
    'General Education'      => ['color'=>'#1e5f4e','label'=>'GE'],
    'Professional Education' => ['color'=>'#1e5f4e','label'=>'PE'],
    'Major Subject'          => ['color'=>'#1e5f4e','label'=>'MAJ'],
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
    <link rel="stylesheet" href="/classroomv2/assets/style.css">

</head>
<body class="page-teacher-add_subject">
<div class="app-shell">


<?php $active_nav = 'subjects'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <?php if ($success_msg && $new_subject_id): ?>
  <div class="alert alert-success">
    <i class="ti ti-circle-check"></i>
    <div>
      <?= $success_msg ?>
      <br>
      <a href="/classroomv2/teacher/subject_view.php?id=<?= $new_subject_id ?>" class="btn-goto">
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

  <div>

      <!-- Subject info -->
      <div class="card" style="margin-bottom:20px;">
        <p class="card-title"><i class="ti ti-info-circle"></i> Subject Information</p>
        <div class="form-row">
          <div class="form-group">
            <label>Subject Code <span class="text-red">*</span></label>
            <input type="text" name="subject_code" class="form-control"
              placeholder="e.g. CC201"
              value="<?= htmlspecialchars($_POST['subject_code']??'') ?>" required>
          </div>
          <div class="form-group">
            <label>Section <span class="text-red">*</span></label>
            <select name="section_id" class="form-control" required>
              <option value="">Select section</option>
              <?php
              $selected_section_id = isset($_POST['section_id']) ? (int)$_POST['section_id'] : $prefill_section;
              foreach ($sections_list as $sec):
              ?>
              <option value="<?= $sec['id'] ?>" <?= ($selected_section_id === (int)$sec['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($sec['section_name']) ?> (<?= $sec['sc'] ?> student<?= $sec['sc']!=1?'s':'' ?>)
              </option>
              <?php endforeach; ?>
            </select>
            <p style="font-size:11px;color:var(--text7);margin-top:4px;">
              Every student currently in this section will be enrolled automatically.
              <?php if (empty($sections_list)): ?>
                You don't have any sections yet — create one first in <a href="manage_sections.php" class="text-accent">Manage Sections</a>.
              <?php endif; ?>
            </p>
          </div>
        </div>
        <div class="form-group">
          <label>Subject Name <span class="text-red">*</span></label>
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

      <!-- Class Schedule -->
      <div class="card" style="margin-bottom:20px;">
        <p class="card-title"><i class="ti ti-calendar-time"></i> Class Schedule <span class="text-red">*</span></p>
        <div class="form-group">
          <label>Days</label>
          <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <?php
            $day_labels = ['Mon'=>'Mon','Tue'=>'Tue','Wed'=>'Wed','Thu'=>'Thu','Fri'=>'Fri','Sat'=>'Sat','Sun'=>'Sun'];
            $checked_days = $_POST['schedule_days'] ?? [];
            foreach ($day_labels as $val => $label):
            ?>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
              <input type="checkbox" name="schedule_days[]" value="<?= $val ?>" <?= in_array($val, $checked_days) ? 'checked' : '' ?>>
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Start Time</label>
            <input type="time" name="schedule_start_time" class="form-control"
              value="<?= htmlspecialchars($_POST['schedule_start_time']??'') ?>" required>
          </div>
          <div class="form-group">
            <label>End Time</label>
            <input type="time" name="schedule_end_time" class="form-control"
              value="<?= htmlspecialchars($_POST['schedule_end_time']??'') ?>" required>
          </div>
        </div>
      </div>

      <!-- Subject type -->
      <div class="card" style="margin-bottom:20px;">
        <p class="card-title"><i class="ti ti-category"></i> Subject Type</p>
        <p>
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
        <p>
          Must total exactly <strong>100%</strong>.
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
            <label><span style="color:#a78bfa;width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;background:#a78bfa;">

            </span>Attendance % <span style="font-weight:400;font-size:10px;color:var(--text7);">(under Perf)</span></label>
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

    <div style="margin-top:24px;">
      <button type="submit" name="save_subject" class="btn btn-primary" id="deployBtn">
        <i class="ti ti-rocket"></i> Deploy Subject
      </button>
    </div>

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
const savedType = document.getElementById('subject_type_hidden').value;
if (savedType) selectType(savedType);
</script>
</main>
</div>
</body>
</html>