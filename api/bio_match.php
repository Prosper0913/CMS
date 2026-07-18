<?php
// ============================================================
//  api/bio_match.php
//  Step 1 of 2 for attendance.
//
//  ESP32 scans a finger → extracts 256-byte feature (CharBuffer1)
//  → POSTs it here as base64.
//
//  This endpoint returns ALL enrolled templates for every student
//  in the subject bound to this device. The ESP32 then does the
//  1:N matching locally on the sensor (DownChar + Match loop).
//
//  POST /classroom/api/bio_match.php
//  Body:
//    device_key  = DEVICE_KEY_HERE
//    probe_b64   = base64 of 256-byte feature from CharBuffer1
//    date        = YYYY-MM-DD   (from RTC)
//    time        = HH:MM:SS     (from RTC)
//
//  Response:
//    {
//      "status"      : "ok",
//      "subject_id"  : 5,
//      "late_cutoff" : "08:15:00",
//      "templates"   : [
//          { "student_id":"2024-001", "name":"Ana Reyes",  "template_b64":"..." },
//          { "student_id":"2024-002", "name":"Ben Santos", "template_b64":"..." },
//          ...
//      ]
//    }
//
//  NOTE: The probe_b64 (256-byte feature) is NOT used server-side
//  for matching — the AS608 Match command is hardware-only and
//  runs on the ESP32. We accept it here only to log the attempt
//  and for future software-matching if needed.
// ============================================================

header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Parse body ────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = [];
if (!empty($raw)) {
    $data = json_decode($raw, true) ?? [];
}
$data = array_merge($_POST, $data);

$device_key = trim($data['device_key'] ?? '');
$probe_b64  = trim($data['probe_b64']  ?? '');
$scan_date  = $data['date'] ?? date('Y-m-d');
$scan_time  = $data['time'] ?? date('H:i:s');
$ip         = $_SERVER['REMOTE_ADDR'] ?? '';

// Validate formats
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scan_date)) $scan_date = date('Y-m-d');
if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $scan_time))  $scan_time = date('H:i:s');

// ── Authenticate device ───────────────────────────────────────
if ($device_key === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing device_key']);
    exit;
}

$dq = $conn->prepare(
    "SELECT id, subject_id FROM bio_devices WHERE device_key = ? LIMIT 1"
);
$dq->bind_param('s', $device_key);
$dq->execute();
$device = $dq->get_result()->fetch_assoc();

if (!$device) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unknown device. Register it in Biometric Setup.']);
    exit;
}

// Update last_seen
$upd = $conn->prepare("UPDATE bio_devices SET last_seen = NOW() WHERE id = ?");
$upd->bind_param('i', $device['id']);
$upd->execute();

$conn->query("UPDATE bio_sessions SET status='ended', ended_at=NOW()
              WHERE status='active' AND auto_expire_at IS NOT NULL AND auto_expire_at < NOW()");

$sq = $conn->prepare(
    "SELECT subject_id, late_threshold FROM bio_sessions
     WHERE device_id=? AND status='active'
     ORDER BY started_at DESC LIMIT 1"
);
$sq->bind_param('i', $device['id']);
$sq->execute();
$session = $sq->get_result()->fetch_assoc();

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'No active session. Ask teacher to start one.']);
    exit;
}

$subject_id  = (int)$session['subject_id'];
$late_cutoff = $session['late_threshold'] ?? '08:15:00';


// ── Load templates for all students enrolled in this subject ──
// Only students who HAVE a fingerprint template enrolled
$tq = $conn->prepare(
    "SELECT ft.student_id,
            ft.template_b64,
            CONCAT(s.first_name, ' ', s.last_name) AS name
     FROM fingerprint_templates ft
     JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = ft.student_id COLLATE utf8mb4_unicode_ci
     JOIN subject_enrollments se
          ON se.student_id COLLATE utf8mb4_unicode_ci = ft.student_id COLLATE utf8mb4_unicode_ci
         AND se.subject_id = ?
     ORDER BY s.last_name ASC"
);
$tq->bind_param('i', $subject_id);
$tq->execute();
$tq_result = $tq->get_result();

$templates = [];
while ($r = $tq_result->fetch_assoc()) {
    $templates[] = [
        'student_id'   => $r['student_id'],
        'name'         => $r['name'],
        'template_b64' => $r['template_b64'],
    ];
}

if (empty($templates)) {
    // Log the attempt
    $ll = $conn->prepare(
        "INSERT INTO biometric_log
         (device_id, subject_id, scanned_at, ip_address, status, message)
         VALUES (?, ?, ?, ?, 'no_templates', 'No enrolled fingerprints for this subject')"
    );
    $ll->bind_param('iiss', $device['id'], $subject_id,
                    $scan_date . ' ' . $scan_time, $ip);
    $ll->execute();

    echo json_encode([
        'status'  => 'error',
        'message' => 'No fingerprints enrolled for this subject yet.',
    ]);
    exit;
}

// ── Fetch late threshold from subject config ──────────────────
// Stored as a fixed value here; you could add a column to subjects
// if you need per-subject thresholds
$late_cutoff = '08:15:00';

echo json_encode([
    'status'     => 'ok',
    'subject_id' => $subject_id,
    'late_cutoff'=> $late_cutoff,
    'templates'  => $templates,
]);