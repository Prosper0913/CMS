<?php
// ============================================================
//  student/subject_detail.php
//  Student's detailed view of one subject:
//    - Grade breakdown overview
//    - Scores per component (Exam / Written / Performance)
//    - Attendance log
// ============================================================
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$sid        = $_SESSION['student_id'];
$subject_id = (int)($_GET['id'] ?? 0);

if (!$subject_id) {
    header("Location: /classroom/student/dashboard.php");
    exit;
}

// ── Verify student is enrolled in this subject ───────────────
$chk = $conn->prepare(
    "SELECT e.subject_id FROM subject_enrollments e
     WHERE e.subject_id = ? AND e.student_id = ? LIMIT 1"
);
$chk->bind_param("is", $subject_id, $sid);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    header("Location: /classroom/student/dashboard.php");
    exit;
}

// ── Subject info ─────────────────────────────────────────────
$subq = $conn->prepare(
    "SELECT sub.*, t.username AS teacher_name
     FROM subjects sub
     LEFT JOIN users t ON t.id = sub.teacher_id
     WHERE sub.id = ? AND sub.is_active = 1"
);
$subq->bind_param("i", $subject_id);
$subq->execute();
$subject = $subq->get_result()->fetch_assoc();

if (!$subject) {
    header("Location: /classroom/student/dashboard.php");
    exit;
}

// ── Grade summary ─────────────────────────────────────────────
$gq = $conn->prepare(
    "SELECT * FROM subject_grades WHERE subject_id = ? AND student_id = ?"
);
$gq->bind_param("is", $subject_id, $sid);
$gq->execute();
$grade = $gq->get_result()->fetch_assoc();

// ── Scores per component ──────────────────────────────────────
$components = ['Major Exam', 'Written Work', 'Performance Task'];
$scores = [];
foreach ($components as $comp) {
    $sq = $conn->prepare(
        "SELECT entry_name, score, total_items, date_given,
                ROUND(score / total_items * 100, 1) AS pct
         FROM score_entries
         WHERE subject_id = ? AND student_id = ? AND component = ?
         ORDER BY date_given ASC, entry_name ASC"
    );
    $sq->bind_param("iss", $subject_id, $sid, $comp);
    $sq->execute();
    $scores[$comp] = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Attendance log ────────────────────────────────────────────
$aq = $conn->prepare(
    "SELECT date, status, time_in
     FROM attendance
     WHERE subject_id = ? AND student_id = ?
     ORDER BY date DESC"
);
$aq->bind_param("is", $subject_id, $sid);
$aq->execute();
$att_rows = $aq->get_result()->fetch_all(MYSQLI_ASSOC);

$att_total   = count($att_rows);
$att_present = 0;
$att_late    = 0;
$att_absent  = 0;
foreach ($att_rows as $ar) {
    if ($ar['status'] === 'Present')     $att_present++;
    elseif ($ar['status'] === 'Late')    $att_late++;
    else                                  $att_absent++;
}

// ── Active tab ────────────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'overview';
$valid_tabs = ['overview', 'exam', 'written', 'performance', 'attendance'];
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'overview';

// ── Type config ───────────────────────────────────────────────
$type_colors = [
    'General Education'      => '#7aa3ff',
    'Professional Education' => '#34d399',
    'Major Subject'          => '#fbbf24',
];
$subject_color = $type_colors[$subject['subject_type']] ?? '#7aa3ff';

$fg   = (float)($grade['final_grade'] ?? 0);
$pass = $fg >= 75;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?php echo htmlspecialchars($subject['subject_code']); ?> — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">
  <style>body.page-student-subject_detail{--subject-color:<?php echo htmlspecialchars($subject_color, ENT_QUOTES, 'UTF-8'); ?>;}</style>

</head>
<body class="page-student-subject_detail">

<nav class="navbar">
  <a class="brand" href="/classroom/student/dashboard.php"><img src="/classroom/assets/images/TCM logo (2).png" alt="Classroom Management System" width="32" height="32"></span>Classroom Management System</a>
  <div class="nav-sep"></div>
  <a href="/classroom/student/dashboard.php" class="nav-link"><i class="ti ti-home"></i> Home</a>
  <a href="/classroom/student/subjects.php"  class="nav-link"><i class="ti ti-books"></i> My Subjects</a>
  <div class="nav-right">
    <span class="nav-role">Student</span>
    <span style="font-size:13px;color:var(--text2);"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="/classroom/logout.php" class="btn-logout"><i class="ti ti-logout"></i> Logout</a>
  </div>
</nav>

<div class="page-wrap">

  <a href="/classroom/student/dashboard.php" class="back-link">
    <i class="ti ti-arrow-left"></i> Back to Dashboard
  </a>

  <!-- ── SUBJECT HERO ── -->
  <div class="subject-hero">
    <div class="hero-accent"></div>
    <div class="hero-body">
      <div style="flex:1;">
        <div class="hero-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
        <div class="hero-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
        <div class="hero-meta">
          <span class="hero-meta-item"><i class="ti ti-school"></i> <?php echo htmlspecialchars($subject['section']); ?></span>
          <span class="hero-meta-item"><i class="ti ti-calendar"></i> <?php echo htmlspecialchars($subject['semester']); ?> Sem · <?php echo htmlspecialchars($subject['school_year']); ?></span>
          <span class="hero-meta-item"><i class="ti ti-user"></i> <?php echo htmlspecialchars($subject['teacher_name']); ?></span>
          <span style="font-size:11px;padding:2px 9px;border-radius:99px;color:var(--subject-color);background:<?php echo $subject_color; ?>18;border:1px solid <?php echo $subject_color; ?>44;">
            <?php echo htmlspecialchars($subject['subject_type']); ?>
          </span>
        </div>
      </div>
      <div class="hero-grade">
        <?php if ($fg > 0): ?>
        <div class="grade-big" style="color:<?php echo $pass ? 'var(--green)' : 'var(--red)'; ?>">
          <?php echo number_format($fg, 2); ?>
        </div>
        <div class="grade-letter" style="color:<?php echo $pass ? 'var(--green)' : 'var(--red)'; ?>">
          <?php echo $grade['letter_grade'] ?? '—'; ?>
          <span style="font-size:11px;color:var(--text3);font-weight:400;"> · <?php echo $pass ? 'PASSED' : 'FAILED'; ?></span>
        </div>
        <div class="grade-label">Final Grade</div>
        <?php else: ?>
        <div class="grade-big" style="color:var(--text3);">—</div>
        <div class="grade-label">No grade yet</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── TABS ── -->
  <div class="tabs">
    <?php
    $tabs = [
      'overview'    => ['ti ti-chart-pie',    'Overview'],
      'exam'        => ['ti ti-file-text',     'Major Exams'],
      'written'     => ['ti ti-pencil',        'Written Works'],
      'performance' => ['ti ti-star',          'Performance'],
      'attendance'  => ['ti ti-calendar-check','Attendance'],
    ];
    foreach ($tabs as $key => [$icon, $label]):
    ?>
    <a href="subject_detail.php?id=<?php echo $subject_id; ?>&tab=<?php echo $key; ?>"
       class="tab-link <?php echo $active_tab === $key ? 'active' : ''; ?>">
      <i class="<?php echo $icon; ?>"></i> <?php echo $label; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════════════════════════════════════
       TAB: OVERVIEW
  ════════════════════════════════════════════ -->
  <?php if ($active_tab === 'overview'): ?>

  <?php if ($fg === 0.0): ?>
  <div class="notice">
    <i class="ti ti-info-circle" style="color:var(--accent);font-size:15px;"></i>
    No scores have been recorded yet. Your grade will appear here once your teacher enters scores.
  </div>
  <?php endif; ?>

  <!-- Component averages -->
  <div class="stat-grid">
    <div class="stat-box" style="border-top:2px solid #7aa3ff;">
      <div class="stat-val" style="color:#7aa3ff;">
        <?php echo ($grade && $grade['exam_avg'] > 0) ? number_format($grade['exam_avg'],1).'%' : '—'; ?>
      </div>
      <div class="stat-lbl">Major Exam Avg</div>
    </div>
    <div class="stat-box" style="border-top:2px solid #34d399;">
      <div class="stat-val" style="color:#34d399;">
        <?php echo ($grade && $grade['written_avg'] > 0) ? number_format($grade['written_avg'],1).'%' : '—'; ?>
      </div>
      <div class="stat-lbl">Written Work Avg</div>
    </div>
    <div class="stat-box" style="border-top:2px solid #fbbf24;">
      <div class="stat-val" style="color:#fbbf24;">
        <?php echo ($grade && $grade['performance_avg'] > 0) ? number_format($grade['performance_avg'],1).'%' : '—'; ?>
      </div>
      <div class="stat-lbl">Performance Avg</div>
    </div>
    <div class="stat-box" style="border-top:2px solid #a78bfa;">
      <div class="stat-val" style="color:#a78bfa;">
        <?php echo ($grade && $grade['attendance_rate'] > 0) ? number_format($grade['attendance_rate'],1).'%' : '—'; ?>
      </div>
      <div class="stat-lbl">Attendance Rate</div>
    </div>
  </div>

  <!-- Grade weights breakdown -->
  <div class="card">
    <p class="card-title"><i class="ti ti-percentage" style="color:var(--subject-color);"></i> Grade Composition</p>
    <?php
    $breakdown = [
      ['Major Exams',    '#7aa3ff', (float)$subject['exam_pct'],        (float)($grade['exam_avg'] ?? 0)],
      ['Written Works',  '#34d399', (float)$subject['written_pct'],     (float)($grade['written_avg'] ?? 0)],
      ['Performance',    '#fbbf24', (float)$subject['performance_pct'], (float)($grade['performance_component'] ?? 0)],
    ];
    foreach ($breakdown as [$label, $color, $weight, $avg]):
      $contribution = $avg * $weight / 100;
    ?>
    <div class="weight-row">
      <div class="weight-label">
        <span class="weight-dot" style="background:<?php echo $color; ?>;"></span>
        <?php echo $label; ?>
        <span style="font-size:10px;color:var(--text3);margin-left:auto;"><?php echo (int)$weight; ?>%</span>
      </div>
      <div class="weight-bar-track">
        <div class="weight-bar-fill" style="width:<?php echo min($avg,100); ?>%;background:<?php echo $color; ?>;opacity:.75;"></div>
      </div>
      <div class="weight-val" style="color:<?php echo $avg>=75?'var(--green)':($avg>0?'var(--red)':'var(--text3)'); ?>;">
        <?php echo $avg > 0 ? number_format($avg,1).'%' : '—'; ?>
      </div>
      <div style="font-family:var(--font-mono);font-size:11px;color:var(--text3);min-width:70px;text-align:right;">
        <?php echo $avg > 0 ? '+'.number_format($contribution,2).' pts' : ''; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:flex-end;gap:12px;">
      <span style="font-size:12px;color:var(--text2);">Final Grade</span>
      <span style="font-family:var(--font-head);font-size:20px;font-weight:700;color:<?php echo $pass?'var(--green)':($fg>0?'var(--red)':'var(--text3)'); ?>;">
        <?php echo $fg > 0 ? number_format($fg,2).'%' : '—'; ?>
      </span>
    </div>
  </div>

  <!-- Attendance quick stat on overview -->
  <?php if ($att_total > 0): ?>
  <div class="card">
    <p class="card-title"><i class="ti ti-calendar-check" style="color:var(--purple);"></i> Attendance Summary</p>
    <div class="att-summary">
      <div class="att-chip" style="border-top:2px solid var(--green);">
        <div class="att-chip-val" style="color:var(--green);"><?php echo $att_present; ?></div>
        <div class="att-chip-lbl">Present</div>
      </div>
      <div class="att-chip" style="border-top:2px solid var(--yellow);">
        <div class="att-chip-val" style="color:var(--yellow);"><?php echo $att_late; ?></div>
        <div class="att-chip-lbl">Late</div>
      </div>
      <div class="att-chip" style="border-top:2px solid var(--red);">
        <div class="att-chip-val" style="color:var(--red);"><?php echo $att_absent; ?></div>
        <div class="att-chip-lbl">Absent</div>
      </div>
      <div class="att-chip" style="border-top:2px solid var(--border2);">
        <div class="att-chip-val"><?php echo $att_total; ?></div>
        <div class="att-chip-lbl">Total Days</div>
      </div>
    </div>
    <?php if ($att_total > 0):
      $rate = ($att_present + $att_late * 0.5) / $att_total * 100;
    ?>
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="flex:1;height:8px;background:var(--bg3);border-radius:99px;overflow:hidden;">
        <div style="height:100%;width:<?php echo min($rate,100); ?>%;background:var(--purple);border-radius:99px;"></div>
      </div>
      <span style="font-family:var(--font-mono);font-size:13px;color:var(--purple);"><?php echo number_format($rate,1); ?>%</span>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════
       TAB: SCORES (Exam / Written / Performance)
  ════════════════════════════════════════════ -->
  <?php else:
    $comp_map = [
      'exam'        => ['Major Exam',       '#7aa3ff', 'ti-file-text'],
      'written'     => ['Written Work',     '#34d399', 'ti-pencil'],
      'performance' => ['Performance Task', '#fbbf24', 'ti-star'],
    ];

    if (isset($comp_map[$active_tab])):
      [$comp_name, $comp_color, $comp_icon] = $comp_map[$active_tab];
      $comp_scores = $scores[$comp_name];
      $avg = count($comp_scores) > 0
        ? array_sum(array_column($comp_scores, 'pct')) / count($comp_scores)
        : 0;
  ?>

  <div class="card">
    <p class="card-title">
      <i class="ti <?php echo $comp_icon; ?>" style="color:<?php echo $comp_color; ?>;"></i>
      <?php echo $comp_name; ?> Scores
      <?php if ($avg > 0): ?>
      <span style="margin-left:auto;font-size:13px;font-weight:600;font-family:var(--font-head);color:<?php echo $avg>=75?'var(--green)':'var(--red)'; ?>;">
        Avg: <?php echo number_format($avg,1); ?>%
      </span>
      <?php endif; ?>
    </p>

    <?php if (empty($comp_scores)): ?>
    <div class="empty-state">
      <i class="ti <?php echo $comp_icon; ?>"></i>
      <p>No <?php echo strtolower($comp_name); ?> scores recorded yet.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Date</th>
            <th>Score</th>
            <th>Total</th>
            <th>Percentage</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($comp_scores as $i => $sc):
            $pct  = (float)$sc['pct'];
            $pass = $pct >= 75;
          ?>
          <tr>
            <td class="td-mono"><?php echo $i + 1; ?></td>
            <td style="font-weight:500;"><?php echo htmlspecialchars($sc['entry_name']); ?></td>
            <td class="td-mono"><?php echo $sc['date_given'] ? date('M d, Y', strtotime($sc['date_given'])) : '—'; ?></td>
            <td style="font-family:var(--font-mono);font-size:13px;">
              <?php echo number_format((float)$sc['score'], 1); ?>
            </td>
            <td class="td-mono"><?php echo (int)$sc['total_items']; ?></td>
            <td>
              <div class="inline-bar">
                <div class="inline-track">
                  <div class="inline-fill" style="width:<?php echo min($pct,100); ?>%;background:<?php echo $comp_color; ?>;opacity:.8;"></div>
                </div>
                <span style="font-family:var(--font-mono);font-size:12px;min-width:46px;color:<?php echo $pass?'var(--green)':'var(--red)'; ?>;">
                  <?php echo number_format($pct,1); ?>%
                </span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <!-- Summary bar -->
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:12px;">
      <span style="font-size:12px;color:var(--text2);"><?php echo count($comp_scores); ?> entr<?php echo count($comp_scores)===1?'y':'ies'; ?> · Average</span>
      <div style="flex:1;height:6px;background:var(--bg3);border-radius:99px;overflow:hidden;">
        <div style="height:100%;width:<?php echo min($avg,100); ?>%;background:<?php echo $comp_color; ?>;border-radius:99px;"></div>
      </div>
      <span style="font-family:var(--font-mono);font-size:14px;font-weight:500;color:<?php echo $avg>=75?'var(--green)':($avg>0?'var(--red)':'var(--text3)'); ?>;">
        <?php echo $avg > 0 ? number_format($avg,1).'%' : '—'; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════════════════
       TAB: ATTENDANCE
  ════════════════════════════════════════════ -->
  <?php elseif ($active_tab === 'attendance'): ?>

  <div class="card">
    <p class="card-title"><i class="ti ti-calendar-check" style="color:var(--purple);"></i> Attendance Log</p>

    <?php if ($att_total === 0): ?>
    <div class="empty-state">
      <i class="ti ti-calendar-off"></i>
      <p>No attendance records yet.</p>
    </div>
    <?php else: ?>

    <!-- Summary chips -->
    <div class="att-summary">
      <div class="att-chip" style="border-top:2px solid var(--green);">
        <div class="att-chip-val" style="color:var(--green);"><?php echo $att_present; ?></div>
        <div class="att-chip-lbl">Present</div>
      </div>
      <div class="att-chip" style="border-top:2px solid var(--yellow);">
        <div class="att-chip-val" style="color:var(--yellow);"><?php echo $att_late; ?></div>
        <div class="att-chip-lbl">Late</div>
      </div>
      <div class="att-chip" style="border-top:2px solid var(--red);">
        <div class="att-chip-val" style="color:var(--red);"><?php echo $att_absent; ?></div>
        <div class="att-chip-lbl">Absent</div>
      </div>
      <div class="att-chip" style="border-top:2px solid var(--border2);">
        <div class="att-chip-val"><?php echo $att_total; ?></div>
        <div class="att-chip-lbl">Total Days</div>
      </div>
    </div>

    <!-- Rate bar -->
    <?php
      $rate = ($att_present + $att_late * 0.5) / $att_total * 100;
    ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
      <span style="font-size:12px;color:var(--text2);white-space:nowrap;">Attendance Rate</span>
      <div style="flex:1;height:8px;background:var(--bg3);border-radius:99px;overflow:hidden;">
        <div style="height:100%;width:<?php echo min($rate,100); ?>%;background:var(--purple);border-radius:99px;"></div>
      </div>
      <span style="font-family:var(--font-mono);font-size:13px;color:var(--purple);white-space:nowrap;">
        <?php echo number_format($rate, 1); ?>%
      </span>
    </div>

    <!-- Per-day log -->
    <div class="att-list">
      <?php foreach ($att_rows as $ar):
        $status_class = match($ar['status']) {
          'Present' => 'status-present',
          'Late'    => 'status-late',
          default   => 'status-absent',
        };
      ?>
      <div class="att-row">
        <span class="att-date"><?php echo date('D, M d Y', strtotime($ar['date'])); ?></span>
        <span class="att-time">
          <?php echo $ar['time_in'] ? 'Time in: '.date('g:i A', strtotime($ar['time_in'])) : ''; ?>
        </span>
        <span class="status-badge <?php echo $status_class; ?>"><?php echo $ar['status']; ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>
  </div>

  <?php endif; /* comp_map / attendance */ ?>
  <?php endif; /* overview vs other tabs */ ?>

</div><!-- end page-wrap -->
</body>
</html>
