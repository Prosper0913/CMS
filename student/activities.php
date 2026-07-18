<?php
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];

$stmt = $conn->prepare(
    "SELECT activity_name, activity_type, score, total_items, date_given,
            ROUND(score/total_items*100,1) AS pct
     FROM activities WHERE student_id=? ORDER BY date_given DESC"
);
$stmt->bind_param("s", $sid);
$stmt->execute();
$activities = $stmt->get_result();

$avg_stmt = $conn->prepare("SELECT ROUND(AVG(score/total_items*100),1) AS avg FROM activities WHERE student_id=?");
$avg_stmt->bind_param("s", $sid);
$avg_stmt->execute();
$act_avg = $avg_stmt->get_result()->fetch_assoc()['avg'] ?? 0;

$page_title = "My Activities";
$active_nav = "activities";
include '../includes/header.php';

$type_colors = [
    'Lab Exercise'=>'badge-blue','Seatwork'=>'badge-yellow',
    'Assignment'=>'badge-green','Project'=>'badge-blue',
    'Performance Task'=>'badge-yellow'
];
?>
<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-clipboard-list" style="color:var(--accent)"></i> My Activity Scores</h1>
    <p>All your lab exercises, seatwork, assignments, and other activities.</p>
  </div>

  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Activity Average</div>
      <div class="stat-value"><?php echo $act_avg; ?>%</div>
      <div class="stat-sub">40% of your final grade</div>
    </div>
    <div class="stat-card <?php echo $act_avg>=75?'stat-green':'stat-red'; ?>">
      <div class="stat-label">Status</div>
      <div class="stat-value" style="font-size:16px;"><?php echo $act_avg>=75?'Passing':'Below passing'; ?></div>
    </div>
  </div>

  <div class="card">
    <p class="card-title"><i class="ti ti-list"></i> All Activity Records</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Activity</th><th>Type</th><th>Score</th><th>Percentage</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php if ($activities->num_rows === 0): ?>
          <tr><td colspan="5"><div class="empty-state"><i class="ti ti-clipboard-off"></i><p>No activity scores yet.</p></div></td></tr>
          <?php endif; ?>
          <?php while ($r = $activities->fetch_assoc()):
            $b = $r['pct']>=75?'badge-green':($r['pct']>=60?'badge-yellow':'badge-red');
            $tc = $type_colors[$r['activity_type']] ?? 'badge-blue';
          ?>
          <tr>
            <td style="font-weight:500;font-size:13px;"><?php echo htmlspecialchars($r['activity_name']); ?></td>
            <td><span class="badge <?php echo $tc; ?>"><?php echo htmlspecialchars($r['activity_type']); ?></span></td>
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
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>