<?php
// ============================================================
//  includes/login_throttle.php  —  Brute-force protection for sign-in.
//
//  Counts recent FAILED sign-ins in audit_log (no extra table) and
//  refuses further attempts for a while. Limits are deliberately
//  generous per IP because a whole class often shares one school
//  network address:
//    - same username from the same IP : 5 failures / 15 min
//    - same username from anywhere    : 15 failures / 15 min
//    - one IP, any usernames          : 60 failures / 15 min
//  While blocked, the password is NOT checked at all, and blocked
//  attempts are not counted (so the lock ends 15 min after the last
//  real failure instead of being extended by every retry).
// ============================================================
require_once __DIR__ . '/audit.php';

const LOGIN_WINDOW_MINUTES   = 15;
const LOGIN_MAX_USER_IP      = 5;
const LOGIN_MAX_USER_ANY     = 15;
const LOGIN_MAX_IP           = 60;

/** @return string|null  a user-facing message when blocked, else null */
function login_throttle_check(mysqli $conn, string $username, string $ip): ?string {
    try {
        $w = LOGIN_WINDOW_MINUTES;
        $q = $conn->prepare(
            "SELECT
               SUM(username = ? AND ip_address = ?) AS user_ip,
               SUM(username = ?)                    AS user_any,
               COUNT(*)                             AS ip_any
             FROM audit_log
             WHERE event_type = 'login' AND outcome = 'failure'
               AND reason IN ('wrong_password','unknown_user')
               AND created_at >= (NOW() - INTERVAL {$w} MINUTE)
               AND (ip_address = ? OR username = ?)"
        );
        $q->bind_param('sssss', $username, $ip, $username, $ip, $username);
        $q->execute();
        $r = $q->get_result()->fetch_assoc() ?: [];
        // ip_any above also counts other IPs' rows for this username, so recount IP-only precisely:
        $q2 = $conn->prepare(
            "SELECT COUNT(*) AS c FROM audit_log
             WHERE event_type = 'login' AND outcome = 'failure'
               AND reason IN ('wrong_password','unknown_user')
               AND created_at >= (NOW() - INTERVAL {$w} MINUTE) AND ip_address = ?"
        );
        $q2->bind_param('s', $ip);
        $q2->execute();
        $ipCount = (int)($q2->get_result()->fetch_assoc()['c'] ?? 0);

        if ((int)($r['user_ip'] ?? 0)  >= LOGIN_MAX_USER_IP ||
            (int)($r['user_any'] ?? 0) >= LOGIN_MAX_USER_ANY ||
            $ipCount                   >= LOGIN_MAX_IP) {
            return "Too many failed sign-in attempts. Please wait {$w} minutes and try again, "
                 . "or use “Forgot password” if you can't remember it.";
        }
    } catch (Throwable $e) {
        error_log('[login_throttle] ' . $e->getMessage());   // never block sign-in because of a logging problem
    }
    return null;
}
