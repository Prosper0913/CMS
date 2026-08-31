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
            sub.schedule_days, sub.schedule_start_time, sub.schedule_end_time
     FROM subject_enrollments e
     JOIN subjects sub ON sub.id = e.subject_id
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

while ($sub = $subs_result->fetch_assoc()) {
    $sub_days = array_filter(explode(',', $sub['schedule_days'] ?? ''));
    foreach ($sub_days as $d) {
        if (isset($by_day[$d])) $by_day[$d][] = $sub;
    }
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
      <div style="font-family:var(--font-head);font-size:36px;font-weight:800;color:<?php echo $today_count>0?'var(--accent)':'var(--text7)'; ?>;">
        <?php echo $today_count; ?>
      </div>
      <div><?php echo $today_count === 1 ? 'Class Today' : 'Classes Today'; ?></div>
    </div>
  </div>

  <p class="bottom-margin">
    My Weekly Schedule
  </p>

  <?php if ($total_subjects === 0): ?>
  <div class="empty-state">
    <i class="ti ti-calendar-off" style="color:var(--text6);"></i>
    <p>You are not enrolled in any subjects yet.</p>
    <p style="font-size:12px;margin-top:6px;color:var(--text3);">Contact your teacher to be enrolled.</p>
  </div>
  <?php else: ?>

  <div class="schedule-week">
    <?php foreach ($day_order as $d):
      $is_today = ($d === $today_key);
    ?>
    <div class="schedule-day <?php echo $is_today ? 'is-today' : ''; ?>">
      <div class="schedule-day-header">
        <span><?php echo $day_full[$d]; ?></span>
        <?php if ($is_today): ?><span class="schedule-today-badge">Today</span><?php endif; ?>
      </div>

      <?php if (empty($by_day[$d])): ?>
      <div class="schedule-empty">No classes</div>
      <?php else: ?>
      <?php foreach ($by_day[$d] as $sub):
        $color = $type_colors[$sub['subject_type']] ?? $type_colors['General Education'];
        $sched = formatSchedule($sub['schedule_days'], $sub['schedule_start_time'], $sub['schedule_end_time']);
      ?>
      <a href="/classroomv2/student/subject_detail.php?id=<?php echo $sub['id']; ?>" class="schedule-item" style="border-left-color:<?php echo $color; ?>;">
        <div class="schedule-item-time">
          <?php echo date('g:i A', strtotime($sub['schedule_start_time'])); ?> &ndash; <?php echo date('g:i A', strtotime($sub['schedule_end_time'])); ?>
        </div>
        <div class="schedule-item-name"><?php echo htmlspecialchars($sub['subject_name']); ?></div>
        <div class="schedule-item-meta"><?php echo htmlspecialchars($sub['subject_code']); ?> &middot; <?php echo htmlspecialchars($sub['section']); ?></div>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>

</div>

</main>
</div>
</body>
</html>
