<?php
// ============================================================
//  student/messages.php
//  Private messages sent to this student by their teachers.
//  Opening a message marks it as read. Read-only (no replies).
// ============================================================
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];
$unread_count = getUnreadNotificationCount($conn, $sid);

$open_id = (int)($_GET['id'] ?? 0);
$messages = [];
$load_error = '';
try {
    // Opening a message marks it read (only the student's own).
    if ($open_id > 0) {
        $u = $conn->prepare("UPDATE student_messages SET read_at = NOW() WHERE id = ? AND student_id = ? AND read_at IS NULL");
        $u->bind_param('is', $open_id, $sid);
        $u->execute();
    }
    $q = $conn->prepare(
        "SELECT m.id, m.body, m.created_at, m.read_at, sub.subject_code, sub.subject_name,
                COALESCE(NULLIF(u.display_name,''), u.username) AS teacher_name
         FROM student_messages m
         JOIN subjects sub ON sub.id = m.subject_id
         LEFT JOIN users u ON u.id = m.teacher_id
         WHERE m.student_id = ?
         ORDER BY m.created_at DESC LIMIT 100"
    );
    $q->bind_param('s', $sid);
    $q->execute();
    $messages = $q->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    error_log('[student/messages] ' . $e->getMessage());
    $load_error = "Messages are unavailable right now. Please try again later.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Messages — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-student-notifications">
<div class="app-shell">

<?php $active_nav = 'messages'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">
<div class="page-wrap">
  <p class="bottom-margin">Messages from your teachers</p>

  <?php if ($load_error): ?>
    <div class="alert alert-error" style="margin-bottom:16px;"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($load_error); ?></div>
  <?php elseif (empty($messages)): ?>
    <div class="empty-state">
      <i class="ti ti-message-off" style="color:var(--yellow2);"></i>
      <p class="text-muted">No messages yet.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <?php foreach ($messages as $m):
        $is_open = ($m['id'] === $open_id);
        $unread  = empty($m['read_at']) && !$is_open;
      ?>
      <div id="m<?php echo (int)$m['id']; ?>" style="padding:14px 0;border-top:1px solid var(--border);<?php echo $is_open ? 'background:rgba(30,95,78,.06);padding-left:10px;padding-right:10px;border-radius:8px;' : ''; ?>">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
          <span style="font-weight:<?php echo $unread ? '700' : '600'; ?>;font-size:14px;">
            <?php echo htmlspecialchars($m['teacher_name'] ?? 'Teacher'); ?>
            <?php if ($unread): ?><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--red);margin-left:6px;"></span><?php endif; ?>
          </span>
          <span style="font-size:12px;color:var(--text7);">
            <?php echo htmlspecialchars($m['subject_code']); ?> · <?php echo date('M d, Y g:i A', strtotime($m['created_at'])); ?>
          </span>
        </div>
        <p style="font-size:13.5px;margin-top:8px;white-space:pre-wrap;"><?php echo htmlspecialchars($m['body']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</main>
</div>
<script src="/classroomv2/assets/ui.js" defer></script>
<script src="/classroomv2/assets/pwa.js" defer></script>
<?php if ($open_id): ?><script>document.getElementById('m<?php echo $open_id; ?>')?.scrollIntoView({block:'center'});</script><?php endif; ?>
</body>
</html>
