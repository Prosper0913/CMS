<?php
// ============================================================
//  includes/contact_verify.php
//  Shared by teacher/settings.php, student/settings.php and
//  admin/settings.php: changing the email or contact number only
//  takes effect after the person types a 6-digit code that was
//  emailed to them.
//    - email changed  -> code goes to the NEW address (proves it is theirs)
//    - only the phone -> code goes to the email already on file
//  Nothing is written to the account until the code is accepted.
// ============================================================
require_once __DIR__ . '/otp.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/csrf.php';

const CONTACT_PURPOSE = 'contact_update';

function contact_email_error(string $e): string {
    if ($e === '') return "Please enter your email address — it is how you recover your account.";
    if (strlen($e) > 120 || !filter_var($e, FILTER_VALIDATE_EMAIL)) return "Please enter a valid email address.";
    return '';
}
function contact_number_error(string $c): string {
    if ($c === '') return '';
    $digits = strlen(preg_replace('/\D/', '', $c));
    if (strlen($c) > 20 || !preg_match('/^\+?[0-9\s\-()]+$/', $c) || $digits < 7 || $digits > 15) {
        return "Contact number must be 7-15 digits (spaces, dashes, brackets and a leading + are allowed).";
    }
    return '';
}

/** Write the verified values. $u needs id, role, student_id. */
function contact_apply(mysqli $conn, array $u, string $email, ?string $contact): bool {
    $conn->begin_transaction();
    try {
        $a = $conn->prepare("UPDATE users SET email = ?, contact_number = ?, email_verified_at = NOW() WHERE id = ?");
        $a->bind_param('ssi', $email, $contact, $u['id']);
        $a->execute();
        if (!empty($u['student_id'])) {
            $b = $conn->prepare("UPDATE students SET email = ?, contact_number = ? WHERE student_id = ?");
            $b->bind_param('sss', $email, $contact, $u['student_id']);
            $b->execute();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[contact_verify] ' . $e->getMessage());
        return false;
    }
    audit_log($conn, 'profile_update', 'success', [
        'user_id' => $u['id'], 'username' => $u['username'] ?? '', 'role' => $u['role'] ?? '',
        'reason'  => 'updated email/contact number (email code verified)',
    ]);
    return true;
}

/**
 * Handle the settings-page POST actions. $u: id, username, role, display_name,
 * student_id (or null), email (current), contact_number (current).
 * @return array{success:string,error:string,pending:?array,applied:?array}
 *   pending = ['email','contact','dest'] while a code is awaited;
 *   applied = ['email','contact'] right after a successful save.
 */
function _contact_handle_post_inner(mysqli $conn, array $u): array {
    $key = 'pending_contact_' . (int)$u['id'];
    $out = ['success' => '', 'error' => '', 'pending' => $_SESSION[$key] ?? null, 'applied' => null];
    $purposeLine = 'confirm the email / contact details on your Classroom CMS account';

    if (isset($_POST['cancel_contact'])) {
        unset($_SESSION[$key]);
        $out['pending'] = null;
        return $out;
    }

    if (isset($_POST['update_profile'])) {
        $email   = trim((string)($_POST['email'] ?? ''));
        $contact = trim((string)($_POST['contact_number'] ?? ''));
        $curEmail   = trim((string)($u['email'] ?? ''));
        $curContact = trim((string)($u['contact_number'] ?? ''));

        if (($e = contact_email_error($email)) !== '')        { $out['error'] = $e; return $out; }
        if (($e = contact_number_error($contact)) !== '')     { $out['error'] = $e; return $out; }

        $emailChanged   = strcasecmp($email, $curEmail) !== 0;
        $contactChanged = $contact !== $curContact;
        if (!$emailChanged && !$contactChanged) { $out['success'] = "No changes to save."; return $out; }

        $contact_db = $contact !== '' ? $contact : null;
        if (!otp_verification_required()) {          // emergency valve (SMTP down)
            if (contact_apply($conn, $u, $email, $contact_db)) {
                $out['success'] = "Contact information updated.";
                $out['applied'] = ['email' => $email, 'contact' => $contact_db];
            } else $out['error'] = "Something went wrong. Please try again.";
            return $out;
        }

        $dest = $emailChanged ? $email : $curEmail;
        $r = otp_send_to_destination($conn, $u, $dest, CONTACT_PURPOSE, $purposeLine);
        if (!$r['ok']) { $out['error'] = $r['error']; return $out; }
        $_SESSION[$key] = $out['pending'] = ['email' => $email, 'contact' => $contact_db, 'dest' => $dest];
        $out['success'] = "We sent a 6-digit code to {$dest}. Enter it below to save your changes.";
        return $out;
    }

    if ($out['pending'] && isset($_POST['resend_contact'])) {
        $r = otp_send_to_destination($conn, $u, $out['pending']['dest'], CONTACT_PURPOSE, $purposeLine);
        if ($r['ok']) $out['success'] = "A new code was sent to {$out['pending']['dest']}."; else $out['error'] = $r['error'];
        return $out;
    }

    if ($out['pending'] && isset($_POST['verify_contact'])) {
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) { $out['error'] = "Enter the 6-digit code from your email."; return $out; }
        $p = $out['pending'];
        $r = otp_check_for_user($conn, $u, $p['dest'], CONTACT_PURPOSE, $code);
        if (!$r['ok']) { $out['error'] = $r['error']; return $out; }
        if (contact_apply($conn, $u, $p['email'], $p['contact'])) {
            unset($_SESSION[$key]);
            $out['pending'] = null;
            $out['success'] = "Contact information updated and verified.";
            $out['applied'] = ['email' => $p['email'], 'contact' => $p['contact']];
        } else {
            $out['error'] = "Something went wrong and nothing was saved. Please try again.";
        }
    }
    return $out;
}

/**
 * Public entry point. Any unexpected failure (for example a database that has not
 * had migrations/security_hardening.sql applied yet) is logged and shown as a
 * normal error message instead of a blank 500 page.
 */
function contact_handle_post(mysqli $conn, array $u): array {
    try {
        return _contact_handle_post_inner($conn, $u);
    } catch (Throwable $e) {
        error_log('[contact_verify] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return [
            'success' => '',
            'error'   => "We couldn't process that right now. Please try again in a moment; if it keeps happening, tell your administrator.",
            'pending' => $_SESSION['pending_contact_' . (int)$u['id']] ?? null,
            'applied' => null,
        ];
    }
}

/** Shared red "code required" styling (printed once per page). */
function contact_otp_css(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<style>
  .otp-required{border:2px solid #dc2626 !important;background:rgba(220,38,38,.06);animation:otpPulse 2s ease-in-out infinite;}
  .otp-required .form-control{border-color:#dc2626 !important;}
  .otp-required .form-control:focus{outline:2px solid rgba(220,38,38,.35);}
  .otp-tag{display:inline-flex;align-items:center;gap:6px;background:#dc2626;color:#fff;font-size:11.5px;font-weight:700;
           letter-spacing:.06em;text-transform:uppercase;padding:4px 10px;border-radius:999px;margin-bottom:10px;}
  @keyframes otpPulse{0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.35)}50%{box-shadow:0 0 0 7px rgba(220,38,38,0)}}
  @media (prefers-reduced-motion: reduce){.otp-required{animation:none;}}
  .locked-email-wrap{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .locked-email-wrap input[readonly]{background:rgba(127,127,127,.12);cursor:not-allowed;flex:1;min-width:200px;}
  .verified-badge{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#15803d;font-weight:600;}
  .btn-changeEmail{  background: transparent;border: solid 1px var(--bg2);color: var(--text7);}
  .btn-changeEmail:hover {color: var(--red);
}
</style>
<?php
}

/**
 * The email field for a settings form: locked (read-only, with a lock icon and a
 * Verified tag) once an address is on file. "Change email" unlocks it; saving a
 * different address still needs an emailed code.
 */
function contact_email_field(string $email, bool $verified): void {
    contact_otp_css();
    $has = trim($email) !== '';
    ?>
      <div class="form-group">
        <label><?php echo $has ? '<i class="ti ti-lock"></i> ' : ''; ?>Gmail / Email
          <?php if ($has && $verified): ?><span class="verified-badge"><i class="ti ti-circle-check"></i> Verified</span><?php endif; ?></label>
        <div class="locked-email-wrap">
          <input type="email" name="email" id="contactEmailInput" class="form-control" placeholder="you@gmail.com" required
                 maxlength="120" value="<?php echo htmlspecialchars($email); ?>" <?php echo $has ? 'readonly' : ''; ?>>
          <?php if ($has): ?>
            <button type="button" class="btn btn-changeEmail btn-sm" id="contactEmailUnlock"
                    onclick="var i=document.getElementById('contactEmailInput');i.removeAttribute('readonly');i.focus();i.select();this.remove();document.getElementById('contactEmailHint').style.display='block';">
              <i class="ti ti-pencil"></i> Change email
            </button>
          <?php endif; ?>
        </div>
        <?php if ($has): ?>
          <div id="contactEmailHint" style="display:none;font-size:12px;margin-top:4px;">We'll send a code to the new address before saving it.</div>
        <?php endif; ?>
      </div>
<?php
}

/** Is this account's email confirmed by a code? (false if the column is missing.) */
function contact_is_verified(mysqli $conn, int $userId): bool {
    try {
        $q = $conn->prepare("SELECT email_verified_at FROM users WHERE id = ? LIMIT 1");
        $q->bind_param('i', $userId);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        return !empty($r['email_verified_at']);
    } catch (Throwable $e) {
        return false;
    }
}

/** The "enter your code" card shown above the contact form while a change is pending. */
function contact_verify_card(?array $pending): void {
    if (!$pending) return;
    contact_otp_css();
    ?>
  <div class="card otp-required" style="margin-bottom:20px;">
    <span class="otp-tag"><i class="ti ti-alert-triangle"></i> Code required</span>
    <p class="card-title"><i class="ti ti-shield-lock"></i> Enter your verification code</p>
    <p style="font-size:13px;margin:0 0 12px;">Your changes are <strong>not saved yet</strong>. Enter the 6-digit code we emailed to
      <strong><?php echo htmlspecialchars($pending['dest']); ?></strong>. It expires in 10 minutes.</p>
    <form method="POST" autocomplete="off">
      <?php echo csrf_field(); ?>
      <div class="form-group">
        <input type="text" name="code" class="form-control" required maxlength="6" minlength="6" inputmode="numeric"
               pattern="\d{6}" autocomplete="one-time-code" placeholder="000000" autofocus style="letter-spacing:4px;font-weight:600;">
      </div>
      <button type="submit" name="verify_contact" value="1" class="btn btn-primary"><i class="ti ti-check"></i> Verify &amp; Save</button>
      <button type="submit" name="resend_contact" value="1" class="btn btn-ghost" formnovalidate>Resend code</button>
      <button type="submit" name="cancel_contact" value="1" class="btn btn-ghost" formnovalidate>Cancel</button>
    </form>
  </div>
<?php
}
