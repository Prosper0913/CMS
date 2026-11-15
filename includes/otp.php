<?php
// ============================================================
//  includes/otp.php  —  One-time password (email) helpers for
//  self-service account recovery. Used by forgot_password.php
//  (to request/send a code) and otp_verify.php (to check it).
//
//  Only the SHA-256 hash of a code is ever stored, the same
//  pattern used for the admin-issued reset links.
// ============================================================

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/audit.php';

const OTP_EXPIRY_SECONDS         = 600; // 10 minutes
const OTP_RESEND_COOLDOWN_SECONDS = 60; // 1 minute between sends

/**
 * Look up an account by username (students sign in with their Student ID
 * as username). Teachers/admins keep their contact email on users.email;
 * students keep theirs on students.email (set from their own Settings
 * page) instead, so this coalesces both rather than only checking users.
 */
function otp_lookup_user_by_identifier(mysqli $conn, string $identifier): ?array {
    $q = $conn->prepare(
        "SELECT u.id, u.username, u.role, u.display_name,
                COALESCE(u.email, s.email) AS email, u.student_id
         FROM users u
         LEFT JOIN students s ON s.student_id = u.student_id
         WHERE u.username = ? LIMIT 1"
    );
    $q->bind_param('s', $identifier);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    return $row ?: null;
}

/** Generate a zero-padded 6-digit code. */
function otp_generate_code(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Generate and email a one-time code for the given account, if it exists
 * and has an email on file, and it isn't still inside the resend cooldown.
 * Always safe to call even if none of that is true — every outcome is
 * logged to the audit trail but never surfaced to the caller's UI, so the
 * response to the person stays the same either way (no account enumeration).
 *
 * @return bool true only if an email was actually sent.
 */
function otp_request_and_send(mysqli $conn, string $identifier, string $purpose, string $ip): bool {
    $user = otp_lookup_user_by_identifier($conn, $identifier);
    if (!$user) {
        audit_log($conn, 'otp_request', 'failure', ['username' => $identifier, 'reason' => 'unknown_user']);
        return false;
    }
    if (empty($user['email'])) {
        audit_log($conn, 'otp_request', 'failure', [
            'user_id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'],
            'reason'  => 'no_email_on_file',
        ]);
        return false;
    }

    // Resend cooldown — look at the most recent code for this account/purpose.
    // Elapsed time is computed in SQL (TIMESTAMPDIFF), never by parsing a
    // MySQL datetime string with PHP's strtotime(): the two run in different
    // timezones here (MySQL's session zone vs. PHP's UTC default), and mixing
    // them silently produces a wrong elapsed time.
    $cd = $conn->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed
         FROM otp_codes WHERE user_id = ? AND purpose = ?
         ORDER BY created_at DESC LIMIT 1"
    );
    $cd->bind_param('is', $user['id'], $purpose);
    $cd->execute();
    $last = $cd->get_result()->fetch_assoc();
    if ($last && $last['elapsed'] !== null && (int)$last['elapsed'] < OTP_RESEND_COOLDOWN_SECONDS) {
        audit_log($conn, 'otp_request', 'failure', [
            'user_id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'],
            'reason'  => 'cooldown',
        ]);
        return false;
    }

    $code = otp_generate_code();
    $hash = hash('sha256', $code);

    // expires_at is computed by MySQL itself (DATE_ADD(NOW(), ...)) rather
    // than formatted in PHP, so it's always directly comparable to NOW()
    // later — same reasoning as the cooldown check above.
    $otpExpirySeconds = OTP_EXPIRY_SECONDS;
    $ins = $conn->prepare(
        "INSERT INTO otp_codes (user_id, purpose, destination, code_hash, expires_at, requested_ip)
         VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)"
    );
    $ins->bind_param('isssis', $user['id'], $purpose, $user['email'], $hash, $otpExpirySeconds, $ip);
    $ins->execute();

    $name = $user['display_name'] ?: $user['username'];
    $sent = send_otp_email($user['email'], $name, $code, (int)(OTP_EXPIRY_SECONDS / 60));
    if (!$sent) {
        $rid = (int)$ins->insert_id;
        $rm = $conn->prepare("DELETE FROM otp_codes WHERE id = ?");
        $rm->bind_param('i', $rid);
        $rm->execute();
    }

    audit_log($conn, 'otp_request', $sent ? 'success' : 'failure', [
        'user_id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'],
        'reason'  => $sent ? null : 'send_failed',
    ]);
    return $sent;
}

/**
 * Check a submitted code against the latest unconsumed code for this
 * account/purpose. On success the code is marked consumed (one-time use)
 * and the matching user row is returned so the caller can update the
 * password. On failure, a generic, user-facing message is returned —
 * never anything that reveals whether the account/code exists.
 *
 * @return array{ok:bool, user?:array, error?:string}
 */
function otp_verify_and_consume(mysqli $conn, string $identifier, string $purpose, string $code): array {
    $generic = "That code is invalid or has expired.";
    $user = otp_lookup_user_by_identifier($conn, $identifier);
    if (!$user) {
        return ['ok' => false, 'error' => $generic];
    }

    // Expiry is checked in SQL (expires_at > NOW(), plus an is_expired flag
    // for a merely-expired-but-otherwise-latest row) rather than by parsing
    // a MySQL datetime with PHP's strtotime() — see the note in
    // otp_request_and_send() about the timezone mismatch that causes.
    $q = $conn->prepare(
        "SELECT id, code_hash, attempts, max_attempts, (expires_at <= NOW()) AS is_expired
         FROM otp_codes
         WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL
         ORDER BY created_at DESC LIMIT 1"
    );
    $q->bind_param('is', $user['id'], $purpose);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();

    $ctx = ['user_id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];

    if (!$row) {
        audit_log($conn, 'otp_failed', 'failure', $ctx + ['reason' => 'no_active_code']);
        return ['ok' => false, 'error' => $generic];
    }
    if ((int)$row['is_expired'] === 1) {
        audit_log($conn, 'otp_failed', 'failure', $ctx + ['reason' => 'expired']);
        return ['ok' => false, 'error' => $generic];
    }
    if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
        audit_log($conn, 'otp_failed', 'failure', $ctx + ['reason' => 'max_attempts']);
        return ['ok' => false, 'error' => "Too many attempts for that code. Please request a new one."];
    }

    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $up = $conn->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?");
        $up->bind_param('i', $row['id']);
        $up->execute();
        audit_log($conn, 'otp_failed', 'failure', $ctx + ['reason' => 'wrong_code']);

        $remaining = (int)$row['max_attempts'] - (int)$row['attempts'] - 1;
        return ['ok' => false, 'error' => $remaining > 0
            ? "That code isn't right. {$remaining} attempt" . ($remaining === 1 ? '' : 's') . " left."
            : "That code isn't right. Please request a new one."];
    }

    $up = $conn->prepare("UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?");
    $up->bind_param('i', $row['id']);
    $up->execute();

    audit_log($conn, 'otp_verified', 'success', $ctx);
    return ['ok' => true, 'user' => $user];
}


// ============================================================
//  Verification codes for a SIGNED-IN user (first-login setup and
//  email/contact changes in Settings). Unlike the recovery helpers
//  above, the code is sent to a destination the caller chooses (the
//  new email being verified) and is checked by user id + destination,
//  so a code issued for one address can never confirm another.
// ============================================================
const OTP_USER_MAX_SENDS_PER_HOUR = 5;

/**
 * Email a fresh code to $destination for this signed-in user.
 * @return array{ok:bool, error?:string}  error is safe to show the user.
 */
function otp_send_to_destination(mysqli $conn, array $user, string $destination, string $purpose, string $purposeLine): array {
    $uid = (int)$user['id'];

    $cd = $conn->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed
         FROM otp_codes WHERE user_id = ? AND purpose = ?
         ORDER BY created_at DESC LIMIT 1"
    );
    $cd->bind_param('is', $uid, $purpose);
    $cd->execute();
    $last = $cd->get_result()->fetch_assoc();
    if ($last && $last['elapsed'] !== null && (int)$last['elapsed'] < OTP_RESEND_COOLDOWN_SECONDS) {
        $wait = OTP_RESEND_COOLDOWN_SECONDS - (int)$last['elapsed'];
        return ['ok' => false, 'error' => "Please wait {$wait} more second" . ($wait === 1 ? '' : 's') . " before requesting another code."];
    }

    $hr = $conn->prepare(
        "SELECT COUNT(*) AS c FROM otp_codes
         WHERE user_id = ? AND purpose = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)"
    );
    $hr->bind_param('is', $uid, $purpose);
    $hr->execute();
    if ((int)$hr->get_result()->fetch_assoc()['c'] >= OTP_USER_MAX_SENDS_PER_HOUR) {
        return ['ok' => false, 'error' => "Too many codes requested. Please try again in an hour."];
    }

    // Any older unused code for this purpose stops working.
    $kill = $conn->prepare("UPDATE otp_codes SET consumed_at = NOW() WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL");
    $kill->bind_param('is', $uid, $purpose);
    $kill->execute();

    $code = otp_generate_code();
    $hash = hash('sha256', $code);
    $secs = OTP_EXPIRY_SECONDS;
    $ip   = audit_client_ip();
    $ins = $conn->prepare(
        "INSERT INTO otp_codes (user_id, purpose, destination, code_hash, expires_at, requested_ip)
         VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)"
    );
    $ins->bind_param('isssis', $uid, $purpose, $destination, $hash, $secs, $ip);
    $ins->execute();

    $name = ($user['display_name'] ?? '') ?: ($user['username'] ?? '');
    // Release the PHP session lock while the (possibly slow) SMTP call runs. Otherwise every
    // other tab/request of this user queues behind this one and the whole site looks frozen.
    $hadSession = session_status() === PHP_SESSION_ACTIVE;
    if ($hadSession) session_write_close();
    $sent = send_otp_email($destination, (string)$name, $code, (int)(OTP_EXPIRY_SECONDS / 60), $purposeLine);
    if ($hadSession) @session_start();   // reload $_SESSION for the caller

    if (!$sent) {
        // An email that never left must not use up the cooldown / hourly allowance.
        $rid = (int)$ins->insert_id;
        $rm = $conn->prepare("DELETE FROM otp_codes WHERE id = ?");
        $rm->bind_param('i', $rid);
        $rm->execute();
    }
    audit_log($conn, 'otp_request', $sent ? 'success' : 'failure', [
        'user_id' => $uid, 'username' => $user['username'] ?? null, 'role' => $user['role'] ?? null,
        'reason'  => $sent ? $purpose : $purpose . ':send_failed',
    ]);
    if (!$sent) {
        return ['ok' => false, 'error' => "We couldn't send the email right now. Check the address and try again in a moment."];
    }
    return ['ok' => true];
}

/**
 * Check a code for this signed-in user, purpose AND destination. Burns it on success.
 * @return array{ok:bool, error?:string}
 */
function otp_check_for_user(mysqli $conn, array $user, string $destination, string $purpose, string $code): array {
    $uid = (int)$user['id'];
    $generic = "That code is invalid or has expired.";
    $q = $conn->prepare(
        "SELECT id, code_hash, destination, attempts, max_attempts, (expires_at <= NOW()) AS is_expired
         FROM otp_codes
         WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL
         ORDER BY created_at DESC LIMIT 1"
    );
    $q->bind_param('is', $uid, $purpose);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $ctx = ['user_id' => $uid, 'username' => $user['username'] ?? null, 'role' => $user['role'] ?? null];

    if (!$row || (int)$row['is_expired'] === 1 || strcasecmp((string)$row['destination'], $destination) !== 0) {
        audit_log($conn, 'otp_failed', 'failure', $ctx + ['reason' => $purpose . ':no_active_code']);
        return ['ok' => false, 'error' => $generic];
    }
    if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
        audit_log($conn, 'otp_failed', 'failure', $ctx + ['reason' => $purpose . ':max_attempts']);
        return ['ok' => false, 'error' => "Too many attempts for that code. Please request a new one."];
    }
    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $up = $conn->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?");
        $up->bind_param('i', $row['id']);
        $up->execute();
        audit_log($conn, 'otp_failed', 'failure', $ctx + ['reason' => $purpose . ':wrong_code']);
        $remaining = (int)$row['max_attempts'] - (int)$row['attempts'] - 1;
        return ['ok' => false, 'error' => $remaining > 0
            ? "That code isn't right. {$remaining} attempt" . ($remaining === 1 ? '' : 's') . " left."
            : "That code isn't right. Please request a new one."];
    }
    $up = $conn->prepare("UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?");
    $up->bind_param('i', $row['id']);
    $up->execute();
    audit_log($conn, 'otp_verified', 'success', $ctx + ['reason' => $purpose]);
    return ['ok' => true];
}

/** Is email verification switched on? (config/secrets.php can set REQUIRE_EMAIL_VERIFICATION to false as an emergency valve if SMTP is down.) */
function otp_verification_required(): bool {
    $f = __DIR__ . '/../config/secrets.php';
    if (is_file($f)) require_once $f;
    return !defined('REQUIRE_EMAIL_VERIFICATION') || (bool)REQUIRE_EMAIL_VERIFICATION;
}
