<?php
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];

$stmt = $conn->prepare(
    "SELECT quiz_num, score, total_items, date_given,
            ROUND(score/total_items*100, 1) AS pct
     FROM quizzes WHERE student_id = ? ORDER BY quiz_num ASC"
);
$stmt->bind_param("s", $sid);
$stmt->execute();
$quizzes = $stmt->get_result();

$avg_stmt = $conn->prepare("SELECT ROUND(AVG(score/total_items*100),1) AS avg FROM quizzes WHERE student_id=?");
$avg_stmt->bind_param("s", $sid);
$avg_stmt->execute();
$quiz_avg = $avg_stmt->get_result()->fetch_assoc()['avg'] ?? 0;

$page_title = "My Quizzes";
$active_nav = "quizzes";
include '../includes/header.php';
?>
<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-pencil" style="color:var(--accent)"></i> My Quiz Scores</h1>
    <p>Your quiz score history. Scores are recorded by your teacher.</p>
  </div>

  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Quiz Average</div>
      <div class="stat-value"><?php echo $quiz_avg; ?>%</div>
      <div class="stat-sub">30% of your final grade</div>
    </div>
    <div class="stat-card <?php echo $quiz_avg>=75?'stat-green':'stat-red'; ?>">
      <div class="stat-label">Status</div>
      <div class="stat-value" style="font-size:16px;"><?php echo $quiz_avg>=75?'Passing':'Below passing'; ?></div>
    </div>
  </div>

  <div class="card">
    <p class="card-title"><i class="ti ti-list"></i> All Quiz Records</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Quiz #</th><th>Score</th><th>Percentage</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php if ($quizzes->num_rows === 0): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="ti ti-pencil-off"></i><p>No quiz scores yet.</p></div></td></tr>
          <?php endif; ?>
          <?php while ($r = $quizzes->fetch_assoc()):
            $b = $r['pct']>=75?'badge-green':($r['pct']>=60?'badge-yellow':'badge-red');
          ?>
          <tr>
            <td><span class="badge badge-blue">Quiz <?php echo $r['quiz_num']; ?></span></td>
            <td class="td-mono"><?php echo $r['score'].'/'.$r['total_items']; ?></td>
            <td>
              <div class="score-bar-wrap">
                <div class="score-bar-track">
                  <div class="score-bar-fill" style="width:<?php echo min($r['pct'],100); ?>%;background:<?php echo $r['pct']>=75?'var(--green)':($r['pct']>=60?'var(--yellow)':'var(--red)'); ?>"></div>
                </div>
                <span class="badge <?php echo $b; ?>" style="min-width:50px;justify-content:center;"><?php echo $r['pct']; ?>%</span>
              </div>
            </td>
            <td style="color:var(--text2);font-size:12px;"><?php echo date('M d, Y', strtotime($r['date_given'])); ?></td>
          </tr>
          <?php endwhile; ?>
          <?php if ($quizzes->num_rows > 0): ?>
          <tr style="background:var(--bg3);">
            <td colspan="2" style="text-align:right;font-weight:600;color:var(--text2);font-size:12px;">AVERAGE</td>
            <td colspan="2">
              <span class="badge <?php echo $quiz_avg>=75?'badge-green':($quiz_avg>=60?'badge-yellow':'badge-red'); ?>" style="font-size:13px;padding:4px 12px;">
                <?php echo $quiz_avg; ?>%
              </span>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>