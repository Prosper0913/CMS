<?php
// ============================================================
//  student/notifications.php
//  Central notification feed — absence-streak alerts, new
//  outputs posted, and teacher announcements all land here.
// ============================================================
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];

// ── Mark one as read (and follow its link) ──────────────────
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    $u = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND student_id=?");
    $u->bind_param("is", $nid, $sid);
    $u->execute();

    $l = $conn->prepare("SELECT link FROM notifications WHERE id=? AND student_id=?");
    $l->bind_param("is", $nid, $sid);
    $l->execute();
    $link = $l->get_result()->fetch_assoc()['link'] ?? null;

    header("Location: " . ($link ?: 'notifications.php'));
    exit;
}

// ── Mark all as read ─────────────────────────────────────────
if (isset($_POST['mark_all_read'])) {
    $u = $conn->prepare("UPDATE notifications SET is_read=1 WHERE student_id=?");
    $u->bind_param("s", $sid);
    $u->execute();
    header("Location: notifications.php");
    exit;
}

// ── Fetch notifications ──────────────────────────────────────
$q = $conn->prepare(
    "SELECT n.id, n.type, n.title, n.message, n.link, n.is_read, n.created_at,
            sub.subject_code
     FROM notifications n
     LEFT JOIN subjects sub ON sub.id = n.subject_id
     WHERE n.student_id = ?
     ORDER BY n.created_at DESC
     LIMIT 100"
);
$q->bind_param("s", $sid);
$q->execute();
$notifications = $q->get_result()->fetch_all(MYSQLI_ASSOC);

$unread_count = 0;
foreach ($notifications as $n) if (!$n['is_read']) $unread_count++;

$type_icons = [
    'absence_streak' => ['ti-calendar-x',    '#f87171'],
    'new_output'     => ['ti-report',        '#7aa3ff'],
    'announcement'   => ['ti-speakerphone',  '#fbbf24'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Notifications — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-student-notifications">
<div class="app-shell">


<?php $active_nav = 'notifications'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <p class="bottom-margin" style="margin:0;">
      Notifications
      <?php if ($unread_count > 0): ?>
        <span style="font-size:12px;color:var(--red);">(<?php echo $unread_count; ?> unread)</span>
      <?php endif; ?>
    </p>
    <?php if ($unread_count > 0): ?>
    <form method="POST">
      <button type="submit" name="mark_all_read" class="btn btn-outline btn-sm">
        <i class="ti ti-checks"></i> Mark all as read
      </button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (empty($notifications)): ?>
  <div class="empty-state">
    <i class="ti ti-bell-off" style="color:var(--text6);"></i>
    <p>No notifications yet.</p>
  </div>
  <?php else: ?>
  <div class="card">
    <?php foreach ($notifications as $n):
      [$icon, $color] = $type_icons[$n['type']] ?? ['ti-bell', 'var(--text7)'];
    ?>
    <a href="notifications.php?read=<?php echo $n['id']; ?>"
       style="display:flex;gap:12px;padding:14px 0;border-top:1px solid var(--border);text-decoration:none;color:inherit;<?php echo $n['is_read'] ? 'opacity:.6;' : ''; ?>">
      <div style="flex-shrink:0;width:34px;height:34px;border-radius:50%;background:<?php echo $color; ?>18;display:flex;align-items:center;justify-content:center;">
        <i class="ti <?php echo $icon; ?>" style="color:<?php echo $color; ?>;font-size:17px;"></i>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-weight:<?php echo $n['is_read'] ? '500' : '700'; ?>;font-size:13.5px;"><?php echo htmlspecialchars($n['title']); ?></span>
          <?php if (!$n['is_read']): ?><span style="width:7px;height:7px;border-radius:50%;background:var(--red);flex-shrink:0;"></span><?php endif; ?>
        </div>
        <p style="font-size:13px;color:var(--text7);margin-top:3px;"><?php echo htmlspecialchars($n['message']); ?></p>
        <div style="font-size:11px;color:var(--text7);margin-top:5px;">
          <?php if ($n['subject_code']): ?><?php echo htmlspecialchars($n['subject_code']); ?> · <?php endif; ?>
          <?php echo date('M d, Y g:i A', strtotime($n['created_at'])); ?>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

</main>
</div>
</body>
</html>
