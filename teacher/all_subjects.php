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

  <div class="subject-grid">
    <?php while ($sub = $subjects->fetch_assoc()):
      $cfg   = $type_cfg[$sub['subject_type']] ?? $type_cfg['General Education'];
      $avg   = (float)($sub['class_avg'] ?? 0);
      $count = (int)$sub['enrollee_count'];
      $days  = (int)$sub['class_days'];
    ?>
    <a href="/classroomv2/teacher/subject_view.php?id=<?php echo $sub['id']; ?>" class="subject-card"
       style="<?php echo $sub['is_active'] ? '' : 'opacity:.6;'; ?>">
      <div class="sc-bar" style="background:<?php echo $cfg['color']; ?>;"></div>

      <div class="sc-top">
        <div>
          <div class="sc-code"><?php echo htmlspecialchars($sub['subject_code']); ?></div>
          <div class="sc-name"><?php echo htmlspecialchars($sub['subject_name']); ?></div>
        </div>
        <span class="sc-type-pill"
          style="background:<?php echo $cfg['bg']; ?>;color:<?php echo $cfg['color']; ?>;border:1px solid <?php echo $cfg['color'].'33'; ?>;">
          <?php echo $cfg['label']; ?>
        </span>
      </div>

      <?php if (!$sub['is_active']): ?>
      <div style="margin-bottom:8px;">
        <span style="font-size:10px;font-weight:700;color:var(--text3);background:var(--bg2);
                     border:1px solid var(--border);border-radius:99px;padding:2px 8px;">
          <i class="ti ti-archive"></i> INACTIVE
        </span>
      </div>
      <?php endif; ?>

      <div class="sc-meta">
        <span><i class="ti ti-users"></i> <?php echo $count; ?> students</span>
        <span><i class="ti ti-school"></i> <?php echo htmlspecialchars($sub['section']); ?></span>
        <span><i class="ti ti-calendar"></i> <?php echo $sub['semester']; ?> — <?php echo htmlspecialchars($sub['school_year']); ?></span>
        <?php if ($days > 0): ?>
        <span><i class="ti ti-calendar-check"></i> <?php echo $days; ?> class day<?php echo $days!=1?'s':''; ?></span>
        <?php endif; ?>
        <?php $sched = formatSchedule($sub['schedule_days'] ?? '', $sub['schedule_start_time'] ?? '', $sub['schedule_end_time'] ?? ''); ?>
        <?php if ($sched): ?>
        <span><i class="ti ti-clock"></i> <?php echo $sched; ?></span>
        <?php endif; ?>
      </div>

      <div class="sc-weights">
        <span class="wc exam"><i class="ti ti-file-certificate" style="font-size:10px;"></i> Exam <?php echo (int)$sub['exam_pct']; ?>%</span>
        <span class="wc written"><i class="ti ti-pencil" style="font-size:10px;"></i> Written <?php echo (int)$sub['written_pct']; ?>%</span>
        <span class="wc perf"><i class="ti ti-star" style="font-size:10px;"></i> Perf <?php echo (int)$sub['performance_pct']; ?>%</span>
      </div>

      <?php if ($avg > 0): ?>
      <div class="sc-grade-row">
        <span>Class Average</span>
        <span style="font-weight:700;color:<?php echo $avg>=75?'var(--green)':'var(--red)'; ?>">
          <?php echo $avg; ?>%
        </span>
      </div>
      <div class="score-bar-track">
        <div class="score-bar-fill"
          style="width:<?php echo min($avg,100); ?>%;
                 background:<?php echo $avg>=75?'var(--green)':'var(--red)'; ?>;">
        </div>
      </div>
      <div class="sc-pass-row">
        <span style="color:var(--green);">
          <i class="ti ti-check"></i> <?php echo (int)($sub['passing']??0); ?> passing
        </span>
        <span style="color:var(--red);">
          <i class="ti ti-x"></i> <?php echo (int)($sub['failing']??0); ?> failing
        </span>
      </div>
      <?php else: ?>
      <div>
        <i class="ti ti-clock"></i> No grades recorded yet — click to start
      </div>
      <?php endif; ?>

    </a>
    <?php endwhile; ?>
  </div>

  <!-- <div class="legend">
    <div class="legend-item"><div class="legend-dot" style="background:#7aa3ff;"></div>General Education (30/30/40)</div>
    <div class="legend-item"><div class="legend-dot" style="background:#34d399;"></div>Professional Education (25/25/50)</div>
    <div class="legend-item"><div class="legend-dot" style="background:#fbbf24;"></div>Major Subject (40/20/40)</div>
  </div> -->

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