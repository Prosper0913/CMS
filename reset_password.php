<?php
// ============================================================
//  reset_password.php?token=...
//  The one-time link an admin issues from Admin → Password Requests.
//  - The token is random and only its SHA-256 hash is stored.
//  - Valid for 30 minutes, works once, and is voided when used.
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/audit.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_page.php';

const RESET_MIN_PASSWORD_LENGTH = 8;

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$valid_format = (bool)preg_match('/^[a-f0-9]{64}$/', $token);

$req = null;
if ($valid_format) {
    $tokenHash = hash('sha256', $token);
    $q = $conn->prepare(
        "SELECT r.id AS request_id, r.user_id, u.username, u.role, u.student_id
         FROM password_reset_requests r
         JOIN users u ON u.id = r.user_id
         WHERE r.token_hash = ? AND r.status = 'link_issued'
           AND r.token_expires_at > NOW()
         LIMIT 1"
    );
    $q->bind_param('s', $tokenHash);
    $q->execute();
    $req = $q->get_result()->fetch_assoc();
}

$error = '';

// ── Form submitted ───────────────────────────────────────────
if ($req && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = trim((string)($_POST['new_password'] ?? ''));
    $pw2 = trim((string)($_POST['confirm_password'] ?? ''));

    if (!csrf_verify()) {
        $error = "Your session expired. Please reload this page and try again.";
    } elseif (strlen($pw1) < RESET_MIN_PASSWORD_LENGTH) {
        $error = "Password must be at least " . RESET_MIN_PASSWORD_LENGTH . " characters.";
    } elseif ($pw1 !== $pw2) {
        $error = "The two passwords don't match.";
    } else {
        $hashed = password_hash($pw1, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $u1 = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $u1->bind_param('si', $hashed, $req['user_id']);
            $u1->execute();

            // Students keep a second copy of the hash in students.password
            // (same as Admin → Students → reset password).
            if (!empty($req['student_id'])) {
                $u2 = $conn->prepare("UPDATE students SET password=? WHERE student_id=?");
                $u2->bind_param('ss', $hashed, $req['student_id']);
                $u2->execute();
            }

            // Burn the token so the link can never be used again.
            $u3 = $conn->prepare(
                "UPDATE password_reset_requests
                 SET status='completed', completed_at=NOW(), token_hash=NULL, token_expires_at=NULL
                 WHERE id=? AND status='link_issued'"
            );
            $u3->bind_param('i', $req['request_id']);
            $u3->execute();
            if ($u3->affected_rows < 1) {
                throw new Exception('Link was already used.');
            }
            $conn->commit();

            audit_log($conn, 'recovery_completed', 'success', [
                'user_id' => $req['user_id'], 'username' => $req['username'], 'role' => $req['role'],
            ]);
            header("Location: login.php?reset=1");
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[reset_password] ' . $e->getMessage());
            $error = "Something went wrong and your password was not changed. Please ask the administrator for a new link.";
            $req = null;   // show the "invalid link" card below
        }
    }
} elseif (!$req && $token !== '') {
    // Someone opened/submitted a link that is wrong, expired or already used.
    audit_log($conn, 'recovery_link_invalid', 'failure', [
        'reason' => $valid_format ? 'invalid_or_expired' : 'malformed_token',
    ]);
}

// ── Page ─────────────────────────────────────────────────────
if (!$req):
    auth_page_start('Reset password', 'Account recovery', 'Link not valid');
?>
  <div class="alert err"><i class="ti ti-alert-circle"></i>
    <div><?php echo $error !== '' ? htmlspecialchars($error)
        : "This reset link is invalid, has expired, or has already been used."; ?></div>
  </div>
  <p class="lead">Reset links work once and expire 30 minutes after the administrator issues them.
     You can request a new one below.</p>
  <a class="btn-main" href="forgot_password.php"><i class="ti ti-lock-open"></i> Request a new link</a>
  <a class="back-link" href="login.php"><i class="ti ti-arrow-left"></i> Back to sign in</a>
<?php
    auth_page_end();
    exit;
endif;

auth_page_start('Reset password', 'Account recovery', 'Choose a new password');
?>
  <p class="lead">Setting a new password for <strong><?php echo htmlspecialchars($req['username']); ?></strong>.</p>

  <?php if ($error): ?>
    <div class="alert err"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
    <div class="form-group">
      <label>New password</label>
      <div class="input-wrap">
        <i class="ti ti-lock"></i>
        <input type="password" name="new_password" required minlength="<?php echo RESET_MIN_PASSWORD_LENGTH; ?>"
               autocomplete="new-password" autofocus placeholder="At least <?php echo RESET_MIN_PASSWORD_LENGTH; ?> characters">
      </div>
    </div>
    <div class="form-group">
      <label>Confirm new password</label>
      <div class="input-wrap">
        <i class="ti ti-lock-check"></i>
        <input type="password" name="confirm_password" required minlength="<?php echo RESET_MIN_PASSWORD_LENGTH; ?>"
               autocomplete="new-password" placeholder="Type it again">
      </div>
    </div>
    <button type="submit" class="btn-main"><i class="ti ti-check"></i> Update password</button>
  </form>
  <a class="back-link" href="login.php"><i class="ti ti-arrow-left"></i> Back to sign in</a>
<?php auth_page_end(); ?>
