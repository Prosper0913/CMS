<?php
// ============================================================
//  forgot_password.php
//  "Forgot password?" — two ways to recover an account:
//    1. Email me a one-time code (self-service, needs an email
//       on file — Settings → Contact Info). Handled here by
//       generating + emailing an OTP, then handing off to
//       otp_verify.php to enter the code and set a new password.
//    2. Request admin help (unchanged) — files a request that an
//       admin reviews under Admin → Password Requests, then issues
//       a one-time reset link.
//
//  Neither path reveals whether an account/email exists: the
//  response is always the same generic message either way.
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/audit.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_page.php';
require_once 'includes/otp.php';

// Already signed in? Nothing to recover.
if (isset($_SESSION['role'])) {
    header("Location: " . match ($_SESSION['role']) {
        'teacher' => '/classroomv2/teacher/dashboard.php',
        'admin'   => '/classroomv2/admin/dashboard.php',
        default   => '/classroomv2/student/dashboard.php',
    });
    exit;
}

const RECOVERY_MAX_PER_IP_PER_HOUR = 5;

$error     = '';
$submitted = false;
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = ($_POST['method'] ?? 'admin') === 'otp' ? 'otp' : 'admin';

    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $note       = trim((string)($_POST['note'] ?? ''));
        $identifier = audit_trunc($identifier, 100);
        $note       = audit_trunc($note, 255);

        if ($identifier === '') {
            $error = "Please enter your Student ID or username.";
        } elseif ($method === 'otp') {
            // ── Self-service: email a one-time code ─────────────
            $ip = audit_client_ip();
            try {
                $rl = $conn->prepare(
                    "SELECT COUNT(*) AS c FROM audit_log
                     WHERE event_type='otp_request' AND ip_address=?
                       AND created_at >= (NOW() - INTERVAL 1 HOUR)"
                );
                $rl->bind_param('s', $ip);
                $rl->execute();
                $recent = (int)$rl->get_result()->fetch_assoc()['c'];

                if ($recent >= RECOVERY_MAX_PER_IP_PER_HOUR) {
                    audit_log($conn, 'otp_request', 'failure', [
                        'username' => $identifier, 'reason' => 'rate_limited',
                    ]);
                } else {
                    otp_request_and_send($conn, $identifier, 'password_reset', $ip);
                }
            } catch (Throwable $e) {
                error_log('[forgot_password] ' . $e->getMessage());
            }
            // Always the same next step, whatever happened above.
            header("Location: otp_verify.php?u=" . urlencode($identifier));
            exit;
        } else {
            // ── Admin-mediated request (unchanged) ───────────────
            $submitted = true;
            $ip = audit_client_ip();

            try {
                $rl = $conn->prepare(
                    "SELECT COUNT(*) AS c FROM audit_log
                     WHERE event_type='recovery_request' AND ip_address=?
                       AND created_at >= (NOW() - INTERVAL 1 HOUR)"
                );
                $rl->bind_param('s', $ip);
                $rl->execute();
                $recent = (int)$rl->get_result()->fetch_assoc()['c'];

                if ($recent >= RECOVERY_MAX_PER_IP_PER_HOUR) {
                    audit_log($conn, 'recovery_request', 'failure', [
                        'username' => $identifier, 'reason' => 'rate_limited',
                    ]);
                } else {
                    $uq = $conn->prepare("SELECT id, username, role FROM users WHERE username=? LIMIT 1");
                    $uq->bind_param('s', $identifier);
                    $uq->execute();
                    $u = $uq->get_result()->fetch_assoc();

                    if (!$u) {
                        audit_log($conn, 'recovery_request', 'failure', [
                            'username' => $identifier, 'reason' => 'unknown_user',
                        ]);
                    } else {
                        $oq = $conn->prepare(
                            "SELECT id FROM password_reset_requests
                             WHERE user_id=? AND status IN ('pending','link_issued')
                               AND created_at >= (NOW() - INTERVAL 1 DAY) LIMIT 1"
                        );
                        $oq->bind_param('i', $u['id']);
                        $oq->execute();
                        $open = $oq->get_result()->fetch_assoc();

                        if ($open) {
                            audit_log($conn, 'recovery_request', 'success', [
                                'user_id' => $u['id'], 'username' => $u['username'], 'role' => $u['role'],
                                'reason'  => 'already_pending',
                            ]);
                        } else {
                            $ua  = audit_parse_ua($_SERVER['HTTP_USER_AGENT'] ?? '');
                            $dev = audit_trunc($ua['browser'] . ' on ' . $ua['os'] . ' (' . $ua['device_type'] . ')', 120);
                            $ins = $conn->prepare(
                                "INSERT INTO password_reset_requests
                                    (user_id, identifier, note, requested_ip, requested_device)
                                 VALUES (?,?,?,?,?)"
                            );
                            $ins->bind_param('issss', $u['id'], $identifier, $note, $ip, $dev);
                            $ins->execute();
                            audit_log($conn, 'recovery_request', 'success', [
                                'user_id' => $u['id'], 'username' => $u['username'], 'role' => $u['role'],
                            ]);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[forgot_password] ' . $e->getMessage());
            }
        }
    }
}

auth_page_start('Forgot password', 'Account recovery', 'Forgot your password?');
?>

<?php if ($submitted): ?>
  <div class="alert ok"><i class="ti ti-circle-check"></i>
    <div>If that account exists, an administrator has been notified. Once they have
    confirmed it's really you, they will give you a one-time link to set a new password.</div>
  </div>
  <p class="lead" style="margin-bottom:0;">The link works once and expires 30 minutes after it is issued.</p>
  <a class="back-link" href="login.php"><i class="ti ti-arrow-left"></i> Back to sign in</a>

<?php else: ?>
  <p class="lead">
    Enter your Student ID (or username), then choose how you'd like to recover your account.
  </p>

  <?php if ($error): ?>
    <div class="alert err"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <?php echo csrf_field(); ?>
    <div class="form-group">
      <label>Student ID / Username</label>
      <div class="input-wrap">
        <i class="ti ti-user"></i>
        <input type="text" name="identifier" maxlength="100" required autofocus
               placeholder="Students: enter your Student ID"
               value="<?php echo htmlspecialchars($identifier); ?>">
      </div>
    </div>

    <button type="submit" name="method" value="otp" class="btn-main">
      <i class="ti ti-mail"></i> Email me a one-time code
    </button>
    <p class="hint" style="margin-top:-8px;">
      Needs an email saved to your account (Settings → Contact Info).
    </p>

    <div style="display:flex;align-items:center;gap:10px;color:var(--school-text7);font-size:12px;margin:2px 0;">
      <div style="flex:1;height:1px;background:var(--border-on-light);"></div>
      or
      <div style="flex:1;height:1px;background:var(--border-on-light);"></div>
    </div>

    <div class="form-group">
      <label>Message for the administrator <span style="font-weight:400;">(optional)</span></label>
      <div class="input-wrap top">
        <i class="ti ti-message"></i>
        <textarea name="note" maxlength="255"
          placeholder="e.g. your section, and how the admin can reach you to confirm it's you"></textarea>
      </div>
    </div>
    <button type="submit" name="method" value="admin" class="btn-main"
            style="background:transparent;border:1px solid var(--border-on-light);color:var(--school-text6);">
      <i class="ti ti-send"></i> Request admin help instead
    </button>
  </form>
  <a class="back-link" href="login.php"><i class="ti ti-arrow-left"></i> Back to sign in</a>
<?php endif; ?>

<?php auth_page_end(); ?>
