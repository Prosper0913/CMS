<?php
// ============================================================
//  api/bio_enroll.php
//  Receives a 512-byte AS608 fingerprint template from the ESP32
//  and stores it in fingerprint_templates.
//
//  POST /classroom/api/bio_enroll.php
//  Body (x-www-form-urlencoded or JSON):
//    device_key   = DEVICE_KEY_HERE
//    student_id   = "2024-001"
//    template_b64 = base64-encoded 512-byte AS608 template
//
//  Response:
//    { "status":"ok",    "student":"Ana Reyes" }
//    { "status":"error", "message":"..." }
// ============================================================

header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Accept POST body (form or raw JSON) ───────────────────────
$raw = file_get_contents('php://input');
$data = [];
if (!empty($raw)) {
    $data = json_decode($raw, true) ?? [];
}
// Merge $_POST so both content types work
$data = array_merge($_POST, $data);

$device_key   = trim($data['device_key']   ?? '');
$student_id   = trim($data['student_id']   ?? '');
$template_b64 = trim($data['template_b64'] ?? '');

// ── Validate device key ───────────────────────────────────────
if ($device_key === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing device_key']);
    exit;
}
$dq = $conn->prepare("SELECT id, subject_id FROM bio_devices WHERE device_key=? LIMIT 1");
$dq->bind_param('s', $device_key);
$dq->execute();
$device = $dq->get_result()->fetch_assoc();

if (!$device) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unknown device']);
    exit;
}

// Update last_seen
$upd = $conn->prepare("UPDATE bio_devices SET last_seen=NOW() WHERE id=?");
$upd->bind_param('i', $device['id']);
$upd->execute();

// ── Validate inputs ───────────────────────────────────────────
if ($student_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'Missing student_id']);
    exit;
}
if ($template_b64 === '') {
    echo json_encode(['status' => 'error', 'message' => 'Missing template_b64']);
    exit;
}

// Decode and verify size
// AS608 createModel → UpChar(CharBuffer1) gives 512 bytes.
// Some firmware variants give 256. Accept both.
$template_raw = base64_decode($template_b64, true);
if ($template_raw === false) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid base64 in template']);
    exit;
}
$template_len = strlen($template_raw);
if ($template_len < 256 || $template_len > 512) {
    echo json_encode([
        'status'  => 'error',
        'message' => "Template size invalid: got {$template_len} bytes (expected 256-512)",
    ]);
    exit;
}

// ── Verify student exists ─────────────────────────────────────
$sq = $conn->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_id=? LIMIT 1");
$sq->bind_param('s', $student_id);
$sq->execute();
$student = $sq->get_result()->fetch_assoc();

if (!$student) {
    echo json_encode(['status' => 'error', 'message' => "Student '{$student_id}' not found"]);
    exit;
}

$display = $student['first_name'] . ' ' . $student['last_name'];

// ── Upsert template (INSERT or UPDATE if already enrolled) ────
$ins = $conn->prepare(
    "INSERT INTO fingerprint_templates (student_id, template_b64, subject_id)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE
       template_b64 = VALUES(template_b64),
       subject_id   = VALUES(subject_id),
       updated_at   = NOW()"
);
$ins->bind_param('ssi', $student_id, $template_b64, $device['subject_id']);
if (!$ins->execute()) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    exit;
}

// ── Log the enrollment ────────────────────────────────────────
$ll = $conn->prepare(
    "INSERT INTO biometric_log (device_id, student_id, subject_id, scanned_at, ip_address, status, message)
     VALUES (?, ?, ?, NOW(), ?, 'enrolled', ?)"
);
$ip  = $_SERVER['REMOTE_ADDR'] ?? '';
$msg = "Template enrolled for {$display}";
$ll->bind_param('iisss', $device['id'], $student_id, $device['subject_id'], $ip, $msg);
$ll->execute();

echo json_encode([
    'status'  => 'ok',
    'student' => $display,
    'message' => "Fingerprint enrolled for {$display}",
]);