<?php
// ============================================================
//  api/_api_common.php
//  Shared plumbing for external-facing API endpoints. NOT
//  session-based — external systems authenticate with an API key
//  sent in the X-API-Key header, checked against the api_keys
//  table (hash comparison, plaintext key is never stored). Every
//  request, success or failure, is written to api_request_log.
//
//  ── MULTI-SYSTEM DESIGN ──────────────────────────────────────
//  This is NOT hardcoded to any one integrated system (e.g. the
//  FPST Inventory System). Instead, each API key carries its own
//  `allowed_course` (set on admin/api_keys.php when the key is
//  generated). A key can only ever read/write data for sections
//  tagged with that exact course — enforced generically below by
//  authorize_course() / require_authorized_section(), which every
//  endpoint (masterlist.php, borrowed_equipment.php) calls.
//
//  TO ONBOARD A NEW INTEGRATED SYSTEM (e.g. a library system for
//  course "BSIT", a second inventory system for "BSCS", etc.):
//    1. Go to admin/api_keys.php and generate a new key, entering
//       that system's course in the "Allowed Course" field.
//    2. Hand that system the generated key.
//    3. Done. Nothing in this file, masterlist.php, or
//       borrowed_equipment.php needs to change — they all read the
//       course restriction from the key itself, not from a literal
//       string in the code.
//
//  If a key should be able to read/write ANY course (a fully
//  trusted internal integration), leave "Allowed Course" blank
//  when generating it — allowed_course NULL/empty means
//  unrestricted. Use sparingly; prefer scoping every partner
//  integration to exactly the course it needs.
// ============================================================
require_once __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json');

function log_api_request($conn, $endpoint, $api_key_id, $success, $message = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $succ = $success ? 1 : 0;
    try {
        $stmt = $conn->prepare(
            "INSERT INTO api_request_log (api_key_id, endpoint, ip_address, success, message) VALUES (?,?,?,?,?)"
        );
        $stmt->bind_param('isssi', $api_key_id, $endpoint, $ip, $succ, $message);
        $stmt->execute();
    } catch (Throwable $e) {
        // never let logging itself break the request
    }
}

function api_fail($code, $message, $conn = null, $endpoint = '', $api_key_id = null) {
    http_response_code($code);
    if ($conn) log_api_request($conn, $endpoint, $api_key_id, false, $message);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Reads X-API-Key from the request, validates it against api_keys,
// updates last_used_at, logs the call, and returns the key row
// (including allowed_course, used by authorize_course() below).
// Ends the request with a 401 JSON error if missing/invalid/revoked.
function authenticate_api_key($conn, $endpoint) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $provided = $headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    if (!$provided) {
        api_fail(401, 'Missing X-API-Key header.', $conn, $endpoint, null);
    }

    $hash = hash('sha256', $provided);
    $stmt = $conn->prepare(
        "SELECT id, client_name, is_active, allowed_course FROM api_keys WHERE key_hash = ? LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        api_fail(401, 'Invalid API key.', $conn, $endpoint, null);
    }
    if (!$row['is_active']) {
        api_fail(401, 'This API key has been revoked.', $conn, $endpoint, $row['id']);
    }

    $upd = $conn->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();

    log_api_request($conn, $endpoint, $row['id'], true, 'OK');
    return $row;
}

// ── Course-scoped access control ────────────────────────────
// Checks a course value (e.g. a section's course, or a requested
// ?course= param) against THIS KEY's allowed_course. Blank/NULL
// allowed_course = unrestricted key, allowed everywhere. Otherwise
// the match must be exact (case-insensitive, trimmed).
//
// >>> This is the ONE function that enforces "which system can see
// >>> which course's data" — it never mentions any specific course
// >>> by name. Add a new integrated system by giving its key an
// >>> allowed_course value on admin/api_keys.php, not by editing
// >>> this function.
function authorize_course($key, $course_value, $endpoint, $conn) {
    $allowed = trim((string)($key['allowed_course'] ?? ''));
    if ($allowed === '') return true; // unrestricted key
    if (strcasecmp($allowed, trim((string)$course_value)) !== 0) {
        api_fail(
            403,
            "This API key is only authorized for the \"$allowed\" course.",
            $conn, $endpoint, $key['id']
        );
    }
    return true;
}

// Confirms a section exists AND the requesting key is authorized
// for that section's course (via authorize_course() above). Used
// by both masterlist.php and borrowed_equipment.php so a key can
// never pull/push data outside its own course, even if a caller
// guesses a valid section_id.
function require_authorized_section($conn, $key, $section_id, $endpoint) {
    $stmt = $conn->prepare("SELECT id, section_name, course FROM sections WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $section_id);
    $stmt->execute();
    $section = $stmt->get_result()->fetch_assoc();
    if (!$section) {
        api_fail(404, 'Section not found.', $conn, $endpoint, $key['id']);
    }
    authorize_course($key, $section['course'], $endpoint, $conn);
    return $section;
}
