<?php
// ============================================================
// api/bio_record.php
// Called by ESP32 after a successful fingerprint match.
// Resolves subject from the active bio_session for this device.
//
// POST /classroom/api/bio_record.php
// Body (form or JSON):
//   device_key = DEVICE_KEY_HERE
//   student_id = "2024-001"
//   date       = YYYY-MM-DD
//   time       = HH:MM:SS
//   status     = "Present" | "Late" (optional, server recalculates)
// ============================================================
header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Parse body ────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$data = !empty($raw) ? (json_decode($raw, true) ?? []) : [];
$data = array_merge($_POST, $data);

$device_key = trim($data['device_key'] ?? '');
$student_id = trim($data['student_id'] ?? '');
$scan_date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'] ?? '') ? $data['date'] : date('Y-m-d');
$scan_time  = preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['time'] ?? '') ? $data['time'] : date('H:i:s');
$ip         = $_SERVER['REMOTE_ADDR'] ?? '';

// ── Authenticate device ───────────────────────────────────────
if ($device_key === '') {
    http_response_code(400);
    die(json_encode(['status'=>'error','message'=>'Missing device_key']));
}

$dq = $conn->prepare("SELECT id FROM bio_devices WHERE device_key=? LIMIT 1");
$dq->bind_param('s', $device_key);
$dq->execute();
$device = $dq->get_result()->fetch_assoc();
if (!$device) {
    http_response_code(403);
    die(json_encode(['status'=>'error','message'=>'Unknown device']));
}
$device_id = (int)$device['id'];

// Heartbeat
$upd = $conn->prepare("UPDATE bio_devices SET last_seen=NOW() WHERE id=?");
$upd->bind_param('i', $device_id); $upd->execute();

// ── Auto-expire sessions ──────────────────────────────────────
$conn->query(
    "UPDATE bio_sessions SET status='ended', ended_at=NOW()
     WHERE status='active' AND auto_expire_at IS NOT NULL AND auto_expire_at < NOW()"
);

// ── Find active session ───────────────────────────────────────
$sq = $conn->prepare(
    "SELECT id AS session_id, subject_id, started_at, late_after_minutes
     FROM bio_sessions
     WHERE device_id=? AND status='active'
     ORDER BY started_at DESC LIMIT 1"
);
$sq->bind_param('i', $device_id);
$sq->execute();
$session = $sq->get_result()->fetch_assoc();
if (!$session) {
    writelog($conn, $device_id, null, $student_id, null, "$scan_date $scan_time", $ip,
             'no_session', 'No active session for this device');
    die(json_encode(['status'=>'error','message'=>'No active session. Ask teacher to start one.']));
}
$session_id         = (int)$session['session_id'];
$subject_id         = (int)$session['subject_id'];
$session_started_at = $session['started_at'];
$late_after_minutes = (int)$session['late_after_minutes'];

// ── Validate student ──────────────────────────────────────────
if ($student_id === '') {
    die(json_encode(['status'=>'error','message'=>'Missing student_id']));
}

$sq2 = $conn->prepare(
    "SELECT student_id, first_name, last_name FROM students WHERE student_id=? LIMIT 1"
);
$sq2->bind_param('s', $student_id);
$sq2->execute();
$student = $sq2->get_result()->fetch_assoc();
if (!$student) {
    writelog($conn, $device_id, $session_id, $student_id, $subject_id,
             "$scan_date $scan_time", $ip, 'error', "Student '$student_id' not found");
    die(json_encode(['status'=>'error','message'=>"Student not found"]));
}
$display = $student['first_name'] . ' ' . $student['last_name'];

// ── Verify enrollment ─────────────────────────────────────────
$eq = $conn->prepare(
    "SELECT id FROM subject_enrollments WHERE subject_id=? AND student_id=? LIMIT 1"
);
$eq->bind_param('is', $subject_id, $student_id);
$eq->execute(); $eq->store_result();
if ($eq->num_rows === 0) {
    $msg = "$display is not enrolled in this subject";
    writelog($conn, $device_id, $session_id, $student_id, $subject_id,
             "$scan_date $scan_time", $ip, 'not_enrolled', $msg);
    die(json_encode(['status'=>'error','message'=>$msg]));
}

// ── Duplicate check ───────────────────────────────────────────
$ck = $conn->prepare(
    "SELECT id FROM attendance WHERE subject_id=? AND student_id=? AND date=? LIMIT 1"
);
$ck->bind_param('iss', $subject_id, $student_id, $scan_date);
$ck->execute(); $ck->store_result();
if ($ck->num_rows > 0) {
    writelog($conn, $device_id, $session_id, $student_id, $subject_id,
             "$scan_date $scan_time", $ip, 'dup', 'Already marked today');
    die(json_encode(['status'=>'dup','student'=>$display,'action'=>'Already marked']));
}

// ── Determine Present / Late ──────────────────────────────────
// Let MySQL do the comparison (scan datetime vs. session start + minutes)
// rather than PHP's clock, to avoid any PHP/MySQL timezone disagreement —
// same reasoning as bio_match.php.
$scan_datetime = "$scan_date $scan_time";
$lateq = $conn->prepare(
    "SELECT (? >= DATE_ADD(?, INTERVAL ? MINUTE)) AS is_late"
);
$lateq->bind_param('ssi', $scan_datetime, $session_started_at, $late_after_minutes);
$lateq->execute();
$is_late = (bool)$lateq->get_result()->fetch_assoc()['is_late'];
$att_status = $is_late ? 'Late' : 'Present';

$source = 'Biometric';

// ── Insert attendance ─────────────────────────────────────────
$ins = $conn->prepare(
    "INSERT INTO attendance (subject_id, student_id, date, time_in, status, remarks, source)
     VALUES (?,?,?,?,?,'',?)"
);
$ins->bind_param('isssss', $subject_id, $student_id, $scan_date, $scan_time, $att_status, $source);
if (!$ins->execute()) {
    http_response_code(500);
    die(json_encode(['status'=>'error','message'=>'DB error: '.$conn->error]));
}

recalcSubjectGrade($conn, $subject_id, $student_id);

writelog($conn, $device_id, $session_id, $student_id, $subject_id,
         "$scan_date $scan_time", $ip, strtolower($att_status),
         "Marked $att_status via biometric");

echo json_encode([
    'status' => strtolower($att_status),
    'student' => $display,
    'action' => $att_status,
]);

// ── Helper ────────────────────────────────────────────────────
function writelog($conn, $device_id, $session_id, $student_id, $subject_id,
                   $scanned_at, $ip, $status, $message) {
    $ll = $conn->prepare(
        "INSERT INTO biometric_log
            (device_id, session_id, student_id, subject_id, scanned_at, ip_address, status, message)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $ll->bind_param('iisissss',
        $device_id, $session_id, $student_id, $subject_id,
        $scanned_at, $ip, $status, $message
    );
    $ll->execute();
}