<?php
// ============================================================
//  teacher/all_subjects.php
//  Dedicated "All Subjects" overview page.
//  Same subject-card design language as dashboard.php's
//  "Your Subject-Sections" grid, but as its own full page with
//  search, type/semester/status filters, and full stats.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$teacher_id = $_SESSION['user_id'];

// ── Read filters from querystring ────────────────────────────
$search  = trim($_GET['search']  ?? '');
$type    = trim($_GET['type']    ?? '');
$sem     = trim($_GET['sem']     ?? '');
$status  = trim($_GET['status']  ?? 'active'); // active | inactive | all

$valid_types = ['General Education', 'Professional Education', 'Major Subject'];
$valid_sems  = ['1st', '2nd', 'Summer'];
if (!in_array($type, $valid_types, true)) $type = '';
if (!in_array($sem, $valid_sems, true))   $sem  = '';
if (!in_array($status, ['active', 'inactive', 'all'], true)) $status = 'active';

// ── Build WHERE clause dynamically ───────────────────────────
$where  = "s.teacher_id = ?";
$params = [$teacher_id];
$types  = "i";

if ($status === 'active') {
    $where .= " AND s.is_active = 1";
} elseif ($status === 'inactive') {
    $where .= " AND s.is_active = 0";
}
if ($search !== '') {
    $where .= " AND (s.subject_code LIKE ? OR s.subject_name LIKE ? OR s.section LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if ($type !== '') {
    $where .= " AND s.subject_type = ?";
    $params[] = $type;
    $types   .= "s";
}
if ($sem !== '') {
    $where .= " AND s.semester = ?";
    $params[] = $sem;
    $types   .= "s";
}

// ── Main query: every matching subject + per-subject stats ───
$sql = "SELECT s.*,
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
         WHERE $where
         ORDER BY s.is_active DESC, s.semester DESC, s.subject_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$subjects = $stmt->get_result();

// ── Subject dropdown data for the navbar (active subjects only, unfiltered) ──
$nav_subs_stmt = $conn->prepare(
    "SELECT id, subject_code, subject_name, section, subject_type
     FROM subjects WHERE teacher_id = ? AND is_active = 1
     ORDER BY semester DESC, subject_name ASC"
);
$nav_subs_stmt->bind_param("i", $teacher_id);
$nav_subs_stmt->execute();
$nav_subs = $nav_subs_stmt->get_result();

// ── Summary numbers, scoped to the SAME filters as the grid ───
$totals_sql = "SELECT
        COUNT(DISTINCT s.id)            AS total_subjects,
        COUNT(DISTINCT e.student_id)    AS total_students,
        ROUND(AVG(g.final_grade),1)     AS overall_avg,
        SUM(g.final_grade >= 75)        AS total_passing,
        SUM(g.final_grade > 0 AND g.final_grade < 75) AS total_failing
     FROM subjects s
     LEFT JOIN subject_enrollments e ON e.subject_id = s.id
     LEFT JOIN subject_grades g      ON g.subject_id = s.id
     WHERE $where";
$totals_stmt = $conn->prepare($totals_sql);
$totals_stmt->bind_param($types, ...$params);
$totals_stmt->execute();
$t = $totals_stmt->get_result()->fetch_assoc();

$type_cfg = [
    'General Education'      => ['color'=>'#1e5f4e','bg'=>'rgba(122,163,255,.1)', 'label'=>'GE'],
    'Professional Education' => ['color'=>'#1e5f4e','bg'=>'rgba(52,211,153,.1)',  'label'=>'PE'],
    'Major Subject'          => ['color'=>'#1e5f4e','bg'=>'rgba(251,191,36,.1)',  'label'=>'MAJ'],
];

// ── Group subjects by day for the timetable, and work out the
//    time range to render. Defaults to a typical instructor day,
//    7:30 AM–5:30 PM, but widens automatically if any class falls
//    outside that window rather than clipping it. ──
$day_order = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$day_full  = ['Mon'=>'Monday','Tue'=>'Tuesday','Wed'=>'Wednesday','Thu'=>'Thursday','Fri'=>'Friday','Sat'=>'Saturday','Sun'=>'Sunday'];
$by_day = array_fill_keys($day_order, []);
$range_start = 7*60 + 30;   // 7:30 AM in minutes-since-midnight
$range_end   = 17*60 + 30;  // 5:30 PM

$all_subjects_rows = [];
$subjects->data_seek(0);
while ($sub = $subjects->fetch_assoc()) {
    $all_subjects_rows[] = $sub;
    if (!empty($sub['schedule_start_time']) && !empty($sub['schedule_end_time'])) {
        $sm = (int)date('H', strtotime($sub['schedule_start_time'])) * 60 + (int)date('i', strtotime($sub['schedule_start_time']));
        $em = (int)date('H', strtotime($sub['schedule_end_time']))   * 60 + (int)date('i', strtotime($sub['schedule_end_time']));
        if ($sm < $range_start) $range_start = $sm;
        if ($em > $range_end)   $range_end   = $em;

        $sub_days = array_filter(explode(',', $sub['schedule_days'] ?? ''));
        foreach ($sub_days as $d) {
            if (isset($by_day[$d])) $by_day[$d][] = $sub;
        }
    }
}
$total_range = max(1, $range_end - $range_start);
$px_per_min  = 1.2;
$grid_height = round($total_range * $px_per_min);

// helper to keep other filters when a filter link is clicked
function qs($overrides = []) {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }
    return htmlspecialchars('?' . http_build_query($params));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>All Subjects — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-teacher-all_subjects">
<div class="app-shell">


<!-- ── NAVBAR ── -->
<?php $active_nav = 'subjects'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <!-- ── TOP STATS (scoped to current filters) ── 
  <div class="stats-row">
    <div class="stat-card stat-green">
      <div class="stat-label">Subjects</div>
      <div class="stat-value"><?php echo (int)($t['total_subjects']??0); ?></div>
      <div class="stat-sub"><?php echo $status==='all' ? 'matching filters' : $status; ?></div>
    </div>
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Students</div>
      <div class="stat-value"><?php echo (int)($t['total_students']??0); ?></div>
      <div class="stat-sub">across these subjects</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Passing</div>
      <div class="stat-value"><?php echo (int)($t['total_passing']??0); ?></div>
      <div class="stat-sub">grade ≥ 75</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-label">Failing</div>
      <div class="stat-value"><?php echo (int)($t['total_failing']??0); ?></div>
      <div class="stat-sub">need attention</div>
    </div>
    <div class="stat-card stat-yellow">
      <div class="stat-label">Overall Avg</div>
      <div class="stat-value"><?php echo $t['overall_avg'] ? $t['overall_avg'].'%' : '—'; ?></div>
      <div class="stat-sub">these subjects</div>
    </div>
  </div>
-->

  <!-- ── SEARCH + FILTERS ── -->
  <div class="card" style="margin-bottom:24px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">

      <div class="form-group" style="flex:1;min-width:220px;margin-bottom:0;">
        <label>Search</label>
        <div class="input-wrap">
          <i class="ti ti-search"></i>
          <input type="text" name="search" class="form-control"
                 placeholder="Code, name, or section…"
                 value="<?php echo htmlspecialchars($search); ?>">
        </div>
      </div>

      <div class="form-group" style="margin-bottom:0;">
        <label>Type</label>
        <select name="type" class="form-control">
          <option value="">All Types</option>
          <?php foreach ($valid_types as $vt): ?>
          <option value="<?php echo $vt; ?>" <?php echo $type===$vt?'selected':''; ?>><?php echo $vt; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:0;">
        <label>Semester</label>
        <select name="sem" class="form-control">
          <option value="">All Semesters</option>
          <?php foreach ($valid_sems as $vs): ?>
          <option value="<?php echo $vs; ?>" <?php echo $sem===$vs?'selected':''; ?>><?php echo $vs; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin-bottom:0;">
        <label>Status</label>
        <select name="status" class="form-control">
          <option value="active"   <?php echo $status==='active'?'selected':'';   ?>>Active</option>
          <option value="inactive" <?php echo $status==='inactive'?'selected':''; ?>>Inactive</option>
          <option value="all"      <?php echo $status==='all'?'selected':'';      ?>>All</option>
        </select>
      </div>

      <button type="submit" class="btn btn-outline btn-sm"><i class="ti ti-filter"></i> Apply</button>
      <?php if ($search || $type || $sem || $status !== 'active'): ?>
        <a href="all_subjects.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i> Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── SUBJECT CARDS ── -->
  <?php if ($subjects->num_rows === 0): ?>
  <div class="card" style="text-align:center;padding:64px 24px;">
    <i class="ti ti-books" style="font-size:44px;color:var(--text7);display:block;margin-bottom:16px;"></i>
    <p style="font-family:var(--font-head);font-size:18px;font-weight:700;color:var(--text7);margin-bottom:8px;">
      No subjects match these filters
    </p>
    <p style="font-size:13px;color:var(--text7);margin-bottom:24px;">
      Try clearing search or adjusting the filters above.
    </p>
    <a href="all_subjects.php" class="btn btn-primary" style="display:inline-flex;">
      <i class="ti ti-refresh"></i> Reset Filters
    </a>
  </div>
  <?php else: ?>

  <div class="section-label">
    <span>
      <?php echo $subjects->num_rows; ?> subject-section<?php echo $subjects->num_rows!=1?'s':''; ?>
    </span>
    <a href="/classroomv2/teacher/add_subject.php" class="btn btn-outline btn-sm">
      <i class="ti ti-plus"></i> New Subject
    </a>
  </div>

  <div class="timetable-wrap">
    <div class="timetable-times" style="height:<?php echo $grid_height; ?>px;">
      <?php
      // Hour labels down the left edge, matching the grid's scale
      for ($m = $range_start; $m <= $range_end; $m += 60):
        $top = round(($m - $range_start) * $px_per_min);
      ?>
      <div class="timetable-time-label" style="top:<?php echo $top; ?>px;"><?php echo date('g:i A', strtotime('1970-01-01 ' . floor($m/60) . ':' . ($m%60))); ?></div>
      <?php endfor; ?>
    </div>

    <div class="timetable-grid">
      <?php foreach ($day_order as $d): ?>
      <div class="timetable-day-col">
        <div class="timetable-day-label"><?php echo $day_full[$d]; ?></div>
        <div class="timetable-day-body" style="height:<?php echo $grid_height; ?>px;background-size:100% <?php echo round(60*$px_per_min); ?>px;">
          <?php foreach ($by_day[$d] as $sub):
            $cfg = $type_cfg[$sub['subject_type']] ?? $type_cfg['General Education'];
            $sm  = (int)date('H', strtotime($sub['schedule_start_time'])) * 60 + (int)date('i', strtotime($sub['schedule_start_time']));
            $em  = (int)date('H', strtotime($sub['schedule_end_time']))   * 60 + (int)date('i', strtotime($sub['schedule_end_time']));
            $top    = round(($sm - $range_start) * $px_per_min);
            $height = max(28, round(($em - $sm) * $px_per_min));
          ?>
          <a href="/classroomv2/teacher/subject_view.php?id=<?php echo $sub['id']; ?>"
             class="timetable-block <?php echo $sub['is_active'] ? '' : 'is-inactive'; ?>"
             style="top:<?php echo $top; ?>px;height:<?php echo $height; ?>px;border-left-color:<?php echo $cfg['color']; ?>;">
            <div class="tt-time"><?php echo date('g:i A', strtotime($sub['schedule_start_time'])); ?>&ndash;<?php echo date('g:i A', strtotime($sub['schedule_end_time'])); ?></div>
            <div class="tt-name"><?php echo htmlspecialchars($sub['subject_code']); ?> &middot; <?php echo htmlspecialchars($sub['subject_name']); ?></div>
            <div class="tt-meta"><?php echo htmlspecialchars($sub['section']); ?> &middot; <?php echo (int)$sub['enrollee_count']; ?> students</div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php
  // Subjects with no schedule set yet still need to be reachable
  $unscheduled = array_filter($all_subjects_rows, fn($s) => empty($s['schedule_days']) || empty($s['schedule_start_time']));
  if ($unscheduled):
  ?>
  <div class="card" style="margin-top:20px;">
    <p class="card-title"><i class="ti ti-calendar-off"></i> No Schedule Set</p>
    <p style="font-size:12px;color:var(--text7);margin-bottom:12px;">
      These subjects don't have a class schedule yet — edit each one's Settings tab to add one.
    </p>
    <?php foreach ($unscheduled as $sub): ?>
    <a href="/classroomv2/teacher/subject_view.php?id=<?php echo $sub['id']; ?>" style="display:block;padding:8px 0;border-top:1px solid var(--border);color:inherit;text-decoration:none;">
      <?php echo htmlspecialchars($sub['subject_code']); ?> &middot; <?php echo htmlspecialchars($sub['subject_name']); ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

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