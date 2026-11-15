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
function contact_handle_post(mysqli $conn, array $u): array {
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

/** The "enter your code" card shown above the contact form while a change is pending. */
function contact_verify_card(?array $pending): void {
    if (!$pending) return;
    ?>
  <div class="card" style="margin-bottom:20px;border:1px solid var(--primary, #1e5f4e);">
    <p class="card-title"><i class="ti ti-shield-lock"></i> Confirm your change</p>
    <p style="font-size:13px;margin:0 0 12px;">Enter the 6-digit code we emailed to
      <strong><?php echo htmlspecialchars($pending['dest']); ?></strong>. Your new details are saved only after this step. The code expires in 10 minutes.</p>
    <form method="POST" autocomplete="off">
      <?php echo csrf_field(); ?>
      <div class="form-group">
        <input type="text" name="code" class="form-control" required maxlength="6" minlength="6" inputmode="numeric"
               pattern="\d{6}" autocomplete="one-time-code" placeholder="000000" style="letter-spacing:4px;font-weight:600;">
      </div>
      <button type="submit" name="verify_contact" value="1" class="btn btn-primary"><i class="ti ti-check"></i> Verify &amp; Save</button>
      <button type="submit" name="resend_contact" value="1" class="btn btn-ghost" formnovalidate>Resend code</button>
      <button type="submit" name="cancel_contact" value="1" class="btn btn-ghost" formnovalidate>Cancel</button>
    </form>
  </div>
<?php
}
