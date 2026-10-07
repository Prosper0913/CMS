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
