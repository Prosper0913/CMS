<?php
// ============================================================
//  admin/dashboard.php
//  System-wide overview: total teachers, total students,
//  total subjects, and quick links into teacher/student
//  management. Admin-only (requireRole enforces it).
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';

$admin_name = $_SESSION['username'];

// ── Summary numbers ──────────────────────────────────────────
$totals = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role='teacher')  AS total_teachers,
        (SELECT COUNT(*) FROM students)                    AS total_students,
        (SELECT COUNT(*) FROM subjects WHERE is_active=1)  AS total_subjects,
        (SELECT COUNT(*) FROM sections)                    AS total_sections"
)->fetch_assoc();

// ── Teachers overview (subject counts only — teachers no longer own students) ──
$per_teacher = $conn->query(
    "SELECT u.id, u.username, u.display_name,
        (SELECT COUNT(*) FROM subjects sub WHERE sub.teacher_id = u.id AND sub.is_active = 1) AS subject_count
     FROM users u
     WHERE u.role = 'teacher'
     ORDER BY u.display_name ASC, u.username ASC"
);

// ── Recently added students, with their section (if any) ──────
$recent_students = $conn->query(
    "SELECT s.student_id, s.last_name, s.first_name, s.created_at,
            (SELECT GROUP_CONCAT(sec.section_name SEPARATOR ', ')
             FROM section_students ss JOIN sections sec ON sec.id = ss.section_id
             WHERE ss.student_id = s.student_id) AS section_names
     FROM students s
     ORDER BY s.created_at DESC
     LIMIT 8"
);

// ── Daily active users, last 30 days ──────────────────────────
// Distinct accounts (any role) with at least one successful login
// that day, sourced from the security audit log.
$login_rows = $conn->query(
    "SELECT DATE(created_at) AS d, COUNT(DISTINCT user_id) AS cnt
     FROM audit_log
     WHERE event_type = 'login' AND outcome = 'success'
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(created_at)"
)->fetch_all(MYSQLI_ASSOC);
$login_by_day = [];
foreach ($login_rows as $r) { $login_by_day[$r['d']] = (int)$r['cnt']; }

$usage_labels = [];
$usage_counts = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $usage_labels[] = date('M j', strtotime($d));
    $usage_counts[] = $login_by_day[$d] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-dashboard">

<div class="app-shell">
<?php $active_nav = 'dashboard'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-shield-lock text-accent"></i> Admin Dashboard</h1>
    <?php
echo date("l, F j, Y"); 
?>
    <p>System-wide overview across every teacher account.</p>
  </div>

<hr class="thin-line">

  <!-- ── Daily active users ── -->
  <div class="card" style="margin-top:20px;">
    <p class="card-title"><i class="ti ti-chart-line"></i> Daily Active Users <span style="font-weight:400;font-size:11px;color:var(--text7);">(last 30 days)</span></p>
    <?php if (array_sum($usage_counts) === 0): ?>
    <div class="empty-state" style="padding:30px;">
      <i class="ti ti-chart-line-off"></i>
      <p>No logins recorded in the last 30 days yet.</p>
    </div>
    <?php else: ?>
    <div style="height:260px;">
      <canvas id="usageChart"></canvas>
    </div>
    <?php endif; ?>
  </div>

  <div class="two-col" style="margin-top:20px;">

    <!-- ── Per-teacher breakdown ── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-user-star"></i> Teachers Overview</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Teacher</th>
              <th>Active Subjects</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($per_teacher->num_rows === 0): ?>
            <tr><td colspan="2">
              <div class="empty-state">
                <i class="ti ti-user-off"></i>
                <p>No teacher accounts yet.</p>
              </div>
            </td></tr>
            <?php endif; ?>
            <?php while ($t = $per_teacher->fetch_assoc()): ?>
            <tr>
              <td>
                <div style="font-weight:500;"><?php echo htmlspecialchars($t['display_name'] ?: $t['username']); ?></div>
                <div style="font-size:11px;color:var(--text7);">@<?php echo htmlspecialchars($t['username']); ?></div>
              </td>
              <td><span class="badge badge-blue"><?php echo (int)$t['subject_count']; ?></span></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:14px;">
        <a href="teachers.php" class="btn btn-outline btn-sm"><i class="ti ti-user-star"></i> Manage Teachers</a>
      </div>
    </div>

    <!-- ── Recently added students ── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-user-plus"></i> Recently Added Students</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Student</th>
              <th>Section</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recent_students->num_rows === 0): ?>
            <tr><td colspan="3">
              <div class="empty-state">
                <i class="ti ti-users-off"></i>
                <p>No students yet.</p>
              </div>
            </td></tr>
            <?php endif; ?>
            <?php while ($r = $recent_students->fetch_assoc()): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></td>
              <td>
                <?php echo $r['section_names'] ? htmlspecialchars($r['section_names']) : '<span class="text-muted">Unassigned</span>'; ?>
              </td>
              <td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:14px;">
        <a href="students.php" class="btn btn-outline btn-sm"><i class="ti ti-users"></i> Manage All Students</a>
      </div>
    </div>

  </div>
</div>

</main>
</div>
<?php if (array_sum($usage_counts) > 0): ?>
<script>
(function(){
  const ctx = document.getElementById('usageChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?php echo json_encode($usage_labels); ?>,
      datasets: [{
        label: 'Users logged in',
        data: <?php echo json_encode($usage_counts); ?>,
        borderColor: '#1e5f4e',
        backgroundColor: 'rgba(30,95,78,.12)',
        tension: 0.3,
        fill: true,
        pointRadius: 2,
        pointHoverRadius: 5,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
        x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } }
      }
    }
  });
})();
</script>
<?php endif; ?>
</body>
</html>