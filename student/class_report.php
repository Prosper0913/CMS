<?php
// ============================================================
//  student/class_report.php
//  Transparency page — every student can see class-wide stats.
//  The logged-in student's own row is highlighted.
// ============================================================
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];

// Class summary
$summary = $conn->query(
    "SELECT ROUND(AVG(final_grade),1) AS avg,
            ROUND(MAX(final_grade),1) AS highest,
            ROUND(MIN(CASE WHEN final_grade>0 THEN final_grade END),1) AS lowest,
            SUM(final_grade>=75) AS passing,
            SUM(final_grade>0 AND final_grade<75) AS failing,
            COUNT(*) AS total
     FROM grades"
)->fetch_assoc();

// All students ranked by final grade (for the ranking table)
$ranked = $conn->query(
    "SELECT s.student_id, s.last_name, s.first_name,
            COALESCE(g.final_grade,0) AS final_grade,
            COALESCE(g.letter_grade,'N/A') AS letter_grade,
            COALESCE(g.quiz_avg,0) AS quiz_avg,
            COALESCE(g.activity_avg,0) AS activity_avg,
            COALESCE(g.attendance_rate,0) AS attendance_rate
     FROM students s LEFT JOIN grades g USING(student_id)
     WHERE g.final_grade > 0
     ORDER BY g.final_grade DESC"
);

// Grade distribution for chart
$dist_raw = $conn->query(
    "SELECT letter_grade, COUNT(*) AS cnt FROM grades WHERE final_grade>0 GROUP BY letter_grade ORDER BY letter_grade"
);
$dist_labels = []; $dist_counts = [];
while ($r = $dist_raw->fetch_assoc()) {
    $dist_labels[] = $r['letter_grade'];
    $dist_counts[] = (int)$r['cnt'];
}

// Top quiz scorers (anonymous by default — shows name since this is transparency)
$top_quiz = $conn->query(
    "SELECT s.last_name, s.first_name, ROUND(AVG(q.score/q.total_items*100),1) AS avg
     FROM quizzes q JOIN students s USING(student_id)
     GROUP BY q.student_id ORDER BY avg DESC LIMIT 5"
);

// Most absences (shows count only, no names — privacy)
$absences = $conn->query(
    "SELECT s.student_id,
            COUNT(*) AS absence_count
     FROM attendance a JOIN students s USING(student_id)
     WHERE a.status='Absent'
     GROUP BY a.student_id ORDER BY absence_count DESC LIMIT 10"
);

// This student's rank
$my_grade = $conn->prepare("SELECT final_grade FROM grades WHERE student_id=?");
$my_grade->bind_param("s", $sid);
$my_grade->execute();
$my_fg = (float)($my_grade->get_result()->fetch_assoc()['final_grade'] ?? 0);

$my_rank = $conn->query(
    "SELECT COUNT(*)+1 AS rank FROM grades WHERE final_grade > $my_fg"
)->fetch_assoc()['rank'];

$page_title = "Class Report";
$active_nav = "report";
include '../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-report-analytics" style="color:var(--accent)"></i> Class Report</h1>
    <p>Class-wide performance overview — for transparency and awareness.</p>
  </div>

  <!-- Your standing callout -->
  <?php if ($my_fg > 0): ?>
  <div style="background:rgba(79,124,255,.1);border:1px solid rgba(79,124,255,.3);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;gap:14px;">
    <i class="ti ti-user-circle" style="font-size:28px;color:var(--accent);flex-shrink:0;"></i>
    <div>
      <div style="font-size:13px;font-weight:600;color:var(--text);">Your Standing</div>
      <div style="font-size:12px;color:var(--text2);margin-top:2px;">
        You are currently ranked <strong style="color:var(--accent);">#<?php echo $my_rank; ?></strong>
        out of <?php echo $summary['total']; ?> students
        with a final grade of <strong style="color:var(--accent);"><?php echo number_format($my_fg,2); ?>%</strong>.
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Class summary cards -->
  <div class="stats-row">
    <div class="stat-card stat-accent"><div class="stat-label">Class Average</div><div class="stat-value"><?php echo $summary['avg']??0; ?>%</div></div>
    <div class="stat-card stat-green"><div class="stat-label">Highest Grade</div><div class="stat-value"><?php echo $summary['highest']??0; ?>%</div></div>
    <div class="stat-card stat-red"><div class="stat-label">Lowest Grade</div><div class="stat-value"><?php echo $summary['lowest']??0; ?>%</div></div>
    <div class="stat-card stat-green"><div class="stat-label">Passing</div><div class="stat-value"><?php echo $summary['passing']??0; ?></div></div>
    <div class="stat-card stat-red"><div class="stat-label">Failing</div><div class="stat-value"><?php echo $summary['failing']??0; ?></div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">

    <!-- Grade distribution chart -->
    <div class="card">
      <p class="card-title"><i class="ti ti-chart-bar"></i> Grade Distribution</p>
      <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">How many students got each letter grade.</p>
      <?php if (empty($dist_labels)): ?>
        <div class="empty-state"><i class="ti ti-chart-off"></i><p>No data yet.</p></div>
      <?php else: ?>
        <canvas id="distChart" height="200"></canvas>
      <?php endif; ?>
    </div>

    <!-- Top 5 quiz scorers -->
    <div class="card">
      <p class="card-title"><i class="ti ti-trophy"></i> Top 5 — Quiz Average</p>
      <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px;">
        <?php if ($top_quiz->num_rows===0): ?>
          <div class="empty-state"><i class="ti ti-pencil-off"></i><p>No quiz data yet.</p></div>
        <?php endif; ?>
        <?php $rank=1; while($r=$top_quiz->fetch_assoc()):
          $is_me = false; // name-based match not reliable; highlight via student_id if needed
        ?>
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="width:22px;height:22px;border-radius:50%;background:var(--bg3);color:var(--text2);font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;"><?php echo $rank++; ?></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:500;margin-bottom:3px;"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
            <div class="score-bar-track"><div class="score-bar-fill" style="width:<?php echo min($r['avg'],100); ?>%;background:var(--green)"></div></div>
          </div>
          <span style="font-size:13px;font-weight:600;color:var(--green);min-width:44px;text-align:right;"><?php echo $r['avg']; ?>%</span>
        </div>
        <?php endwhile; ?>
      </div>
    </div>

  </div>

  <!-- Full class ranking table -->
  <div class="card" style="margin-bottom:24px;">
    <p class="card-title"><i class="ti ti-list-numbers"></i> Class Ranking</p>
    <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">
      Your row is highlighted. Only students with recorded grades are shown.
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Student</th><th>Quiz Avg</th><th>Activity Avg</th><th>Attendance</th><th>Final Grade</th><th>Letter</th></tr>
        </thead>
        <tbody>
          <?php if ($ranked->num_rows === 0): ?>
          <tr><td colspan="7"><div class="empty-state"><i class="ti ti-chart-off"></i><p>No grade data yet.</p></div></td></tr>
          <?php endif; ?>
          <?php $rank=1; while($r=$ranked->fetch_assoc()):
            $is_me = $r['student_id'] === $sid;
            $row_bg = $is_me ? 'background:rgba(79,124,255,.1);border-left:3px solid var(--accent);' : '';
            $b = $r['final_grade']>=75?'badge-green':'badge-red';
          ?>
          <tr style="<?php echo $row_bg; ?>">
            <td style="color:var(--text2);"><?php echo $rank++; ?></td>
            <td>
              <span style="font-weight:<?php echo $is_me?'600':'400'; ?>;color:<?php echo $is_me?'var(--accent)':'var(--text)'; ?>;">
                <?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?>
              </span>
              <?php if ($is_me): ?>
                <span class="badge badge-blue" style="margin-left:6px;font-size:10px;">You</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;"><?php echo number_format($r['quiz_avg'],1); ?>%</td>
            <td style="font-size:12px;"><?php echo number_format($r['activity_avg'],1); ?>%</td>
            <td style="font-size:12px;"><?php echo number_format($r['attendance_rate'],1); ?>%</td>
            <td style="font-weight:600;color:<?php echo $r['final_grade']>=75?'var(--green)':'var(--red)'; ?>">
              <?php echo number_format($r['final_grade'],2); ?>%
            </td>
            <td><span class="badge <?php echo $b; ?>"><?php echo $r['letter_grade']; ?></span></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Absence count ranking (no names for privacy) -->
  <div class="card">
    <p class="card-title"><i class="ti ti-calendar-x"></i> Absence Count Ranking</p>
    <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">Names hidden for privacy. Counts only.</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <?php if ($absences->num_rows === 0): ?>
        <p style="font-size:13px;color:var(--text2);"><i class="ti ti-circle-check" style="color:var(--green)"></i> No absences recorded yet.</p>
      <?php endif; ?>
      <?php $rank=1; while($r=$absences->fetch_assoc()): ?>
      <div style="padding:8px 14px;background:var(--bg3);border-radius:var(--radius);border:1px solid var(--border);font-size:12px;color:var(--text2);">
        #<?php echo $rank++; ?> — <span class="badge badge-red"><?php echo $r['absence_count']; ?> absences</span>
      </div>
      <?php endwhile; ?>
    </div>
  </div>

</div>

<script>
Chart.defaults.color = '#8b95af';
Chart.defaults.borderColor = '#2a3045';
Chart.defaults.font.family = 'DM Sans';
<?php if (!empty($dist_labels)): ?>
new Chart(document.getElementById('distChart'), {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($dist_labels); ?>,
    datasets: [{
      label: 'Students',
      data: <?php echo json_encode($dist_counts); ?>,
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
</script>

<?php include '../includes/footer.php'; ?>