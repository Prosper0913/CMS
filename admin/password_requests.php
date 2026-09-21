<?php
// ============================================================
//  admin/password_requests.php
//  "Forgot password?" requests. The admin verifies who the person
//  is (in person / by phone / any way they trust), then issues a
//  one-time reset link that the person opens to choose their own
//  new password. The admin never sees or sets the password.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/audit.php';
require_once '../includes/csrf.php';

const RESET_LINK_MINUTES = 30;

$admin_id       = (int)$_SESSION['user_id'];
$admin_username = $_SESSION['username'] ?? '';

// One-time messages (Post/Redirect/Get)
$flash_ok   = $_SESSION['pwreq_flash_ok']   ?? '';
$flash_err  = $_SESSION['pwreq_flash_err']  ?? '';
$issued     = $_SESSION['pwreq_issued']     ?? null;   // ['link'=>..., 'username'=>...]
unset($_SESSION['pwreq_flash_ok'], $_SESSION['pwreq_flash_err'], $_SESSION['pwreq_issued']);

// Is the table there?
$table_ok = true;
try { $conn->query("SELECT 1 FROM password_reset_requests LIMIT 1"); }
catch (Throwable $e) { $table_ok = false; }

// ── POST actions ─────────────────────────────────────────────
if ($table_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($_POST['request_id'] ?? 0);

    if (!csrf_verify()) {
        $_SESSION['pwreq_flash_err'] = "Your session expired. Please try again.";
        header("Location: password_requests.php"); exit;
    }

    // Load the request + account it belongs to
    $rq = $conn->prepare(
        "SELECT r.id, r.user_id, r.status, u.username, u.role
         FROM password_reset_requests r JOIN users u ON u.id = r.user_id
         WHERE r.id = ? LIMIT 1"
    );
    $rq->bind_param('i', $rid);
    $rq->execute();
    $row = $rq->get_result()->fetch_assoc();

    if (!$row || !in_array($row['status'], ['pending', 'link_issued'], true)) {
        $_SESSION['pwreq_flash_err'] = "That request is no longer open.";
        header("Location: password_requests.php"); exit;
    }

    if (isset($_POST['issue_link'])) {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $mins  = RESET_LINK_MINUTES;
        $up = $conn->prepare(
            "UPDATE password_reset_requests
             SET status='link_issued', token_hash=?, processed_by=?, processed_at=NOW(),
                 token_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
             WHERE id=?"
        );
        $up->bind_param('siii', $hash, $admin_id, $mins, $rid);
        $up->execute();

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $link   = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/classroomv2/reset_password.php?token=' . $token;

        audit_log($conn, 'recovery_link_issued', 'success', [
            'user_id' => $admin_id, 'username' => $admin_username, 'role' => 'admin',
            'reason'  => 'For account: ' . $row['username'],
        ]);
        $_SESSION['pwreq_issued'] = ['link' => $link, 'username' => $row['username']];

    } elseif (isset($_POST['dismiss'])) {
        $up = $conn->prepare(
            "UPDATE password_reset_requests
             SET status='dismissed', token_hash=NULL, token_expires_at=NULL,
                 processed_by=?, processed_at=NOW()
             WHERE id=?"
        );
        $up->bind_param('ii', $admin_id, $rid);
        $up->execute();
        audit_log($conn, 'recovery_dismissed', 'success', [
            'user_id' => $admin_id, 'username' => $admin_username, 'role' => 'admin',
            'reason'  => 'For account: ' . $row['username'],
        ]);
        $_SESSION['pwreq_flash_ok'] = "Request for " . $row['username'] . " dismissed.";
    }
    header("Location: password_requests.php"); exit;
}

// ── Data ─────────────────────────────────────────────────────
$open = []; $history = [];
if ($table_ok) {
    $base =
        "SELECT r.*, u.username, u.role, u.display_name,
                s.first_name, s.last_name, s.course,
                pa.username AS processed_by_name,
                (r.token_expires_at IS NOT NULL AND r.token_expires_at <= NOW()) AS link_expired
         FROM password_reset_requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN students s ON s.student_id = u.student_id
         LEFT JOIN users pa ON pa.id = r.processed_by ";
    $open    = $conn->query($base . "WHERE r.status IN ('pending','link_issued') ORDER BY r.created_at ASC")->fetch_all(MYSQLI_ASSOC);
    $history = $conn->query($base . "WHERE r.status IN ('completed','dismissed') ORDER BY COALESCE(r.completed_at, r.processed_at, r.created_at) DESC LIMIT 25")->fetch_all(MYSQLI_ASSOC);
}

function pwreq_who(array $r): string {
    if (!empty($r['last_name'])) return $r['last_name'] . ', ' . $r['first_name'];
    return $r['display_name'] ?: $r['username'];
}
$role_badge = ['student' => 'badge-green', 'teacher' => 'badge-blue', 'admin' => 'badge-purple'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Password Requests — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-pwrequests">
<div class="app-shell">

<?php $active_nav = 'password_requests'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-lock-open text-accent"></i> Password Requests</h1>
    <p>People who used “Forgot password?” on the sign-in page. Confirm who they are first, then issue a one-time link — they choose their own new password.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <?php if (!$table_ok): ?>
    <div class="alert alert-error"><i class="ti ti-alert-circle"></i>
      <div>The <code>password_reset_requests</code> table doesn't exist yet. Run <code>audit_and_recovery.sql</code> on the database, then reload.</div>
    </div>
  <?php else: ?>

  <?php if ($issued): ?>
    <div class="alert alert-success" style="margin-bottom:20px;align-items:flex-start;">
      <i class="ti ti-circle-check" style="margin-top:2px;"></i>
      <div style="min-width:0;">
        <p style="margin:0 0 8px;font-weight:600;">Reset link for <b><?php echo htmlspecialchars($issued['username']); ?></b> — give it to them now, it won't be shown again.</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <code id="reset-link" style="font-family:'DM Mono',monospace;font-size:12.5px;background:var(--bg3);padding:8px 12px;border-radius:8px;border:1px solid var(--border);word-break:break-all;"><?php echo htmlspecialchars($issued['link']); ?></code>
          <button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('reset-link').innerText.trim())">
            <i class="ti ti-copy"></i> Copy
          </button>
        </div>
        <p style="margin:10px 0 0;font-size:12px;color:var(--text7);">
          Valid for <?php echo RESET_LINK_MINUTES; ?> minutes and works once. Only its hash is stored, so if it's lost, just issue a new one.
        </p>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($flash_ok): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><i class="ti ti-circle-check"></i><div><?php echo htmlspecialchars($flash_ok); ?></div></div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="ti ti-alert-circle"></i><div><?php echo htmlspecialchars($flash_err); ?></div></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:20px;">
    <p class="card-title"><i class="ti ti-inbox"></i> Open Requests (<?php echo count($open); ?>)</p>
    <?php if (empty($open)): ?>
      <div class="empty-state" style="color:var(--text7);"><i class="ti ti-circle-check"></i><p>No open password requests.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Requested</th><th>Account</th><th>Message</th><th>From</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($open as $r): ?>
          <tr>
            <td style="font-size:12px;color:var(--text7);white-space:nowrap;"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td>
              <div style="font-weight:500;"><?php echo htmlspecialchars(pwreq_who($r)); ?></div>
              <div style="font-size:11.5px;color:var(--text7);">
                <span class="badge <?php echo $role_badge[$r['role']] ?? 'badge-gray'; ?>"><?php echo htmlspecialchars(ucfirst($r['role'])); ?></span>
                <code><?php echo htmlspecialchars($r['username']); ?></code>
                <?php if ($r['course']): ?>&middot; <?php echo htmlspecialchars($r['course']); ?><?php endif; ?>
              </div>
            </td>
            <td style="font-size:12.5px;max-width:240px;"><?php echo $r['note'] !== null && $r['note'] !== '' ? nl2br(htmlspecialchars($r['note'])) : '<span class="text-muted">—</span>'; ?></td>
            <td style="font-size:12px;">
              <code><?php echo htmlspecialchars($r['requested_ip']); ?></code><br>
              <span style="color:var(--text7);"><?php echo htmlspecialchars($r['requested_device'] ?? ''); ?></span>
            </td>
            <td>
              <?php if ($r['status'] === 'pending'): ?>
                <span class="badge badge-yellow">Awaiting you</span>
              <?php elseif ($r['link_expired']): ?>
                <span class="badge badge-gray">Link expired</span>
              <?php else: ?>
                <span class="badge badge-blue">Link issued</span>
                <div style="font-size:11px;color:var(--text7);margin-top:3px;">expires <?php echo htmlspecialchars($r['token_expires_at']); ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="td-actions" style="display:flex;gap:6px;flex-wrap:wrap;">
                <form method="POST" style="margin:0;" onsubmit="return confirm('Only issue a link after you have confirmed this is really <?php echo htmlspecialchars(addslashes($r['username'])); ?>. Continue?');">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" name="issue_link" class="btn btn-primary btn-sm">
                    <i class="ti ti-link"></i> <?php echo $r['status'] === 'pending' ? 'Issue link' : 'Issue new link'; ?>
                  </button>
                </form>
                <form method="POST" style="margin:0;">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" name="dismiss" class="btn btn-outline btn-sm" title="Dismiss without issuing a link">
                    <i class="ti ti-x"></i> Dismiss
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <p class="card-title"><i class="ti ti-history"></i> Recently Handled</p>
    <?php if (empty($history)): ?>
      <p style="font-size:13px;color:var(--text7);">Nothing handled yet.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Requested</th><th>Account</th><th>Outcome</th><th>Handled by</th></tr></thead>
        <tbody>
        <?php foreach ($history as $r): ?>
          <tr>
            <td style="font-size:12px;color:var(--text7);white-space:nowrap;"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td><?php echo htmlspecialchars(pwreq_who($r)); ?> <code style="font-size:11px;"><?php echo htmlspecialchars($r['username']); ?></code></td>
            <td>
              <?php if ($r['status'] === 'completed'): ?>
                <span class="badge badge-green">Password changed</span>
                <span style="font-size:11px;color:var(--text7);"><?php echo htmlspecialchars($r['completed_at'] ?? ''); ?></span>
              <?php else: ?>
                <span class="badge badge-gray">Dismissed</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12.5px;"><?php echo htmlspecialchars($r['processed_by_name'] ?? '—'); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

</main>
</div>
</body>
</html>
