<?php
// ============================================================
//  first_login.php
//  Mandatory first-sign-in setup for accounts that were given a
//  password by someone else (new students, new teachers, and any
//  account whose password an admin reset):
//    1. choose a personal password
//    2. give a Gmail address, and confirm it with a 6-digit code
//  Until this is done, includes/auth.php sends the user back here
//  from every other page (users.must_change_password = 1).
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/auth_page.php';
require_once __DIR__ . '/includes/otp.php';

const FL_PURPOSE = 'first_login';
const FL_MIN_PASSWORD = 8;

function fl_home(string $role): string {
    return match ($role) {
        'teacher' => '/classroomv2/teacher/dashboard.php',
        'admin'   => '/classroomv2/admin/dashboard.php',
        default   => '/classroomv2/student/dashboard.php',
    };
}

$uid = (int)$_SESSION['user_id'];
$q = $conn->prepare("SELECT id, username, role, display_name, password, student_id, must_change_password FROM users WHERE id = ? LIMIT 1");
$q->bind_param('i', $uid);
$q->execute();
$user = $q->get_result()->fetch_assoc();
if (!$user) { header('Location: /classroomv2/logout.php'); exit; }

// Nothing to do (already set up) → straight to the dashboard.
if ((int)$user['must_change_password'] !== 1) {
    $_SESSION['must_change_password'] = 0;
    unset($_SESSION['first_login']);
    header('Location: ' . fl_home($user['role']));
    exit;
}

function fl_is_gmail(string $e): bool {
    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/@(gmail|googlemail)\.com$/i', $e) === 1;
}

/** Write the new password + verified email, clear the flag, and go to the dashboard. */
function fl_complete(mysqli $conn, array $user, string $hash, string $email): bool {
    $conn->begin_transaction();
    try {
        $u = $conn->prepare("UPDATE users SET password = ?, email = ?, email_verified_at = NOW(), must_change_password = 0 WHERE id = ?");
        $u->bind_param('ssi', $hash, $email, $user['id']);
        $u->execute();
        if (!empty($user['student_id'])) {
            $s = $conn->prepare("UPDATE students SET password = ?, email = ? WHERE student_id = ?");
            $s->bind_param('sss', $hash, $email, $user['student_id']);
            $s->execute();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[first_login] ' . $e->getMessage());
        return false;
    }
    audit_log($conn, 'password_change', 'success', [
        'user_id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'],
        'reason'  => 'first-login setup (new password + verified email)',
    ]);
    $_SESSION['must_change_password'] = 0;
    unset($_SESSION['first_login']);
    session_regenerate_id(true);
    return true;
}

$error  = '';
$notice = '';
$pending = $_SESSION['first_login'] ?? null;   // ['hash' => ..., 'email' => ...] after step 1
$action  = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'restart') {
        unset($_SESSION['first_login']);
        $pending = null;
    } elseif ($action === 'save') {
        $pw1   = trim((string)($_POST['new_password'] ?? ''));
        $pw2   = trim((string)($_POST['confirm_password'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));

        if (strlen($pw1) < FL_MIN_PASSWORD) {
            $error = "Your new password must be at least " . FL_MIN_PASSWORD . " characters.";
        } elseif ($pw1 !== $pw2) {
            $error = "The two passwords don't match.";
        } elseif (password_verify($pw1, $user['password'])) {
            $error = "Please choose a new password — it can't be the one you were given.";
        } elseif (strcasecmp($pw1, (string)$user['username']) === 0 || strcasecmp($pw1, (string)$user['student_id']) === 0) {
            $error = "Your password can't be the same as your username or Student ID.";
        } elseif (!fl_is_gmail($email)) {
            $error = "Please enter a valid Gmail address (example: yourname@gmail.com).";
        } else {
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            if (!otp_verification_required()) {
                if (fl_complete($conn, $user, $hash, $email)) { header('Location: ' . fl_home($user['role'])); exit; }
                $error = "Something went wrong and your account was not updated. Please try again.";
            } else {
                $r = otp_send_to_destination($conn, $user, $email, FL_PURPOSE, 'confirm your email address and finish setting up your Classroom CMS account');
                if ($r['ok']) {
                    $_SESSION['first_login'] = $pending = ['hash' => $hash, 'email' => $email];
                    $notice = "We sent a 6-digit code to {$email}.";
                } else {
                    $error = $r['error'];
                }
            }
        }
    } elseif ($pending && $action === 'resend') {
        $r = otp_send_to_destination($conn, $user, $pending['email'], FL_PURPOSE, 'confirm your email address and finish setting up your Classroom CMS account');
        if ($r['ok']) $notice = "A new code was sent to {$pending['email']}."; else $error = $r['error'];
    } elseif ($pending && $action === 'verify') {
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = "Enter the 6-digit code from your email.";
        } else {
            $r = otp_check_for_user($conn, $user, $pending['email'], FL_PURPOSE, $code);
            if (!$r['ok']) {
                $error = $r['error'];
            } elseif (fl_complete($conn, $user, $pending['hash'], $pending['email'])) {
                header('Location: ' . fl_home($user['role']));
                exit;
            } else {
                $error = "Something went wrong and your account was not updated. Please try again.";
            }
        }
    }
}

$display = $user['display_name'] ?: $user['username'];
auth_page_start('Set up your account', 'Welcome, ' . $display, $pending ? 'Confirm your email' : 'Secure your account');
?>
  <?php if ($error): ?>
    <div class="alert err"><i class="ti ti-alert-circle"></i> <?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($notice): ?>
    <div class="alert ok"><i class="ti ti-circle-check"></i> <?php echo htmlspecialchars($notice); ?></div>
  <?php endif; ?>

<?php if (!$pending): ?>
  <p class="lead">
    Because this is your first time signing in, please choose your own password and add the Gmail
    address you actually use. We'll email you a code to confirm it. The email is also how you recover
    your account if you ever forget your password.
  </p>
  <form method="POST" autocomplete="off">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <div class="form-group">
      <label>New password</label>
      <div class="input-wrap"><i class="ti ti-lock"></i>
        <input type="password" name="new_password" required minlength="<?php echo FL_MIN_PASSWORD; ?>" autocomplete="new-password" placeholder="At least <?php echo FL_MIN_PASSWORD; ?> characters" autofocus></div>
    </div>
    <div class="form-group">
      <label>Confirm new password</label>
      <div class="input-wrap"><i class="ti ti-lock-check"></i>
        <input type="password" name="confirm_password" required minlength="<?php echo FL_MIN_PASSWORD; ?>" autocomplete="new-password" placeholder="Type it again"></div>
    </div>
    <div class="form-group">
      <label>Gmail address</label>
      <div class="input-wrap"><i class="ti ti-mail"></i>
        <input type="email" name="email" required maxlength="120" autocomplete="email" placeholder="yourname@gmail.com"></div>
      <div class="hint">Must be a @gmail.com address you can open right now.</div>
    </div>
    <button type="submit" class="btn-main"><i class="ti ti-send"></i> Send verification code</button>
  </form>
<?php else: ?>
  <p class="lead">
    Enter the 6-digit code we sent to <strong><?php echo htmlspecialchars($pending['email']); ?></strong>.
    It expires in 10 minutes. Check your spam folder if you don't see it.
  </p>
  <form method="POST" autocomplete="off">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="verify">
    <div class="form-group">
      <label>6-digit code</label>
      <div class="input-wrap"><i class="ti ti-shield-lock"></i>
        <input type="text" name="code" required maxlength="6" minlength="6" inputmode="numeric" pattern="\d{6}"
               autocomplete="one-time-code" placeholder="000000" autofocus style="letter-spacing:4px;font-weight:600;"></div>
    </div>
    <button type="submit" class="btn-main"><i class="ti ti-check"></i> Confirm &amp; continue</button>
  </form>
  <form method="POST" style="margin-top:12px;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="resend">
    <button type="submit" class="btn-main" style="background:transparent;color:var(--school-bg);border:1px solid var(--school-bg);width:100%;">Resend code</button>
  </form>
  <form method="POST">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="restart">
    <button type="submit" class="back-link" style="background:none;border:none;width:100%;cursor:pointer;">Wrong email? Start over</button>
  </form>
<?php endif; ?>

  <a class="back-link" href="/classroomv2/logout.php">Sign out</a>
<?php auth_page_end();
