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
             WHERE subject_id = s.id AND final_grade >= " . PASSING_GRADE . ")                   AS passing,
            (SELECT COUNT(*)
             FROM subject_grades
             WHERE subject_id = s.id AND final_grade > 0 AND final_grade < " . PASSING_GRADE . ") AS failing,
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
        SUM(g.final_grade >= " . PASSING_GRADE . ")        AS total_passing,
        SUM(g.final_grade > 0 AND g.final_grade < " . PASSING_GRADE . ") AS total_failing
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
$range_start = 7*60;    // 7:00 AM in minutes-since-midnight
$range_end   = 21*60;   // 5:00 PM

$all_subjects_rows = [];       // ACTIVE subjects — shown on the timetable
$inactive_rows     = [];       // INACTIVE subjects — shown as a history table
$subjects->data_seek(0);
while ($sub = $subjects->fetch_assoc()) {
    if (!$sub['is_active']) { $inactive_rows[] = $sub; continue; }
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
// Inactive subjects are history records, not part of the weekly timetable.
// Group them by school year (newest first) then semester, because several
// inactive subjects can share the same days/times across different terms.
$sem_rank = ['1st' => 1, '2nd' => 2, 'Summer' => 3];
$history = [];   // [school_year][semester] => rows
foreach ($inactive_rows as $r) {
    $sy = trim($r['school_year'] ?? '') !== '' ? $r['school_year'] : 'No school year';
    $sm = trim($r['semester'] ?? '')    !== '' ? $r['semester']    : 'No semester';
    $history[$sy][$sm][] = $r;
}
uksort($history, function ($a, $b) {   // newest school year first; "No school year" last
    if ($a === 'No school year') return 1;
    if ($b === 'No school year') return -1;
    return strnatcmp($b, $a);
});
foreach ($history as $sy => &$sems) {
    uksort($sems, fn($a, $b) => ($sem_rank[$b] ?? 0) <=> ($sem_rank[$a] ?? 0));  // 2nd before 1st
    foreach ($sems as &$list) {
        usort($list, fn($a, $b) => strcasecmp($a['subject_name'], $b['subject_name']));
    }
    unset($list);
}
unset($sems);

function fmt_schedule_text($sub) {
    if (empty($sub['schedule_days']) || empty($sub['schedule_start_time']) || empty($sub['schedule_end_time'])) return '—';
    $days = implode(', ', array_filter(explode(',', $sub['schedule_days'])));
    return $days . ' · ' . date('g:i A', strtotime($sub['schedule_start_time']))
         . '–' . date('g:i A', strtotime($sub['schedule_end_time']));
}

// Snap the grid to whole hours so every row is a clean hour cell.
$range_start = (int)(floor($range_start / 60) * 60);
$range_end   = (int)(ceil($range_end / 60) * 60);
if ($range_end <= $range_start) $range_end = $range_start + 60;
$hour_h      = 64;                                   // px per hour row
$hours_count = (int)(($range_end - $range_start) / 60);
$px_per_min  = $hour_h / 60;
$grid_height = $hours_count * $hour_h;
$day_short   = ['Mon'=>'MON','Tue'=>'TUE','Wed'=>'WED','Thu'=>'THU','Fri'=>'FRI','Sat'=>'SAT','Sun'=>'SUN'];
$today_key   = date('D');
// Term label under the title: the school year / semester most of the
// active subjects belong to.
$term_counts = [];
foreach ($all_subjects_rows as $r) {
    $lab = trim(($r['school_year'] ?? '') . '|' . ($r['semester'] ?? ''));
    $term_counts[$lab] = ($term_counts[$lab] ?? 0) + 1;
}
arsort($term_counts);
$term_label = '';
if ($term_counts) {
    [$ty, $tm] = array_pad(explode('|', array_key_first($term_counts), 2), 2, '');
    $term_label = trim(($ty !== '' ? 'S.Y. ' . $ty : '') . ($tm !== '' ? ' · ' . $tm . (in_array($tm, ['1st','2nd'], true) ? ' Semester' : '') : ''));
}

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
    <a href="all_subjects.php" class="btn btn-primary" style="display:inline-flex; max-width: 50%; margin-top:25px;">
      <i class="ti ti-refresh"></i> Reset Filters
    </a>
        <a href="add_subject.php" class="btn btn-primary" style="display:inline-flex;margin-top:15px; max-width: 50%">
      <i class="ti ti-plus"></i> Add a Subject
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

  <?php if ($all_subjects_rows): ?>
  <!-- ── WEEKLY SCHEDULE (active subjects) ── -->
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
        <!-- header row -->
        <div class="wk-head"></div>
        <?php foreach ($day_order as $d): ?>
        <div class="wk-head <?php echo $d === $today_key ? 'is-today' : ''; ?>"><?php echo $day_short[$d]; ?></div>
        <?php endforeach; ?>

        <!-- time labels -->
        <div class="wk-timecol">
          <?php for ($h = 0; $h < $hours_count; $h++):
            $mm = $range_start + $h * 60;
          ?>
          <div class="wk-time"><?php echo date('g:i A', strtotime('1970-01-01 ' . floor($mm/60) . ':00')); ?></div>
          <?php endfor; ?>
        </div>

        <!-- one column per day -->
        <?php foreach ($day_order as $d): ?>
        <div class="wk-col <?php echo $d === $today_key ? 'is-today' : ''; ?>">
          <?php for ($h = 0; $h < $hours_count; $h++): ?><div class="wk-cell"></div><?php endfor; ?>

          <?php foreach ($by_day[$d] as $sub):
            $cls = ['General Education'=>'wk-ge','Professional Education'=>'wk-pe','Major Subject'=>'wk-maj'][$sub['subject_type']] ?? 'wk-ge';
            $sm  = (int)date('H', strtotime($sub['schedule_start_time'])) * 60 + (int)date('i', strtotime($sub['schedule_start_time']));
            $em  = (int)date('H', strtotime($sub['schedule_end_time']))   * 60 + (int)date('i', strtotime($sub['schedule_end_time']));
            $top    = round(($sm - $range_start) * $px_per_min);
            $height = max(30, round(($em - $sm) * $px_per_min) - 2);
            $tstr = date('g:i A', strtotime($sub['schedule_start_time'])) . '–' . date('g:i A', strtotime($sub['schedule_end_time']));
          ?>
          <a href="/classroomv2/teacher/subject_view.php?id=<?php echo (int)$sub['id']; ?>"
             class="wk-block <?php echo $cls; ?>"
             style="top:<?php echo $top + 1; ?>px;height:<?php echo $height; ?>px;"
             title="<?php echo htmlspecialchars($sub['subject_code'].' · '.$sub['subject_name'].' · '.$sub['section'].' · '.$tstr); ?>">
            <div class="b-time"><?php echo $tstr; ?></div>
            <div class="b-code"><?php echo htmlspecialchars($sub['subject_code']); ?></div>
            <div class="b-name"><?php echo htmlspecialchars($sub['subject_name']); ?></div>
            <div class="b-meta"><?php echo htmlspecialchars($sub['section']); ?> &middot; <?php echo (int)$sub['enrollee_count']; ?> students</div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

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

  <?php if ($history): ?>
  <!-- ── INACTIVE SUBJECTS — history, grouped by school year & semester ── -->
  <div class="section-label" style="margin-top:28px;">
    <span><i class="ti ti-archive"></i> Inactive subjects (history) &mdash; <?php echo count($inactive_rows); ?></span>
  </div>
  <?php foreach ($history as $sy => $sems): foreach ($sems as $sm => $rows): ?>
  <div class="card" style="margin-bottom:16px;">
    <p class="card-title">
      <i class="ti ti-calendar-event"></i>
      S.Y. <?php echo htmlspecialchars($sy); ?> &middot; <?php echo htmlspecialchars($sm); ?><?php echo in_array($sm, ['1st','2nd'], true) ? ' Semester' : ''; ?>
      <span class="card-title-right" style="font-size:11px;font-weight:400;color:var(--text3);">
        <?php echo count($rows); ?> subject<?php echo count($rows)!=1?'s':''; ?>
      </span>
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Code</th>
            <th>Subject</th>
            <th>Section</th>
            <th>Type</th>
            <th>Schedule</th>
            <th style="text-align:right;">Students</th>
            <th style="text-align:right;">Avg</th>
            <th style="text-align:right;">Pass / Fail</th>
            <th>Closed</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $cfg = $type_cfg[$r['subject_type']] ?? $type_cfg['General Education']; ?>
          <tr>
            <td class="td-mono"><?php echo htmlspecialchars($r['subject_code']); ?></td>
            <td style="font-weight:500;"><?php echo htmlspecialchars($r['subject_name']); ?></td>
            <td><?php echo htmlspecialchars($r['section']); ?></td>
            <td><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;background:<?php echo $cfg['bg']; ?>;color:<?php echo $cfg['color']; ?>;"><?php echo $cfg['label']; ?></span></td>
            <td style="font-size:12px;color:var(--text7);white-space:nowrap;"><?php echo htmlspecialchars(fmt_schedule_text($r)); ?></td>
            <td style="text-align:right;"><?php echo (int)$r['enrollee_count']; ?></td>
            <td style="text-align:right;"><?php echo $r['class_avg'] !== null ? $r['class_avg'].'%' : '—'; ?></td>
            <td style="text-align:right;white-space:nowrap;"><?php echo (int)$r['passing']; ?> / <?php echo (int)$r['failing']; ?></td>
            <td style="font-size:12px;color:var(--text7);white-space:nowrap;"><?php echo !empty($r['deactivated_at']) ? date('M d, Y', strtotime($r['deactivated_at'])) : '—'; ?></td>
            <td style="text-align:right;">
              <a href="/classroomv2/teacher/subject_view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-outline btn-sm">
                <i class="ti ti-eye"></i> View
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; endforeach; ?>
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
<script src="/classroomv2/assets/ui.js" defer></script>
<script src="/classroomv2/assets/pwa.js" defer></script>
</body>
</html>