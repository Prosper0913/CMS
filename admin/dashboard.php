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
        (SELECT COUNT(*) FROM sections)                    AS total_sections,
        (SELECT COUNT(*) FROM subject_section_requests WHERE status='pending') AS pending_requests"
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
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-dashboard">

<?php $active_nav = 'dashboard'; include __DIR__ . '/_nav.php'; ?>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-shield-lock" style="color:var(--accent)"></i> Admin Dashboard</h1>
    <p>System-wide overview across every teacher account.</p>
  </div>

<hr class="thin-line">

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
                <?php echo $r['section_names'] ? htmlspecialchars($r['section_names']) : '<span style="color:var(--text7)">Unassigned</span>'; ?>
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

</body>
</html>
