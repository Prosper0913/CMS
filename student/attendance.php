<?php
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];

$stmt = $conn->prepare(
    "SELECT date, time_in, status, remarks FROM attendance
     WHERE student_id=? ORDER BY date DESC"
);
$stmt->bind_param("s", $sid);
$stmt->execute();
$records = $stmt->get_result();

$sum = $conn->prepare(
    "SELECT COUNT(*) AS total,
            SUM(status='Present') AS present,
            SUM(status='Absent')  AS absent,
            SUM(status='Late')    AS late,
            ROUND(SUM(CASE WHEN status='Present' THEN 1 WHEN status='Late' THEN 0.5 ELSE 0 END)/COUNT(*)*100,1) AS rate
     FROM attendance WHERE student_id=?"
);
$sum->bind_param("s", $sid);
$sum->execute();
$summary = $sum->get_result()->fetch_assoc();

$page_title = "My Attendance";
$active_nav = "attendance";
include '../includes/header.php';
?>
<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-calendar-check" style="color:var(--accent)"></i> My Attendance</h1>
    <p>Your full attendance record. Late counts as half-present toward your attendance rate.</p>
  </div>

  <div class="stats-row">
    <div class="stat-card <?php echo ($summary['rate']??0)>=75?'stat-green':'stat-red'; ?>">
      <div class="stat-label">Attendance Rate</div>
      <div class="stat-value"><?php echo $summary['rate'] ?? 0; ?>%</div>
      <div class="stat-sub">30% of final grade</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Present</div>
      <div class="stat-value"><?php echo $summary['present'] ?? 0; ?></div>
    </div>
    <div class="stat-card stat-yellow">
      <div class="stat-label">Late</div>
      <div class="stat-value"><?php echo $summary['late'] ?? 0; ?></div>
      <div class="stat-sub">counts as 0.5</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-label">Absent</div>
      <div class="stat-value"><?php echo $summary['absent'] ?? 0; ?></div>
    </div>
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Days</div>
      <div class="stat-value"><?php echo $summary['total'] ?? 0; ?></div>
    </div>
  </div>

  <div class="card">
    <p class="card-title"><i class="ti ti-list"></i> Attendance History</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Date</th><th>Status</th><th>Time In</th><th>Remarks</th></tr>
        </thead>
        <tbody>
          <?php if ($records->num_rows === 0): ?>
          <tr><td colspan="4"><div class="empty-state"><i class="ti ti-calendar-off"></i><p>No attendance records yet.</p></div></td></tr>
          <?php endif; ?>
          <?php while ($r = $records->fetch_assoc()):
            $b = ['Present'=>'badge-green','Late'=>'badge-yellow','Absent'=>'badge-red'][$r['status']] ?? 'badge-blue';
          ?>
          <tr>
            <td style="font-weight:500;"><?php echo date('l, M d Y', strtotime($r['date'])); ?></td>
            <td><span class="badge <?php echo $b; ?>"><?php echo $r['status']; ?></span></td>
            <td style="color:var(--text2);font-size:12px;">
              <?php echo $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '—'; ?>
            </td>
            <td style="color:var(--text2);font-size:12px;"><?php echo htmlspecialchars($r['remarks'] ?: '—'); ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include '../includes/footer.php'; ?>