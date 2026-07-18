<?php
// ============================================================
//  api/bio_config.php
//  Polled by ESP32 on boot and periodically between scans.
//  Returns the currently active session for this device.
//
//  GET /classroom/api/bio_config.php?key=DEVICE_KEY_HERE
//
//  Response 200 (session active):
//    { "status":"ok", "session_id":12, "subject_id":5,
//      "subject_code":"CS101", "subject_name":"Intro to CS",
//      "section":"BSIT-3A", "teacher":"Mr. Santos",
//      "late_threshold":"08:15:00" }
//
//  Response 200 (no active session):
//    { "status":"idle", "message":"No active session. Waiting..." }
//
//  Response 404:
//    { "status":"error", "message":"Device not registered" }
// ============================================================

header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$device_key = trim($_GET['key'] ?? '');
if ($device_key === '') {
    http_response_code(400);
    die(json_encode(['status'=>'error','message'=>'No device key provided']));
}

// ── Authenticate device ───────────────────────────────────────
$dq = $conn->prepare("SELECT id, label FROM bio_devices WHERE device_key=? LIMIT 1");
$dq->bind_param('s', $device_key);
$dq->execute();
$device = $dq->get_result()->fetch_assoc();

if (!$device) {
    http_response_code(404);
    die(json_encode(['status'=>'error','message'=>'Device not registered']));
}

// FIX #3: The original called ->bind_param() directly on the prepare()
//         result without storing the statement object first, which is a
//         PHP fatal error.  The UPDATE then re-prepared the same query
//         and bound parameters on the new statement object — redundant and
//         confusing.  Keeping only the correct two-step version.
$upd = $conn->prepare("UPDATE bio_devices SET last_seen=NOW() WHERE id=?");
$upd->bind_param('i', $device['id']);
$upd->execute();

// ── Auto-expire sessions ──────────────────────────────────────
$conn->query(
    "UPDATE bio_sessions SET status='ended', ended_at=NOW()
     WHERE status='active'
       AND auto_expire_at IS NOT NULL
       AND auto_expire_at < NOW()"
);

$sq = $conn->prepare(
    "SELECT bs.id AS session_id, bs.subject_id, bs.late_threshold,
            s.subject_code, s.subject_name, s.section,
            u.username AS teacher_name
     FROM bio_sessions bs
     JOIN subjects s ON s.id = bs.subject_id
     JOIN users u ON u.id = bs.started_by
     WHERE bs.device_id=? AND bs.status='active'
     ORDER BY bs.started_at DESC LIMIT 1"
);
$sq->bind_param('i', $device['id']);
$sq->execute();
$session = $sq->get_result()->fetch_assoc();

if (!$session) {
    echo json_encode([
        'status'  => 'idle',
        'device'  => $device['label'],
        'message' => 'No active session. Waiting for teacher to start one.',
    ]);
    exit;
}

echo json_encode([
    'status'         => 'ok',
    'session_id'     => (int)$session['session_id'],
    'subject_id'     => (int)$session['subject_id'],
    'subject_code'   => $session['subject_code'],
    'subject_name'   => $session['subject_name'],
    'section'        => $session['section'],
    'teacher'        => $session['teacher_name'],
    'late_threshold' => $session['late_threshold'],
]);