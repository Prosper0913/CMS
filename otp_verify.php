<?php
// ============================================================
//  otp_verify.php?u=<identifier>
//  Step 2 of self-service recovery: enter the 6-digit code that
//  was emailed, plus a new password, in one form. The code is
//  only ever compared server-side (as a SHA-256 hash) and is
//  burned the moment it's accepted, so it can't be reused.
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/audit.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_page.php';
require_once 'includes/otp.php';

const OTP_MIN_PASSWORD_LENGTH = 8;
const RESEND_MAX_PER_IP_PER_HOUR = 5;

$identifier = trim((string)($_GET['u'] ?? $_POST['identifier'] ?? ''));
if ($identifier === '') {
    header("Location: forgot_password.php");
    exit;
}
$identifier = audit_trunc($identifier, 100);

$error   = '';
$notice  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired. Please reload this page and try again.";
    } elseif (($_POST['action'] ?? '') === 'resend') {
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
            if ($recent < RESEND_MAX_PER_IP_PER_HOUR) {
                otp_request_and_send($conn, $identifier, 'password_reset', $ip);
            }
        } catch (Throwable $e) {
            error_log('[otp_verify] ' . $e->getMessage());
        }
        $notice = "If that account is eligible, a new code was just sent. "
                . "Please wait at least a minute between requests.";
    } else {
        // ── Verify code + set new password ───────────────────────
        $code = trim((string)($_POST['code'] ?? ''));
        $pw1  = trim((string)($_POST['new_password'] ?? ''));
        $pw2  = trim((string)($_POST['confirm_password'] ?? ''));

        if (!preg_match('/^\d{6}$/', $code)) {
            $error = "Enter the 6-digit code from your email.";
        } elseif (strlen($pw1) < OTP_MIN_PASSWORD_LENGTH) {
            $error = "Password must be at least " . OTP_MIN_PASSWORD_LENGTH . " characters.";
        } elseif ($pw1 !== $pw2) {
            $error = "The two passwords don't match.";
        } else {
            // Only touch the code (and its attempt counter) once the
            // password fields themselves are already valid, so a typo
            // in "confirm password" never burns an attempt for nothing.
            $result = otp_verify_and_consume($conn, $identifier, 'password_reset', $code);
            if (!$result['ok']) {
                $error = $result['error'];
            } else {
                $user = $result['user'];
                $hashed = password_hash($pw1, PASSWORD_DEFAULT);
                $conn->begin_transaction();
                try {
                    $u1 = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                    $u1->bind_param('si', $hashed, $user['id']);
                    $u1->execute();

                    if (!empty($user['student_id'])) {
                        $u2 = $conn->prepare("UPDATE students SET password=? WHERE student_id=?");
                        $u2->bind_param('ss', $hashed, $user['student_id']);
                        $u2->execute();
                    }
                    $conn->commit();

                    audit_log($conn, 'recovery_completed', 'success', [
                        'user_id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'],
                        'reason'  => 'otp',
                    ]);
                    header("Location: login.php?reset=1");
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('[otp_verify] ' . $e->getMessage());
                    $error = "Something went wrong and your password was not changed. Please try again.";
                }
            }
        }
    }
}

auth_page_start('Enter code', 'Account recovery', 'Check your email');
?>
  <p class="lead">
    If an account with an email on file matches what you entered, a 6-digit code was sent to it.
    Enter the code below along with your new password. The code expires in 10 minutes.
  </p>

  <?php if ($error): ?>
    <div class="alert err"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($notice): ?>
    <div class="alert ok"><i class="ti ti-circle-check"></i> <?php echo htmlspecialchars($notice); ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($identifier); ?>">

    <div class="form-group">
      <label>6-digit code</label>
      <div class="input-wrap">
        <i class="ti ti-shield-lock"></i>
        <input type="text" name="code" required maxlength="6" minlength="6"
               inputmode="numeric" pattern="\d{6}" autocomplete="one-time-code"
               placeholder="000000" autofocus
               style="letter-spacing:4px;font-weight:600;">
      </div>
    </div>
    <div class="form-group">
      <label>New password</label>
      <div class="input-wrap">
        <i class="ti ti-lock"></i>
        <input type="password" name="new_password" required minlength="<?php echo OTP_MIN_PASSWORD_LENGTH; ?>"
               autocomplete="new-password" placeholder="At least <?php echo OTP_MIN_PASSWORD_LENGTH; ?> characters">
      </div>
    </div>
    <div class="form-group">
      <label>Confirm new password</label>
      <div class="input-wrap">
        <i class="ti ti-lock-check"></i>
        <input type="password" name="confirm_password" required minlength="<?php echo OTP_MIN_PASSWORD_LENGTH; ?>"
               autocomplete="new-password" placeholder="Type it again">
      </div>
    </div>
    <button type="submit" class="btn-main"><i class="ti ti-check"></i> Verify &amp; update password</button>
  </form>

  <form method="POST" style="margin-top:10px;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($identifier); ?>">
    <input type="hidden" name="action" value="resend">
    <button type="submit" class="btn-main"
            style="background:transparent;border:1px solid var(--border-on-light);color:var(--school-text6);">
      <i class="ti ti-refresh"></i> Didn't get it? Resend code
    </button>
  </form>

  <a class="back-link" href="forgot_password.php"><i class="ti ti-arrow-left"></i> Use a different method</a>
<?php auth_page_end(); ?>
