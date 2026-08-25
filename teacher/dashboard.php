<!------cat > /mnt/user-data/outputs/classroom_v2/teacher/dashboard.php << 'PHPEOF'--->
<?php
// ============================================================
//  teacher/dashboard.php  —  v2 redesign
//  Self-contained page (no header.php include).
//  Shows subject cards, quick stats, at-risk alerts.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

$teacher_id = $_SESSION['user_id'];

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

// ── Summary numbers ──────────────────────────────────────────
$totals_stmt = $conn->prepare(
    "SELECT
        COUNT(DISTINCT s.id)            AS total_subjects,
        COUNT(DISTINCT e.student_id)    AS total_students,
        ROUND(AVG(g.final_grade),1)     AS overall_avg,
        SUM(g.final_grade >= 75)        AS total_passing,
        SUM(g.final_grade > 0 AND g.final_grade < 75) AS total_failing
     FROM subjects s
     LEFT JOIN subject_enrollments e ON e.subject_id = s.id
     LEFT JOIN subject_grades g      ON g.subject_id = s.id
     WHERE s.teacher_id = ? AND s.is_active = 1"
);
$totals_stmt->bind_param("i", $teacher_id);
$totals_stmt->execute();
$t = $totals_stmt->get_result()->fetch_assoc();

// ── At-risk students (failing in any subject) ────────────────
$at_risk = $conn->prepare(
    "SELECT st.student_id, st.last_name, st.first_name,
            sub.subject_code, sub.subject_name, sub.section,
            g.final_grade, g.letter_grade
     FROM subject_grades g
     JOIN students st   ON st.student_id  = g.student_id
     JOIN subjects sub  ON sub.id         = g.subject_id
     WHERE sub.teacher_id = ? AND g.final_grade > 0 AND g.final_grade < 75
     ORDER BY g.final_grade ASC
     LIMIT 8"
);
$at_risk->bind_param("i", $teacher_id);
$at_risk->execute();
$risk_result = $at_risk->get_result();

// ── Recent score entries ─────────────────────────────────────
$recent_stmt = $conn->prepare(
    "SELECT se.entry_name, se.component, se.score, se.total_items,
            se.date_given, st.last_name, st.first_name,
            sub.subject_code
     FROM score_entries se
     JOIN students st  ON st.student_id = se.student_id
     JOIN subjects sub ON sub.id        = se.subject_id
     WHERE sub.teacher_id = ?
     ORDER BY se.created_at DESC
     LIMIT 6"
);
$recent_stmt->bind_param("i", $teacher_id);
$recent_stmt->execute();
$recent = $recent_stmt->get_result();

// ── Top & Lowest performers, scoped per subject ───────────────
// Step 1: for each subject, find every activity (exam / written work /
// performance task) and when it was last entered.
$la_all_stmt = $conn->prepare(
    "SELECT se.subject_id, se.component, se.entry_name, se.total_items,
            se.date_given, sub.subject_code, sub.subject_name, sub.section,
            MAX(se.created_at) AS latest_created
     FROM score_entries se
     JOIN subjects sub ON sub.id = se.subject_id
     WHERE sub.teacher_id = ?
     GROUP BY se.subject_id, se.component, se.entry_name, se.total_items,
              se.date_given, sub.subject_code, sub.subject_name, sub.section
     ORDER BY latest_created DESC"
);
$la_all_stmt->bind_param("i", $teacher_id);
$la_all_stmt->execute();
$la_rows = $la_all_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Keep only each subject's single most-recent activity. Rows are already
// ordered newest-first, so the first time a subject_id is seen is its latest.
$latest_by_subject = [];
foreach ($la_rows as $row) {
    if (!isset($latest_by_subject[$row['subject_id']])) {
        $latest_by_subject[$row['subject_id']] = $row;
    }
}

// Step 2: for each subject's latest activity, rank every student's score
// and split into up to 3 top performers and up to 3 lowest performers
// (never overlapping, even when the class only has a few scores).
$subject_perf = []; // subject_id => ['activity'=>row, 'top'=>[...], 'bottom'=>[...]]
foreach ($latest_by_subject as $sid => $act) {
    $pf_stmt = $conn->prepare(
        "SELECT st.student_id, st.last_name, st.first_name, se.score, se.total_items
         FROM score_entries se
         JOIN students st ON st.student_id = se.student_id
         WHERE se.subject_id = ? AND se.component = ? AND se.entry_name = ?
         ORDER BY se.score DESC, st.last_name ASC"
    );
    $pf_stmt->bind_param("iss", $sid, $act['component'], $act['entry_name']);
    $pf_stmt->execute();
    $pf_rows  = $pf_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pf_count = count($pf_rows);

    $top_n    = min(3, $pf_count);
    $bottom_n = min(3, max(0, $pf_count - $top_n));

    $subject_perf[$sid] = [
        'activity' => $act,
        'top'      => array_slice($pf_rows, 0, $top_n),
        'bottom'   => $bottom_n > 0
            ? array_reverse(array_slice($pf_rows, $pf_count - $bottom_n, $bottom_n))
            : [],
    ];
}


$type_cfg = [
    'General Education'      => ['color'=>'#1e5f4e','bg'=>'rgba(91,141,238,.1)', 'label'=>'GE'],
    'Professional Education' => ['color'=>'#1e5f4e','bg'=>'rgba(52,211,153,.1)', 'label'=>'PE'],
    'Major Subject'          => ['color'=>'#1e5f4e','bg'=>'rgba(251,191,36,.1)', 'label'=>'MAJ'],
];

$comp_colors = [
    'Major Exam'       => ['color'=>'#7aa3ff','icon'=>'ti-file-certificate'],
    'Written Work'     => ['color'=>'#34d399','icon'=>'ti-pencil'],
    'Performance Task' => ['color'=>'#fbbf24','icon'=>'ti-star'],
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Dashboard — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroomv2/assets/style.css">

</head>
<body class="page-teacher-dashboard">

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <a class="brand" href="/classroomv2/teacher/dashboard.php">
    <img src="/classroomv2/assets/images/TCM logo (2).png" alt="Classroom CMS" width="32" height="32"></span>Classroom Management System
  </a>
  <div class="nav-sep"></div>
  <a href="/classroomv2/teacher/dashboard.php" class="nav-link active">
    <i class="ti ti-layout-dashboard"></i> Dashboard
  </a>

  <!-- Subject dropdown (only if subjects exist) -->
  <?php if ($all_subs->num_rows > 0): ?>
  <div class="nav-dropdown">
    <button class="nav-dd-btn" id="ddBtn" onclick="toggleDD()">
      <i class="ti ti-books"></i> My Subjects
      <i class="ti ti-chevron-down"></i>
    </button>
    <div class="nav-dd-menu" id="ddMenu">
      <?php
      $all_subs->data_seek(0);
      while ($ns = $all_subs->fetch_assoc()):
        $dc = $type_cfg[$ns['subject_type']]['color'] ?? '#00ff1a';
      ?>
      <a href="/classroomv2/teacher/subject_view.php?id=<?php echo $ns['id']; ?>" class="dd-item">
        <span class="dd-dot" style="background:<?php echo $dc; ?>;"></span>
        <span class="dd-main"><?php echo htmlspecialchars($ns['subject_code'].' — '.$ns['subject_name']); ?></span>
        <span class="dd-sub"><?php echo htmlspecialchars($ns['section']); ?></span>
      </a>
      <?php endwhile; ?>
      <div class="dd-divider"></div>
      <a href="/classroomv2/teacher/all_subjects.php" class="dd-item">
        <i class="ti ti-eye" style="color:var(--green);font-size:13px;"></i>
        <span class="dd-main">View All Subjects</span>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <a href="/classroomv2/teacher/add_subject.php" class="nav-link">
    <i class="ti ti-book-plus"></i> Add Subject
  </a>
  <a href="/classroomv2/teacher/manage_sections.php" class="nav-link">
    <i class="ti ti-building-community"></i> Sections
  </a>
  <!-- <a href="/classroomv2/teacher/students.php" class="nav-link">
    <i class="ti ti-users"></i> Students
  </a> -->

  <div class="nav-right">
    <span class="nav-role">Teacher</span>
    <span style="font-size:13px;color:var(--text);"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="/classroomv2/logout.php" class="btn-logout"><i class="ti ti-logout"></i> Logout</a>
  </div>
</nav>

<div class="page-wrap">

  <!-- ── WELCOME BANNER ── -->
  <div class="welcome-banner">
    <div class="welcome-text">
      <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?> 👋</h1>
      <p><?php echo date('l, F d Y'); ?> &nbsp;·&nbsp; <?php echo (int)($t['total_subjects']??0); ?> active subject<?php echo ($t['total_subjects']??0)!=1?'s':''; ?> this semester</p>
    </div>
  </div>



  <!-- ── SUBJECT CARDS ── -->
  <?php if ($all_subs->num_rows === 0): ?>
  <div class="card" style="text-align:center;padding:64px 24px;">
    <i class="ti ti-books" style="font-size:44px;color:var(--text6);display:block;margin-bottom:16px;"></i>
    <p style="font-family:var(--font-head);font-size:18px;font-weight:700;color:var(--text6);margin-bottom:8px;">No subjects yet</p>
    <p style="font-size:13px;color:var(--text6);margin-bottom:24px;">Create your first subject to get started.</p>
    <a href="/classroomv2/teacher/add_subject.php" class="btn btn-primary" style="display:inline-flex;">
      <i class="ti ti-book-plus"></i> Add Your First Subject
    </a>
  </div>
  <?php else:
    $all_subs->data_seek(0);
  ?>
  <?php endif; ?>

  <hr class="thin-line">

  <!-- ── BOTTOM: At-risk + Recent activity ── -->
  <?php if (($t['total_subjects']??0) > 0): ?>
  <div class="bottom-grid" style="margin-top:28px;">

    <!-- At-risk students -->
    <div class="card">
      <p class="card-title">
        <i class="ti ti-alert-triangle" style="color:var(--red);"></i>
        At-Risk Students
        <?php if ($risk_result->num_rows > 0): ?>
          <span style="margin-left:auto;font-family:var(--font-mono);font-size:11px;color:var(--red);">
            <?php echo $risk_result->num_rows; ?> flagged
          </span>
        <?php endif; ?>
      </p>
      <?php if ($risk_result->num_rows === 0): ?>
        <div class="empty-state" style="padding:24px;">
          <i class="ti ti-circle-check" style="color:var(--green);font-size:28px;"></i>
          <p style="color:var(--text7);margin-top:8px;">No failing students — great job!</p>
        </div>
      <?php else: ?>
        <?php while ($r = $risk_result->fetch_assoc()):
          $initials = strtoupper(substr($r['last_name'],0,1).substr($r['first_name'],0,1));
        ?>
        <div class="risk-item">
          <div class="risk-avatar"><?php echo $initials; ?></div>
          <div>
            <div class="risk-name"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
            <div class="risk-sub">
              <?php echo htmlspecialchars($r['subject_code']); ?> — <?php echo htmlspecialchars($r['section']); ?>
            </div>
          </div>
          <div class="risk-grade"><?php echo number_format($r['final_grade'],1); ?>%</div>
        </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

    <!-- Top & Lowest performers (each subject's own most recent activity) -->
    <div class="card">
      <p class="card-title">
        <i class="ti ti-trophy" style="color: var(--yellow2);"></i>
        Top &amp; Lowest Performers
        <?php if (!empty($subject_perf)): ?>
          <span class="tlp-sub-count">
            <?php echo count($subject_perf); ?> subject<?php echo count($subject_perf)!=1?'s':''; ?>
          </span>
        <?php endif; ?>
      </p>

      <?php if (empty($subject_perf)): ?>
        <div class="empty-state" style="padding:24px;">
          <i class="ti ti-pencil-off"></i>
          <p class="grey-font">No score entries yet.</p>
        </div>
      <?php else: ?>

        <?php foreach ($subject_perf as $sid => $data):
          $act  = $data['activity'];
          $pkey = 'sp' . $sid;
          $top1 = $data['top'][0]    ?? null;
          $bot1 = $data['bottom'][0] ?? null;
        ?>
        <div class="tlp-entry">
          <div class="tlp-entry-off"
              onclick="togglePerf('<?php echo $pkey; ?>')">
            <div class="per-student-performer">
              <i class="ti ti-chevron-down tlp-dd" id="perf_chev_<?php echo $pkey; ?>"></i>
              <div style="min-width:0;">
                <div class="tlp-sub-perf-title">
                  <?php echo htmlspecialchars($act['subject_code']); ?> · <?php echo htmlspecialchars($act['entry_name']); ?>
                </div>
                <div class="tlp-sec-date">
                  <?php echo htmlspecialchars($act['section']); ?> · <?php echo date('M d', strtotime($act['date_given'])); ?>
                </div>
              </div>
            </div>
            <div class="inside-chevdown3">
              <?php if ($top1):
                $p1 = $top1['total_items'] > 0 ? round($top1['score']/$top1['total_items']*100,1) : 0;
              ?>
              <span class="green-font"><i class="ti ti-arrow-up"></i> <?php echo $p1; ?>%</span>
              <?php endif; ?>
              <?php if ($bot1):
                $p2 = $bot1['total_items'] > 0 ? round($bot1['score']/$bot1['total_items']*100,1) : 0;
              ?>
              <span class="red-font"><i class="ti ti-arrow-down"></i> <?php echo $p2; ?>%</span>
              <?php endif; ?>
            </div>
          </div>

          <div id="perf_body_<?php echo $pkey; ?>" class="perf-body">
            <div class="perf-subhead green-font">
              <i class="ti ti-arrow-up"></i> Top Performers
            </div>
            <?php foreach ($data['top'] as $r):
              $pct = $r['total_items'] > 0 ? round($r['score'] / $r['total_items'] * 100, 1) : 0;
              $initials = strtoupper(substr($r['last_name'],0,1).substr($r['first_name'],0,1));
            ?>
            <div class="perf-item">
              <div class="perf-avatar">
                <?php echo $initials; ?>
              </div>
              <div>
                <div class="perf-name"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
                <div class="perf-sub"><?php echo (int)$r['score'].'/'.(int)$r['total_items']; ?></div>
              </div>
              <div class="perf-score" style="color:var(--green);"><?php echo $pct; ?>%</div>
            </div>
            <?php endforeach; ?>

            <div class="perf-subhead">
              <i class="ti ti-arrow-down"></i> Lowest Performers
            </div>
            <?php if (empty($data['bottom'])): ?>
              <p class="empty-state2">Not enough separate scores yet to list lowest performers.</p>
            <?php else: ?>
              <?php foreach ($data['bottom'] as $r):
                $pct = $r['total_items'] > 0 ? round($r['score'] / $r['total_items'] * 100, 1) : 0;
                $initials = strtoupper(substr($r['last_name'],0,1).substr($r['first_name'],0,1));
              ?>
              <div class="perf-item perf-item-lowest">
                <div class="perf-avatar perf-avatar-lowest">
                  <?php echo $initials; ?>
                </div>
                <div>
                  <div class="perf-name"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
                  <div class="perf-sub"><?php echo (int)$r['score'].'/'.(int)$r['total_items']; ?></div>
                </div>
                <div class="perf-score" style="color:var(--red);"><?php echo $pct; ?>%</div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

      <?php endif; ?>
    </div>

    <!-- Recent score entries -->
    <div class="card">
      <p class="card-title">
        <i class="ti ti-activity" style="color: var(--green);"></i>
        Recent Entries
      </p>
      <?php if ($recent->num_rows === 0): ?>
        <div class="empty-state" style="padding:24px;">
          <i class="ti ti-pencil-off"></i>
          <p class="grey-font">No score entries yet.</p>
        </div>
      <?php else: ?>
        <?php while ($r = $recent->fetch_assoc()):
          $cc = $comp_colors[$r['component']] ?? ['color'=>'#7aa3ff','icon'=>'ti-pencil'];
          $pct = $r['total_items'] > 0 ? round($r['score']/$r['total_items']*100,1) : 0;
        ?>
        <div class="recent-item">
          <div class="recent-icon" style="background:<?php echo $cc['color']; ?>18;color:<?php echo $cc['color']; ?>;">
            <i class="ti <?php echo $cc['icon']; ?>"></i>
          </div>
          <div class="recent-main">
            <div class="recent-title">
              <?php echo htmlspecialchars($r['entry_name']); ?>
              <span>
                &nbsp; · &nbsp;&nbsp;<?php echo htmlspecialchars($r['subject_code']); ?>
              </span>
            </div>
            <div class="recent-sub">
              <?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?>
              &nbsp; ·<?php echo date('M d', strtotime($r['date_given'])); ?>
            </div>
          </div>
          <div class="recent-score">
            <?php echo $r['score'].'/'.$r['total_items']; ?>
            <span style="color:<?php echo $pct>=75?'var(--green)':'var(--red)'; ?>;">
              (<?php echo $pct; ?>%)
            </span>
          </div>
        </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>

  </div>
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

// ── Top/Lowest performers per-subject accordion ──────
function togglePerf(key) {
  const body = document.getElementById('perf_body_' + key);
  const chev = document.getElementById('perf_chev_' + key);
  if (!body) return;
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : 'block';
  if (chev) chev.style.transform = isOpen ? '' : 'rotate(180deg)';
}

</script>

</body>
</html>
<!--PHPEOF
echo "done"-->