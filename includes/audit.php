<?php
// ============================================================
//  includes/audit.php  —  Security / audit logging
//
//  audit_log($conn, $event, $outcome, [
//      'user_id'  => 12,          // account involved, if known
//      'username' => 'juan',      // what was typed / acting account
//      'role'     => 'student',
//      'reason'   => 'wrong_password',
//  ]);
//
//  Client IP, browser, OS and device type are captured
//  automatically from the current request.
//
//  event    : login | logout | recovery_request | recovery_link_issued |
//             recovery_completed | recovery_link_invalid |
//             recovery_dismissed | log_export | log_clear
//  outcome  : 'success' | 'failure'
//
//  Logging must NEVER break the page that calls it, so every
//  problem is swallowed (and sent to the PHP error log instead).
// ============================================================

/**
 * The address the request came from. Only REMOTE_ADDR is trusted:
 * X-Forwarded-For can be forged by anyone. If you ever put this app
 * behind a reverse proxy / CDN, resolve the real client IP here.
 */
function audit_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/** Turn a User-Agent string into device type / browser / OS. */
function audit_parse_ua(?string $ua): array {
    $ua = (string)$ua;
    if ($ua === '') {
        return ['device_type' => 'Unknown', 'browser' => 'Unknown', 'os' => 'Unknown'];
    }

    // Scripts, crawlers, headless tools
    if (preg_match('/(curl|wget|python-requests|python-urllib|aiohttp|okhttp|go-http-client|libwww|postman|insomnia|scrapy|headless|java\/|httpclient|bot|crawl|spider|slurp)/i', $ua, $m)) {
        return ['device_type' => 'Bot/Script', 'browser' => ucfirst(strtolower($m[1])), 'os' => 'Unknown'];
    }

    // OS
    $os = 'Unknown';
    if (preg_match('/Windows NT ([0-9.]+)/', $ua, $m)) {
        $win = ['10.0' => 'Windows 10/11', '6.3' => 'Windows 8.1', '6.2' => 'Windows 8', '6.1' => 'Windows 7'];
        $os  = $win[$m[1]] ?? 'Windows';
    } elseif (preg_match('/Android ([0-9]+)/', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (preg_match('/(iPhone|iPad|iPod).*? OS ([0-9]+)/', $ua, $m)) {
        $os = ($m[1] === 'iPad' ? 'iPadOS ' : 'iOS ') . $m[2];
    } elseif (strpos($ua, 'CrOS') !== false) {
        $os = 'ChromeOS';
    } elseif (strpos($ua, 'Mac OS X') !== false) {
        $os = 'macOS';
    } elseif (preg_match('/Linux|X11/', $ua)) {
        $os = 'Linux';
    }

    // Browser (order matters: Edge/Opera/Samsung also say "Chrome")
    $browser = 'Other';
    if (preg_match('/(?:Edg|EdgA|EdgiOS)\/([0-9]+)/', $ua, $m))            $browser = 'Edge ' . $m[1];
    elseif (preg_match('/(?:OPR|Opera)\/([0-9]+)/', $ua, $m))              $browser = 'Opera ' . $m[1];
    elseif (preg_match('/SamsungBrowser\/([0-9]+)/', $ua, $m))             $browser = 'Samsung Internet ' . $m[1];
    elseif (preg_match('/(?:Firefox|FxiOS)\/([0-9]+)/', $ua, $m))          $browser = 'Firefox ' . $m[1];
    elseif (preg_match('/(?:Chrome|CriOS)\/([0-9]+)/', $ua, $m))           $browser = 'Chrome ' . $m[1];
    elseif (preg_match('/Version\/([0-9]+).*Safari/', $ua, $m))            $browser = 'Safari ' . $m[1];
    elseif (preg_match('/(?:MSIE |Trident\/.*rv:)([0-9]+)/', $ua, $m))     $browser = 'Internet Explorer ' . $m[1];

    // Device type
    if (preg_match('/iPad|Tablet/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobi|iPhone|iPod|Android/i', $ua)) {
        $device = 'Mobile';
    } else {
        $device = 'Desktop';
    }

    return ['device_type' => $device, 'browser' => $browser, 'os' => $os];
}

function audit_trunc($v, int $max): ?string {
    if ($v === null) return null;
    $v = (string)$v;
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/** Write one audit row. Returns false (never throws) if it couldn't. */
function audit_log(mysqli $conn, string $event, string $outcome, array $ctx = []): bool {
    try {
        $ua  = audit_trunc($_SERVER['HTTP_USER_AGENT'] ?? '', 500);
        $dev = audit_parse_ua($ua);

        $event    = audit_trunc($event, 40);
        $outcome  = ($outcome === 'success') ? 'success' : 'failure';
        $user_id  = (isset($ctx['user_id']) && $ctx['user_id'] !== '' && $ctx['user_id'] !== null) ? (int)$ctx['user_id'] : null;
        $username = audit_trunc($ctx['username'] ?? null, 100);
        $role     = audit_trunc($ctx['role'] ?? null, 20);
        $ip       = audit_client_ip();
        $reason   = audit_trunc($ctx['reason'] ?? null, 255);
        $device   = $dev['device_type'];
        $browser  = audit_trunc($dev['browser'], 60);
        $os       = audit_trunc($dev['os'], 60);

        $stmt = $conn->prepare(
            "INSERT INTO audit_log
                (event_type, outcome, user_id, username, role, ip_address, user_agent, device_type, browser, os, reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('ssissssssss',
            $event, $outcome, $user_id, $username, $role, $ip, $ua, $device, $browser, $os, $reason
        );
        $stmt->execute();
        return true;
    } catch (Throwable $e) {
        error_log('[audit_log] could not write audit entry: ' . $e->getMessage());
        return false;
    }
}
