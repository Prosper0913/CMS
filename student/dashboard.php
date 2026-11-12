<?php
// ============================================================
//  student/dashboard.php
//  Personal home page — shows the student's weekly class
//  schedule at a glance. Grades live on subjects.php now;
//  this page is for "what do I have and when."
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

// Enrolled subjects with their schedule
$subjects = $conn->prepare(
    "SELECT sub.id, sub.subject_code, sub.subject_name, sub.section, sub.subject_type,
            sub.schedule_days, sub.schedule_start_time, sub.schedule_end_time,
            sub.school_year, sub.semester,
            COALESCE(NULLIF(u.display_name,''), u.username) AS teacher_name
     FROM subject_enrollments e
     JOIN subjects sub ON sub.id = e.subject_id
     LEFT JOIN users u ON u.id = sub.teacher_id
     WHERE e.student_id=? AND sub.is_active=1
     ORDER BY sub.subject_name ASC"
);
$subjects->bind_param("s",$sid);
$subjects->execute();
$subs_result = $subjects->get_result();
$total_subjects = $subs_result->num_rows;

// Group subjects by the day(s) they meet, sorted by start time within each day
$day_order = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$day_full  = ['Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday','Sat'=>'Saturday','Sun'=>'Sunday'];
$by_day = array_fill_keys($day_order, []);

// Grid range: whole hours, default 7 AM – 5 PM, widened to fit every class.
$range_start = 7*60;
$range_end   = 17*60;
$all_rows    = [];

while ($sub = $subs_result->fetch_assoc()) {
    $all_rows[] = $sub;
    if (!empty($sub['schedule_start_time']) && !empty($sub['schedule_end_time'])) {
        $sm = (int)date('H', strtotime($sub['schedule_start_time'])) * 60 + (int)date('i', strtotime($sub['schedule_start_time']));
        $em = (int)date('H', strtotime($sub['schedule_end_time']))   * 60 + (int)date('i', strtotime($sub['schedule_end_time']));
        if ($sm < $range_start) $range_start = $sm;
        if ($em > $range_end)   $range_end   = $em;
    }
    $sub_days = array_filter(explode(',', $sub['schedule_days'] ?? ''));
    foreach ($sub_days as $d) {
        if (isset($by_day[$d])) $by_day[$d][] = $sub;
    }
}
$range_start = (int)(floor($range_start / 60) * 60);
$range_end   = (int)(ceil($range_end / 60) * 60);
if ($range_end <= $range_start) $range_end = $range_start + 60;
$hour_h      = 64;                                   // px per hour row
$hours_count = (int)(($range_end - $range_start) / 60);
$px_per_min  = $hour_h / 60;
$day_short   = ['Mon'=>'MON','Tue'=>'TUE','Wed'=>'WED','Thu'=>'THU','Fri'=>'FRI','Sat'=>'SAT','Sun'=>'SUN'];

// Term label under the title (the school year / semester most subjects share)
$term_counts = [];
foreach ($all_rows as $r) {
    $lab = trim(($r['school_year'] ?? '') . '|' . ($r['semester'] ?? ''));
    $term_counts[$lab] = ($term_counts[$lab] ?? 0) + 1;
}
arsort($term_counts);
$term_label = '';
if ($term_counts) {
    [$ty, $tm] = array_pad(explode('|', array_key_first($term_counts), 2), 2, '');
    $term_label = trim(($ty !== '' ? 'S.Y. ' . $ty : '') . ($tm !== '' ? ' · ' . $tm . (in_array($tm, ['1st','2nd'], true) ? ' Semester' : '') : ''));
}
foreach ($by_day as &$list) {
    usort($list, fn($a,$b) => strcmp($a['schedule_start_time'] ?? '', $b['schedule_start_time'] ?? ''));
}
unset($list);

$today_key = ['Sunday'=>'Sun','Monday'=>'Mon','Tuesday'=>'Tue','Wednesday'=>'Wed','Thursday'=>'Thu','Friday'=>'Fri','Saturday'=>'Sat'][date('l')];
$today_count = count($by_day[$today_key]);

$type_colors = [
    'General Education'      => '#7aa3ff',
    'Professional Education' => '#34d399',
    'Major Subject'          => '#fbbf24',
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
  <style>
  /* ── WEEKLY SCHEDULE SHEET (teacher/all_subjects.php) ─────────────
     Printed-planner look: centered title, day header row, hourly rows,
     every cell outlined. Subjects sit on the grid as coloured blocks. */
  .wk-sheet{background:#f6faf8;border:1px solid rgba(30,95,78,.25);border-radius:14px;padding:26px 24px 22px;margin-bottom:24px;color:#1b3b32}
  .wk-title{text-align:center;font-family:var(--font-head);font-weight:800;font-size:26px;letter-spacing:.14em;color:#1e5f4e;margin:0}
  .wk-sub{text-align:center;font-size:13px;color:#5d7a71;margin:6px 0 4px;letter-spacing:.04em}
  .wk-legend{display:flex;justify-content:center;flex-wrap:wrap;gap:14px;margin:10px 0 20px;font-size:11.5px;color:#4d6a61}
  .wk-legend span{display:inline-flex;align-items:center;gap:6px}
  .wk-legend i{width:12px;height:12px;border-radius:3px;display:inline-block;border:1px solid rgba(0,0,0,.12)}
  .wk-scroll{overflow-x:auto;padding-bottom:4px}
  .wk-board{min-width:820px;display:grid;grid-template-columns:72px repeat(7,minmax(104px,1fr))}
  .wk-head{height:38px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;letter-spacing:.12em;color:#1e5f4e}
  .wk-head.is-today{color:#fff;background:#1e5f4e;border-radius:8px 8px 0 0}
  .wk-timecol{display:flex;flex-direction:column}
  .wk-time{height:var(--hh);display:flex;align-items:flex-start;justify-content:flex-end;padding:5px 10px 0 0;font-size:11.5px;color:#4d6a61;border-top:1px solid #8aa59b;box-sizing:border-box}
  .wk-time:last-child{border-bottom:1px solid #8aa59b}
  .wk-col{position:relative;border-left:1px solid #8aa59b}
  .wk-col:last-child{border-right:1px solid #8aa59b}
  .wk-col.is-today{background:rgba(30,95,78,.06)}
  .wk-cell{height:var(--hh);border-top:1px solid #8aa59b;box-sizing:border-box}
  .wk-cell:last-child{border-bottom:1px solid #8aa59b}
  .wk-block{position:absolute;left:3px;right:3px;box-sizing:border-box;border-radius:7px;padding:5px 7px;overflow:hidden;text-decoration:none;color:#14302a;border:1px solid;border-left-width:4px;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:transform .12s ease,box-shadow .12s ease;z-index:2}
  .wk-block:hover{transform:scale(1.025);box-shadow:0 6px 16px rgba(0,0,0,.22);z-index:6}
  .wk-block .b-time{font-size:10.5px;font-weight:600;opacity:.75;white-space:nowrap}
  .wk-block .b-code{font-size:12px;font-weight:800;line-height:1.2;margin-top:1px}
  .wk-block .b-name{font-size:11.5px;line-height:1.2;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .wk-block .b-meta{font-size:10.5px;opacity:.75;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .wk-ge {background:#dce8ff;border-color:#5b8dee}
  .wk-pe {background:#d3f4e5;border-color:#2bb585}
  .wk-maj{background:#fdecc0;border-color:#d9a21b}
  .wk-empty{grid-column:1/-1;text-align:center;color:#5d7a71;padding:28px 0;font-size:13px}
  @media (max-width:640px){.wk-sheet{padding:18px 12px}.wk-title{font-size:20px}}
  @media print{.wk-sheet{border:none;background:#fff}}
  </style>
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
        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_initial'] . ' ' . $student['last_name']); ?>
      </div>
      <div class="welcome-sub"><?php echo date('l, F d Y'); ?></div>
      <div class="welcome-id"><?php echo htmlspecialchars($student['student_id']); ?></div>
    </div>
    <div style="text-align:right;">
      <div style="font-family:var(--font-head);font-size:36px;font-weight:800;color:<?php echo $today_count>0?'var(--accent)':'var(--text7)'; ?>;">
        <?php echo $today_count; ?>
      </div>
      <div><?php echo $today_count === 1 ? 'Class Today' : 'Classes Today'; ?></div>
    </div>
  </div>


  <?php if ($total_subjects === 0): ?>
  <div class="empty-state">
    <i class="ti ti-calendar-off" style="color:var(--text6);"></i>
    <p>You are not enrolled in any subjects yet.</p>
    <p style="font-size:12px;margin-top:6px;color:var(--text7);">Contact your teacher to be enrolled.</p>
  </div>
  <?php else: ?>

  <!-- ── WEEKLY SCHEDULE ── -->
  <div class="wk-sheet" style="--hh:<?php echo $hour_h; ?>px;">
    <h2 class="wk-title">WEEKLY SCHEDULE</h2>
    <?php if ($term_label !== ''): ?><div class="wk-sub"><?php echo htmlspecialchars($term_label); ?></div><?php endif; ?>
    <div class="wk-legend">
      <span><i style="background:#dce8ff;border-color:#5b8dee"></i> General Education</span>
      <span><i style="background:#d3f4e5;border-color:#2bb585"></i> Professional Education</span>
      <span><i style="background:#fdecc0;border-color:#d9a21b"></i> Major Subject</span>
    </div>

    <div class="wk-scroll">
      <div class="wk-board">
        <div class="wk-head"></div>
        <?php foreach ($day_order as $d): ?>
        <div class="wk-head <?php echo $d === $today_key ? 'is-today' : ''; ?>"><?php echo $day_short[$d]; ?></div>
        <?php endforeach; ?>

        <div class="wk-timecol">
          <?php for ($h = 0; $h < $hours_count; $h++):
            $mm = $range_start + $h * 60;
          ?>
          <div class="wk-time"><?php echo date('g:i A', strtotime('1970-01-01 ' . floor($mm/60) . ':00')); ?></div>
          <?php endfor; ?>
        </div>

        <?php foreach ($day_order as $d): ?>
        <div class="wk-col <?php echo $d === $today_key ? 'is-today' : ''; ?>">
          <?php for ($h = 0; $h < $hours_count; $h++): ?><div class="wk-cell"></div><?php endfor; ?>

          <?php foreach ($by_day[$d] as $sub):
            if (empty($sub['schedule_start_time']) || empty($sub['schedule_end_time'])) continue;
            $cls = ['General Education'=>'wk-ge','Professional Education'=>'wk-pe','Major Subject'=>'wk-maj'][$sub['subject_type']] ?? 'wk-ge';
            $sm  = (int)date('H', strtotime($sub['schedule_start_time'])) * 60 + (int)date('i', strtotime($sub['schedule_start_time']));
            $em  = (int)date('H', strtotime($sub['schedule_end_time']))   * 60 + (int)date('i', strtotime($sub['schedule_end_time']));
            $top    = round(($sm - $range_start) * $px_per_min);
            $height = max(30, round(($em - $sm) * $px_per_min) - 2);
            $tstr = date('g:i A', strtotime($sub['schedule_start_time'])) . '–' . date('g:i A', strtotime($sub['schedule_end_time']));
          ?>
          <a href="/classroomv2/student/subject_detail.php?id=<?php echo (int)$sub['id']; ?>"
             class="wk-block <?php echo $cls; ?>"
             style="top:<?php echo $top + 1; ?>px;height:<?php echo $height; ?>px;"
             title="<?php echo htmlspecialchars($sub['subject_code'].' · '.$sub['subject_name'].' · '.$sub['section'].' · '.$tstr); ?>">
            <div class="b-time"><?php echo $tstr; ?></div>
            <div class="b-code"><?php echo htmlspecialchars($sub['subject_code']); ?></div>
            <div class="b-name"><?php echo htmlspecialchars($sub['subject_name']); ?></div>
            <div class="b-meta"><?php echo htmlspecialchars($sub['section']); ?><?php echo !empty($sub['teacher_name']) ? ' &middot; ' . htmlspecialchars($sub['teacher_name']) : ''; ?></div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php
  // Enrolled subjects that don't have a class schedule yet
  $unscheduled = array_filter($all_rows, fn($r) => empty($r['schedule_days']) || empty($r['schedule_start_time']) || empty($r['schedule_end_time']));
  if ($unscheduled): ?>
  <div class="card" style="margin-bottom:24px;">
    <p class="card-title"><i class="ti ti-calendar-off"></i> No Schedule Set</p>
    <?php foreach ($unscheduled as $r): ?>
    <a href="/classroomv2/student/subject_detail.php?id=<?php echo (int)$r['id']; ?>" style="display:block;padding:8px 0;border-top:1px solid var(--border);color:inherit;text-decoration:none;">
      <?php echo htmlspecialchars($r['subject_code']); ?> &middot; <?php echo htmlspecialchars($r['subject_name']); ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>

</main>
</div>
<script src="/classroomv2/assets/ui.js" defer></script>
<script src="/classroomv2/assets/pwa.js" defer></script>
</body>
</html>