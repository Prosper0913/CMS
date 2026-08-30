<?php
// ============================================================
//  student/dashboard.php
//  Personal home page — student sees their enrolled subjects
//  and overall grade summary. Every query filters by session.
// ============================================================
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];
$unread_count = getUnreadNotificationCount($conn, $sid);

// Student profile
$s = $conn->prepare("SELECT * FROM students WHERE student_id=?");
$s->bind_param("s",$sid); $s->execute();
$student = $s->get_result()->fetch_assoc();

// Enrolled subjects with grades
$subjects = $conn->prepare(
    "SELECT sub.id, sub.subject_code, sub.subject_name, sub.section,
            sub.subject_type, sub.exam_pct, sub.written_pct,
            sub.performance_pct, sub.attendance_pct,
            sub.school_year, sub.semester,
            COALESCE(g.final_grade,0)    AS final_grade,
            COALESCE(g.letter_grade,'N/A') AS letter_grade,
            COALESCE(g.exam_avg,0)       AS exam_avg,
            COALESCE(g.written_avg,0)    AS written_avg,
            COALESCE(g.performance_avg,0) AS perf_avg,
            COALESCE(g.attendance_rate,0) AS att_rate
     FROM subject_enrollments e
     JOIN subjects sub ON sub.id=e.subject_id
     LEFT JOIN subject_grades g ON g.subject_id=e.subject_id AND g.student_id=e.student_id
     WHERE e.student_id=? AND sub.is_active=1
     ORDER BY sub.subject_name ASC"
);
$subjects->bind_param("s",$sid);
$subjects->execute();
$subs = $subjects->get_result();

// Overall stats
$total_subjects = $subs->num_rows;
$overall_avg    = $conn->prepare(
    "SELECT ROUND(AVG(g.final_grade),1) AS avg
     FROM subject_grades g
     JOIN subject_enrollments e ON e.subject_id=g.subject_id AND e.student_id=g.student_id
     WHERE g.student_id=?"
);
$overall_avg->bind_param("s",$sid); $overall_avg->execute();
$overall = $overall_avg->get_result()->fetch_assoc()['avg'] ?? 0;

$passing = $conn->prepare(
    "SELECT COUNT(*) AS c FROM subject_grades WHERE student_id=? AND final_grade>=75"
);
$passing->bind_param("s",$sid); $passing->execute();
$pass_count = $passing->get_result()->fetch_assoc()['c'];

$type_colors = [
    'General Education'      => ['bar'=>'#1e5f4e','badge'=>'rgba(91,141,238,.15)','text'=>'##1e5f4e'],
    'Professional Education' => ['bar'=>'#1e5f4e','badge'=>'rgba(52,211,153,.12)', 'text'=>'#1e5f4e'],
    'Major Subject'          => ['bar'=>'#1e5f4e','badge'=>'rgba(251,191,36,.12)', 'text'=>'#1e5f4e'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>My Dashboard — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroomv2/assets/style.css">

</head>
<body class="page-student-dashboard">
<div class="app-shell">


<?php $active_nav = 'dashboard'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <!-- Welcome banner -->
  <div class="welcome-banner">
    <div>
      <div class="welcome-name">
        Hello, <?php echo htmlspecialchars($student['first_name']); ?> 👋
      </div>
      <div class="welcome-sub"><?php echo date('l, F d Y'); ?></div>
      <div class="welcome-id"><?php echo htmlspecialchars($student['student_id']); ?></div>
    </div>
    <div style="text-align:right;">
      <div style="font-family:var(--font-head);font-size:36px;font-weight:800;color:<?php echo $overall>=75?'var(--green)':'var(--red)'; ?>;">
        <?php echo $overall > 0 ? letterGrade($overall) : '—'; ?>
      </div>
      <div>Overall Average</div>
    </div>
  </div>

  <!-- Subject cards -->
  <p class="bottom-margin">
    My Subjects This Semester
  </p>

  <?php if ($total_subjects === 0): ?>
  <div class="empty-state">
    <i class="ti ti-books" style="color:var(--text6);"></i>
    <p>You are not enrolled in any subjects yet.</p>
    <p style="font-size:12px;margin-top:6px;color:var(--text3);">Contact your teacher to be enrolled.</p>
  </div>
  <?php else: ?>
  <div class="subjects-grid">
    <?php
    $subs->data_seek(0);
    while ($sub = $subs->fetch_assoc()):
      $tc  = $type_colors[$sub['subject_type']] ?? $type_colors['General Education'];
      $fg  = (float)$sub['final_grade'];
      $pass = $fg >= 75;

      // Letter grade badge color
      $lg_color = match(true) {
        $fg >= 85 => ['bg'=>'rgba(52,211,153,.15)','text'=>'#34d399'],
        $fg >= 75 => ['bg'=>'rgba(91,141,238,.15)', 'text'=>'#7aa3ff'],
        $fg >= 70 => ['bg'=>'rgba(251,191,36,.15)', 'text'=>'#fbbf24'],
        $fg > 0   => ['bg'=>'rgba(248,113,113,.15)','text'=>'#f87171'],
        default   => ['bg'=>'var(--bg3)',            'text'=>'var(--text3)'],
      };
    ?>
    <a href="/classroomv2/student/subject_detail.php?id=<?php echo $sub['id']; ?>"
       class="subject-card">

      <div class="sc-top-bar" style="background:<?php echo $tc['bar']; ?>;"></div>

      <div class="sc-code"><?php echo htmlspecialchars($sub['subject_code']); ?></div>
      <div class="sc-name"><?php echo htmlspecialchars($sub['subject_name']); ?></div>

      <div class="sc-meta">
        <span><i class="ti ti-school"></i> <?php echo htmlspecialchars($sub['section']); ?></span>
        <span><i class="ti ti-calendar"></i> <?php echo $sub['semester']; ?> Sem</span>
        <span class="sc-type" style="color:<?php echo $tc['text']; ?>;border-color:<?php echo $tc['bar'].'44'; ?>;background:<?php echo $tc['bar'].'15'; ?>;">
          <?php echo $sub['subject_type']; ?>
        </span>
      </div>

      <!-- Grade display -->
      <?php if ($fg > 0): ?>
      <div class="grade-display">
        <!-- <div class="grade-big" style="color:<?php echo $pass?'var(--green)':'var(--red)'; ?>">
          <?php echo number_format($fg,2); ?>%
        </div> -->
        <div class="grade-letter" style="background:<?php echo $lg_color['bg']; ?>;color:<?php echo $lg_color['text']; ?>;">
          <?php echo $sub['letter_grade']; ?>
        </div>
        <div class="grade-status" style="color:<?php echo $pass?'var(--green)':'var(--red)'; ?>;">
          <?php echo $pass?'✓ Passed':'✗ Failing'; ?>
        </div>
      </div>

      <!-- Component breakdown bars -->
      <div class="comp-bars">
        <?php
        $comps = [
          ['Exams',       $sub['exam_avg'],  '#7aa3ff', $sub['exam_pct']],
          ['Written',     $sub['written_avg'],'#34d399',$sub['written_pct']],
          ['Performance', $sub['perf_avg'],  '#fbbf24', $sub['performance_pct']],
          ['Attendance',  $sub['att_rate'],  '#a78bfa', $sub['attendance_pct']],
        ];
        foreach($comps as [$label,$val,$color,$weight]):
          $v = (float)$val;
        ?>
        <div class="comp-bar-row">
          <span class="comp-bar-label"><?php echo $label; ?> <span>(<?php echo (int)$weight; ?>%)</span></span>
          <div class="comp-bar-track">
            <div class="comp-bar-fill" style="width:<?php echo min($v,100); ?>%;background:<?php echo $color; ?>;opacity:.8;"></div>
          </div>
          <span class="comp-bar-val" style="color:<?php echo $v>=75?'var(--green)':($v>0?'var(--red)':'var(--text7)'); ?>">
            <?php echo $v>0?number_format($v,1).'%':'—'; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div>
        <i class="ti ti-dots"></i> No grades recorded yet
      </div>
      <!-- Weight chips -->
      <div class="weight-chips">
        <span class="wc exam">Exam <?php echo (int)$sub['exam_pct']; ?>%</span>
        <span class="wc written">Written <?php echo (int)$sub['written_pct']; ?>%</span>
        <span class="wc perf">Perf <?php echo (int)$sub['performance_pct']; ?>%</span>
      </div>
      <?php endif; ?>

    </a>
    <?php endwhile; ?>
  </div>
  <?php endif; ?>

</div>

</main>
</div>
</body>
</html>