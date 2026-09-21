<?php
// ============================================================
//  forgot_password.php
//  "Forgot password?" — the person enters their Student ID /
//  username. This does NOT reveal whether the account exists and
//  does NOT email anything: it files a request that an admin sees
//  under Admin → Password Requests. After verifying who they are,
//  the admin issues a one-time reset link (valid 30 minutes).
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/audit.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_page.php';

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
    if (!csrf_verify()) {
        $error = "Your session expired. Please try again.";
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $note       = trim((string)($_POST['note'] ?? ''));
        $identifier = audit_trunc($identifier, 100);
        $note       = audit_trunc($note, 255);

        if ($identifier === '') {
            $error = "Please enter your Student ID or username.";
        } else {
            // The person always gets the same answer, whatever happens below,
            // so this form can't be used to find out which accounts exist.
            $submitted = true;
            $ip = audit_client_ip();

            try {
                // Too many requests from this address in the last hour?
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
                        // Only one open request per account at a time.
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
                // Still show the generic message — never leak internals here.
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
    Enter your Student ID (or username). An administrator will verify who you are and
    give you a one-time link to choose a new password.
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
    <div class="form-group">
      <label>Message for the administrator <span style="font-weight:400;">(optional)</span></label>
      <div class="input-wrap top">
        <i class="ti ti-message"></i>
        <textarea name="note" maxlength="255"
          placeholder="e.g. your section, and how the admin can reach you to confirm it's you"></textarea>
      </div>
    </div>
    <button type="submit" class="btn-main"><i class="ti ti-send"></i> Request password reset</button>
  </form>
  <a class="back-link" href="login.php"><i class="ti ti-arrow-left"></i> Back to sign in</a>
<?php endif; ?>

<?php auth_page_end(); ?>
