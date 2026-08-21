<?php
// ============================================================
//  teacher/reports.php
//  Class-wide analytics. Charts powered by Chart.js (CDN).
//  No data is saved here — it only reads and displays.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

// ── Top scorer per quiz number ─────────────────────────────
$top_per_quiz = $conn->query(
    "SELECT q.quiz_num,
            s.last_name, s.first_name,
            MAX(q.score/q.total_items*100) AS top_pct,
            q.score, q.total_items
     FROM quizzes q JOIN students s USING(student_id)
     GROUP BY q.quiz_num
     ORDER BY q.quiz_num ASC"
);

// ── Top 5 overall quiz average ─────────────────────────────
$top_quiz_avg = $conn->query(
    "SELECT s.last_name, s.first_name,
            ROUND(AVG(q.score/q.total_items*100),1) AS avg_pct
     FROM quizzes q JOIN students s USING(student_id)
     GROUP BY q.student_id
     ORDER BY avg_pct DESC LIMIT 5"
);

// ── Most absences ──────────────────────────────────────────
$most_absences = $conn->query(
    "SELECT s.last_name, s.first_name, COUNT(*) AS absence_count
     FROM attendance a JOIN students s USING(student_id)
     WHERE a.status = 'Absent'
     GROUP BY a.student_id
     ORDER BY absence_count DESC LIMIT 10"
);

// ── Grade distribution (count per letter grade) ────────────
$grade_dist_raw = $conn->query(
    "SELECT letter_grade, COUNT(*) AS cnt
     FROM grades WHERE final_grade > 0
     GROUP BY letter_grade
     ORDER BY letter_grade ASC"
);
$grade_labels = []; $grade_counts = [];
while ($r = $grade_dist_raw->fetch_assoc()) {
    $grade_labels[] = $r['letter_grade'];
    $grade_counts[] = (int)$r['cnt'];
}

// ── Quiz average per quiz number (class average) ───────────
$quiz_avg_raw = $conn->query(
    "SELECT quiz_num,
            ROUND(AVG(score/total_items*100),1) AS avg_pct,
            COUNT(*) AS entries
     FROM quizzes GROUP BY quiz_num ORDER BY quiz_num ASC"
);
$quiz_labels = []; $quiz_avgs = [];
while ($r = $quiz_avg_raw->fetch_assoc()) {
    $quiz_labels[] = 'Quiz ' . $r['quiz_num'];
    $quiz_avgs[]   = (float)$r['avg_pct'];
}

// ── Activity average per type ──────────────────────────────
$act_avg_raw = $conn->query(
    "SELECT activity_type,
            ROUND(AVG(score/total_items*100),1) AS avg_pct
     FROM activities GROUP BY activity_type ORDER BY activity_type"
);
$act_labels = []; $act_avgs = [];
while ($r = $act_avg_raw->fetch_assoc()) {
    $act_labels[] = $r['activity_type'];
    $act_avgs[]   = (float)$r['avg_pct'];
}

// ── Class summary ──────────────────────────────────────────
$summary = $conn->query(
    "SELECT COUNT(*) AS total,
            ROUND(AVG(final_grade),1)  AS avg,
            ROUND(MAX(final_grade),1)  AS highest,
            ROUND(MIN(CASE WHEN final_grade>0 THEN final_grade END),1) AS lowest,
            SUM(final_grade >= 75) AS passing,
            SUM(final_grade > 0 AND final_grade < 75) AS failing
     FROM grades"
)->fetch_assoc();

$page_title = "Reports";
$active_nav = "reports";
include '../includes/header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-report-analytics" style="color:var(--accent)"></i> Class Reports</h1>
    <p>Visual overview of class performance. Students can also view the transparency version on their portal.</p>
  </div>

  <!-- Class summary cards -->
  <div class="stats-row">
    <div class="stat-card stat-accent"><div class="stat-label">Class Average</div><div class="stat-value"><?php echo $summary['avg'] ?? 0; ?>%</div></div>
    <div class="stat-card stat-green"><div class="stat-label">Highest Grade</div><div class="stat-value"><?php echo $summary['highest'] ?? 0; ?>%</div></div>
    <div class="stat-card stat-red"><div class="stat-label">Lowest Grade</div><div class="stat-value"><?php echo $summary['lowest'] ?? 0; ?>%</div></div>
    <div class="stat-card stat-green"><div class="stat-label">Passing</div><div class="stat-value"><?php echo $summary['passing'] ?? 0; ?></div></div>
    <div class="stat-card stat-red"><div class="stat-label">Failing</div><div class="stat-value"><?php echo $summary['failing'] ?? 0; ?></div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">

    <!-- Grade distribution chart -->
    <div class="card">
      <p class="card-title"><i class="ti ti-chart-bar"></i> Grade Distribution</p>
      <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">How many students got each letter grade.</p>
      <?php if (empty($grade_labels)): ?>
        <div class="empty-state"><i class="ti ti-chart-off"></i><p>No grade data yet.</p></div>
      <?php else: ?>
        <canvas id="gradeDistChart" height="200"></canvas>
      <?php endif; ?>
    </div>

    <!-- Quiz average trend chart -->
    <div class="card">
      <p class="card-title"><i class="ti ti-trending-up"></i> Quiz Average per Quiz</p>
      <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">Class average score trend across all quizzes.</p>
      <?php if (empty($quiz_labels)): ?>
        <div class="empty-state"><i class="ti ti-pencil-off"></i><p>No quiz data yet.</p></div>
      <?php else: ?>
        <canvas id="quizTrendChart" height="200"></canvas>
      <?php endif; ?>
    </div>

    <!-- Activity average per type -->
    <div class="card">
      <p class="card-title"><i class="ti ti-clipboard-list"></i> Activity Average by Type</p>
      <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">Class average score per activity type.</p>
      <?php if (empty($act_labels)): ?>
        <div class="empty-state"><i class="ti ti-clipboard-off"></i><p>No activity data yet.</p></div>
      <?php else: ?>
        <canvas id="actTypeChart" height="200"></canvas>
      <?php endif; ?>
    </div>

    <!-- Top 5 quiz average -->
    <div class="card">
      <p class="card-title"><i class="ti ti-trophy"></i> Top 5 — Quiz Average</p>
      <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px;">
        <?php if ($top_quiz_avg->num_rows === 0): ?>
          <div class="empty-state"><i class="ti ti-pencil-off"></i><p>No quiz data yet.</p></div>
        <?php endif; ?>
        <?php $rank=1; while ($r = $top_quiz_avg->fetch_assoc()): ?>
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="width:22px;height:22px;border-radius:50%;background:var(--bg3);color:var(--text2);font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo $rank++; ?></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:500;margin-bottom:3px;"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
            <div class="score-bar-track">
              <div class="score-bar-fill" style="width:<?php echo min($r['avg_pct'],100); ?>%;background:var(--green)"></div>
            </div>
          </div>
          <span style="font-size:13px;font-weight:600;color:var(--green);min-width:44px;text-align:right;"><?php echo $r['avg_pct']; ?>%</span>
        </div>
        <?php endwhile; ?>
      </div>
    </div>

  </div>

  <!-- Top scorer per quiz -->
  <div class="card" style="margin-bottom:24px;">
    <p class="card-title"><i class="ti ti-star"></i> Top Scorer Per Quiz</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Quiz #</th>
            <th>Top Scorer</th>
            <th>Score</th>
            <th>Percentage</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($top_per_quiz->num_rows === 0): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="ti ti-pencil-off"></i><p>No quiz data yet.</p></div></td></tr>
          <?php endif; ?>
          <?php while ($r = $top_per_quiz->fetch_assoc()): ?>
          <tr>
            <td><span class="badge badge-blue">Quiz <?php echo $r['quiz_num']; ?></span></td>
            <td style="font-weight:500;"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></td>
            <td class="td-mono"><?php echo $r['score'].'/'.$r['total_items']; ?></td>
            <td>
              <div class="score-bar-wrap">
                <div class="score-bar-track">
                  <div class="score-bar-fill" style="width:<?php echo min($r['top_pct'],100); ?>%;background:var(--green)"></div>
                </div>
                <span class="badge badge-green"><?php echo number_format($r['top_pct'],1); ?>%</span>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Most absences -->
  <div class="card">
    <p class="card-title"><i class="ti ti-calendar-x"></i> Most Absences</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Rank</th><th>Student</th><th>Absence Count</th><th>Severity</th></tr>
        </thead>
        <tbody>
          <?php if ($most_absences->num_rows === 0): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="ti ti-calendar-check"></i><p>No absences recorded yet.</p></div></td></tr>
          <?php endif; ?>
          <?php $rank=1; while ($r = $most_absences->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--text2);"><?php echo $rank++; ?></td>
            <td style="font-weight:500;"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></td>
            <td><span class="badge badge-red"><?php echo $r['absence_count']; ?> absences</span></td>
            <td>
              <?php if ($r['absence_count'] >= 7): ?>
                <span class="badge badge-red"><i class="ti ti-alert-triangle"></i> Critical</span>
              <?php elseif ($r['absence_count'] >= 4): ?>
                <span class="badge badge-yellow"><i class="ti ti-alert-circle"></i> Warning</span>
              <?php else: ?>
                <span class="badge badge-blue">Monitor</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
// Shared chart defaults
Chart.defaults.color = '#8b95af';
Chart.defaults.borderColor = '#2a3045';
Chart.defaults.font.family = 'DM Sans';

// Grade distribution bar chart
<?php if (!empty($grade_labels)): ?>
new Chart(document.getElementById('gradeDistChart'), {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($grade_labels); ?>,
    datasets: [{
      label: 'Students',
      data: <?php echo json_encode($grade_counts); ?>,
      backgroundColor: '#4f7cff88',
      borderColor: '#4f7cff',
      borderWidth: 1,
      borderRadius: 5,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#2a3045' } },
      x: { grid: { display: false } }
    }
  }
});
<?php endif; ?>

// Quiz average trend line chart
<?php if (!empty($quiz_labels)): ?>
new Chart(document.getElementById('quizTrendChart'), {
  type: 'line',
  data: {
    labels: <?php echo json_encode($quiz_labels); ?>,
    datasets: [{
      label: 'Class Average (%)',
      data: <?php echo json_encode($quiz_avgs); ?>,
      borderColor: '#22c55e',
      backgroundColor: '#22c55e22',
      borderWidth: 2,
      pointRadius: 5,
      pointBackgroundColor: '#22c55e',
      fill: true,
      tension: 0.3,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: false, min: 0, max: 100, grid: { color: '#2a3045' } },
      x: { grid: { display: false } }
    }
  }
});
<?php endif; ?>

// Activity type bar chart
<?php if (!empty($act_labels)): ?>
new Chart(document.getElementById('actTypeChart'), {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($act_labels); ?>,
    datasets: [{
      label: 'Average (%)',
      data: <?php echo json_encode($act_avgs); ?>,
      backgroundColor: '#f59e0b88',
      borderColor: '#f59e0b',
      borderWidth: 1,
      borderRadius: 5,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: false, min: 0, max: 100, grid: { color: '#2a3045' } },
      x: { grid: { display: false } }
    }
  }
});
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
