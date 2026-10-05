<?php
// ============================================================
//  student/settings.php
//  Account settings for a student: contact info (email + phone),
//  change password, login history, and recent account activity.
//  Replaces the old standalone change_password.php page.
// ============================================================
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';

$sid = $_SESSION['student_id'];
$unread_count = getUnreadNotificationCount($conn, $sid);

$success_msg = '';
$error_msg   = '';

// Contact number validator shared with the rest of the app's conventions.
function settings_contact_error(string $c): string {
    if ($c === '') return '';
    $digits = strlen(preg_replace('/\D/', '', $c));
    if (strlen($c) > 20 || !preg_match('/^\+?[0-9\s\-()]+$/', $c) || $digits < 7 || $digits > 15) {
        return "Contact number must be 7-15 digits (spaces, dashes, brackets and a leading + are allowed).";
    }
    return '';
}
function settings_email_error(string $e): string {
    if ($e === '') return '';
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return "Please enter a valid email address.";
    return '';
}

$me_q = $conn->prepare("SELECT id, username, password FROM users WHERE student_id = ? LIMIT 1");
$me_q->bind_param("s", $sid);
$me_q->execute();
$me = $me_q->get_result()->fetch_assoc();

$s_q = $conn->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
$s_q->bind_param("s", $sid);
$s_q->execute();
$student = $s_q->get_result()->fetch_assoc();

// ── Update contact info (email + contact number) ──────────────
if (isset($_POST['update_profile'])) {
    if (!csrf_verify()) {
        $error_msg = "Your session expired. Please refresh the page and try again.";
    } else {
        $email   = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');

        if (($eerr = settings_email_error($email)) !== '') {
            $error_msg = $eerr;
        } elseif (($cerr = settings_contact_error($contact)) !== '') {
            $error_msg = $cerr;
        } else {
            $email_db   = $email !== '' ? $email : null;
            $contact_db = $contact !== '' ? $contact : null;
            $upd = $conn->prepare("UPDATE students SET email = ?, contact_number = ? WHERE student_id = ?");
            $upd->bind_param("sss", $email_db, $contact_db, $sid);
            $upd->execute();

            audit_log($conn, 'profile_update', 'success', [
                'user_id' => $me['id'] ?? null, 'username' => $me['username'] ?? $sid, 'role' => 'student',
                'reason'  => 'updated email/contact number',
            ]);
            $success_msg = "Contact information updated.";
            $student['email'] = $email_db;
            $student['contact_number'] = $contact_db;
        }
    }
}

// ── Change password ────────────────────────────────────────────
if (isset($_POST['change_password'])) {
    $current = trim($_POST['current_password'] ?? '');
    $new_pw   = trim($_POST['new_password'] ?? '');
    $confirm  = trim($_POST['confirm_password'] ?? '');

    if (!csrf_verify()) {
        $error_msg = "Your session expired. Please refresh the page and try again.";
    } elseif (!$me || !password_verify($current, $me['password'])) {
        audit_log($conn, 'password_change', 'failure', [
            'user_id' => $me['id'] ?? null, 'username' => $me['username'] ?? $sid, 'role' => 'student',
            'reason'  => 'wrong current password (settings page)',
        ]);
        $error_msg = "Your current password is incorrect.";
    } elseif (strlen($new_pw) < 8) {
        $error_msg = "New password must be at least 8 characters.";
    } elseif ($new_pw !== $confirm) {
        $error_msg = "The two passwords don't match.";
    } elseif (password_verify($new_pw, $me['password'])) {
        $error_msg = "That's your current password — please choose a different one.";
    } else {
        $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
        $p1 = $conn->prepare("UPDATE users SET password = ? WHERE student_id = ?");
        $p1->bind_param("ss", $hashed, $sid); $p1->execute();
        $p2 = $conn->prepare("UPDATE students SET password = ? WHERE student_id = ?");
        $p2->bind_param("ss", $hashed, $sid); $p2->execute();

        audit_log($conn, 'password_change', 'success', [
            'user_id' => $me['id'], 'username' => $me['username'], 'role' => 'student',
            'reason'  => 'password changed via settings page',
        ]);
        $success_msg = "Your password has been updated.";
    }
}

// ── Login history & recent activity ─────────────────────────────
$login_history = [];
$recent_activity = [];
if ($me) {
    $lh = $conn->prepare(
        "SELECT created_at, outcome, ip_address, device_type, browser, os
         FROM audit_log WHERE user_id = ? AND event_type = 'login'
         ORDER BY created_at DESC LIMIT 20"
    );
    $lh->bind_param("i", $me['id']);
    $lh->execute();
    $login_history = $lh->get_result()->fetch_all(MYSQLI_ASSOC);

    $ra = $conn->prepare(
        "SELECT created_at, event_type, outcome, reason, ip_address, device_type, browser
         FROM audit_log WHERE user_id = ? AND event_type != 'login'
         ORDER BY created_at DESC LIMIT 20"
    );
    $ra->bind_param("i", $me['id']);
    $ra->execute();
    $recent_activity = $ra->get_result()->fetch_all(MYSQLI_ASSOC);
}

$event_labels = [
    'logout'           => ['Signed out', 'ti-logout'],
    'password_change'  => ['Password changed', 'ti-key'],
    'profile_update'   => ['Profile updated', 'ti-user-edit'],
    'recovery_request'          => ['Requested password reset', 'ti-mail'],
    'recovery_completed'        => ['Reset password via recovery link', 'ti-lock-check'],
    'recovery_link_invalid'     => ['Used an invalid/expired reset link', 'ti-lock-exclamation'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Settings — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-student-settings">
<div class="app-shell">

<?php $active_nav = 'settings'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <div class="page-header">
    <h1><i class="ti ti-settings text-accent"></i> Settings</h1>
    <p>Manage your contact info, password, and review your account's recent activity.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg): ?>
    <div class="alert alert-success" style="margin-bottom:16px;"><i class="ti ti-circle-check"></i> <?php echo htmlspecialchars($success_msg); ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
    <div class="alert alert-error" style="margin-bottom:16px;"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
  <?php endif; ?>

  <div class="two-col">
  <div>

  <!-- ── CONTACT INFO ── -->
  <div class="card" style="margin-bottom:20px;">
    <p class="card-title"><i class="ti ti-id-badge-2"></i> Contact Information</p>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <div class="form-group">
        <label>Gmail / Email</label>
        <input type="email" name="email" class="form-control" placeholder="you@gmail.com"
          value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Contact Number</label>
        <input type="tel" name="contact_number" class="form-control" maxlength="20" placeholder="e.g. 09171234567"
          value="<?php echo htmlspecialchars($student['contact_number'] ?? ''); ?>">
      </div>
      <button type="submit" name="update_profile" class="btn btn-primary"><i class="ti ti-check"></i> Save Contact Info</button>
    </form>
  </div>

  <!-- ── CHANGE PASSWORD ── -->
  <div class="card" style="margin-bottom:20px;">
    <p class="card-title"><i class="ti ti-lock"></i> Change Password</p>
    <form method="POST" autocomplete="off">
      <?php echo csrf_field(); ?>
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
      </div>
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
        <div style="font-size:12px;color:var(--text7);margin-top:4px;">At least 8 characters.</div>
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
      </div>
      <button type="submit" name="change_password" class="btn btn-primary"><i class="ti ti-key"></i> Update Password</button>
    </form>
  </div>

  </div>
  <div>

  <!-- ── LOGIN HISTORY ── -->
  <div class="card" style="margin-bottom:20px;">
    <p class="card-title"><i class="ti ti-history"></i> Login History</p>
    <?php if (empty($login_history)): ?>
      <div class="empty-state"><i class="ti ti-history"></i><p>No login history recorded yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>When</th><th>Result</th><th>Device</th><th>IP Address</th></tr></thead>
        <tbody>
          <?php foreach ($login_history as $lh): ?>
          <tr>
            <td class="td-mono"><?php echo date('M d, Y g:i A', strtotime($lh['created_at'])); ?></td>
            <td>
              <?php if ($lh['outcome'] === 'success'): ?>
                <span class="badge badge-green">Success</span>
              <?php else: ?>
                <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:99px;background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);">Failed</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12.5px;color:var(--text7);">
              <?php echo htmlspecialchars(($lh['device_type'] ?: 'Unknown') . ' · ' . ($lh['browser'] ?: 'Unknown') . ' · ' . ($lh['os'] ?: 'Unknown')); ?>
            </td>
            <td class="td-mono"><?php echo htmlspecialchars($lh['ip_address']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── RECENT ACTIVITY ── -->
  <div class="card">
    <p class="card-title"><i class="ti ti-activity"></i> Recent Activity</p>
    <?php if (empty($recent_activity)): ?>
      <div class="empty-state"><i class="ti ti-activity"></i><p>No other account activity recorded yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>When</th><th>Activity</th><th>Result</th></tr></thead>
        <tbody>
          <?php foreach ($recent_activity as $ra):
            [$label, $icon] = $event_labels[$ra['event_type']] ?? [htmlspecialchars($ra['event_type']), 'ti-point'];
          ?>
          <tr>
            <td class="td-mono"><?php echo date('M d, Y g:i A', strtotime($ra['created_at'])); ?></td>
            <td><i class="ti <?php echo $icon; ?>" style="color:var(--text7);margin-right:4px;"></i><?php echo $label; ?></td>
            <td>
              <?php if ($ra['outcome'] === 'success'): ?>
                <span class="badge badge-green">Success</span>
              <?php else: ?>
                <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:99px;background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);">Failed</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  </div>
  </div>

</div>

</main>
</div>
</body>
</html>
