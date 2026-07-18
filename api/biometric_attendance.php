<?php
// ============================================================
//  api/biometric_attendance.php
//  Called by the ESP32 over WiFi. No session required —
//  authenticated by a shared API key in the request.
//
//  GET  ?fp_id=3&subject_id=5&time=14:32:00&date=2026-06-07&key=YOUR_KEY
//  GET  ?fp_id=3&time=14:32:00&date=2026-06-07&key=YOUR_KEY  (no subject)
//
//  Returns JSON:
//    {"status":"present", "student":"Ana Reyes",  "action":"Present", "subjects":2}
//    {"status":"late",    "student":"Ana Reyes",  "action":"Late",    "subjects":1}
//    {"status":"dup",     "student":"Ana Reyes",  "action":"Already marked"}
//    {"status":"error",   "message":"Unknown fingerprint"}
// ============================================================

header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── API key check ────────────────────────────────────────────
require_once '../config/secrets.php';
define('API_KEY', BIO_API_KEY);

$key = $_GET['key'] ?? '';
if ($key !== API_KEY) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// ── Parse params ─────────────────────────────────────────────
$fp_id      = (int)($_GET['fp_id']      ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);   // 0 = no subject context
$scan_date  = $_GET['date'] ?? date('Y-m-d');
$scan_time  = $_GET['time'] ?? date('H:i:s');
$ip         = $_SERVER['REMOTE_ADDR'] ?? null;

// Validate date/time format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scan_date)) $scan_date = date('Y-m-d');
if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $scan_time))  $scan_time = date('H:i:s');

$scanned_at = $scan_date . ' ' . $scan_time;

if ($fp_id <= 0) {
    logAndRespond($conn, 0, null, 0, $scanned_at, $ip, 'error', 'Invalid fp_id');
}

// ── Look up student by fingerprint slot ──────────────────────
$sq = $conn->prepare(
    "SELECT student_id, first_name, last_name FROM students WHERE fingerprint_id = ?"
);
$sq->bind_param('i', $fp_id);
$sq->execute();
$student = $sq->get_result()->fetch_assoc();

if (!$student) {
    logAndRespond($conn, $fp_id, null, 0, $scanned_at, $ip,
        'unknown_fp', "Fingerprint slot {$fp_id} not assigned to any student");
}

$sid     = $student['student_id'];
$display = $student['first_name'] . ' ' . $student['last_name'];

// ── Determine subject context ─────────────────────────────────
if ($subject_id > 0) {
    $eq = $conn->prepare(
        "SELECT subject_id FROM subject_enrollments WHERE subject_id=? AND student_id=? LIMIT 1"
    );
    $eq->bind_param('is', $subject_id, $sid);
    $eq->execute();
    $eq->store_result();
    if ($eq->num_rows === 0) {
        $subject_id = 0;   // Not enrolled in that subject — fall through to auto-detect
    }
}

$subjects_to_mark = [];
if ($subject_id > 0) {
    $subjects_to_mark[] = $subject_id;
} else {
    // Mark all active subjects this student is enrolled in
    $aq = $conn->prepare(
        "SELECT e.subject_id FROM subject_enrollments e
         JOIN subjects s ON s.id = e.subject_id
         WHERE e.student_id = ? AND s.is_active = 1"
    );
    $aq->bind_param('s', $sid);
    $aq->execute();
    $ar = $aq->get_result();
    while ($r = $ar->fetch_assoc()) $subjects_to_mark[] = $r['subject_id'];
}

if (empty($subjects_to_mark)) {
    logAndRespond($conn, $fp_id, $sid, 0, $scanned_at, $ip,
        'no_subject', "{$display} is not enrolled in any active subject");
}

// ── Determine Present vs Late ─────────────────────────────────
$late_threshold    = '08:15:00';
$attendance_status = (strtotime($scan_time) > strtotime($late_threshold)) ? 'Late' : 'Present';
$source            = 'Biometric';

// ── Write attendance rows ─────────────────────────────────────
$marked    = 0;
$duplicate = 0;

foreach ($subjects_to_mark as $sub_id) {
    $ck = $conn->prepare(
        "SELECT id FROM attendance WHERE subject_id=? AND student_id=? AND date=? LIMIT 1"
    );
    $ck->bind_param('iss', $sub_id, $sid, $scan_date);
    $ck->execute();
    $ck->store_result();

    if ($ck->num_rows > 0) {
        $duplicate++;
        continue;
    }

    $ins = $conn->prepare(
        "INSERT INTO attendance (subject_id, student_id, date, time_in, status, remarks, source)
         VALUES (?, ?, ?, ?, ?, '', ?)"
    );
    $ins->bind_param('isssss', $sub_id, $sid, $scan_date, $scan_time, $attendance_status, $source);
    $ins->execute();
    recalcSubjectGrade($conn, $sub_id, $sid);
    $marked++;
}

// ── Write to biometric_log ────────────────────────────────────
$log_status  = ($marked > 0) ? strtolower($attendance_status) : 'dup';
$log_message = $marked > 0
    ? "Marked {$attendance_status} for {$marked} subject(s)"
    : "Already marked today for all subjects";

$first_sub = $subjects_to_mark[0] ?? 0;

$ll = $conn->prepare(
    "INSERT INTO biometric_log
     (fp_id, student_id, subject_id, scanned_at, ip_address, status, message)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$ll->bind_param('isiisss',
    $fp_id, $sid, $first_sub, $scanned_at, $ip, $log_status, $log_message
);
$ll->execute();

// ── Response to ESP32 ─────────────────────────────────────────
if ($marked > 0) {
    echo json_encode([
        'status'   => strtolower($attendance_status),   // 'present' or 'late'
        'student'  => $display,
        'action'   => $attendance_status,
        'subjects' => $marked,
    ]);
} else {
    echo json_encode([
        'status'  => 'dup',
        'student' => $display,
        'action'  => 'Already marked',
    ]);
}
exit;

// ============================================================
//  Helper: log an error to biometric_log, send JSON, and exit
// ============================================================
function logAndRespond($conn, $fp_id, $sid, $sub_id, $scanned_at, $ip, $status, $message) {
    $ll = $conn->prepare(
        "INSERT INTO biometric_log
         (fp_id, student_id, subject_id, scanned_at, ip_address, status, message)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    // student_id may be NULL (unknown fingerprint), use empty string as fallback
    $sid_val = $sid ?? '';
    $ll->bind_param('isiisss',
        $fp_id, $sid_val, $sub_id, $scanned_at, $ip, $status, $message
    );
    $ll->execute();

    $http_code = ($status === 'error' || $status === 'unknown_fp') ? 404 : 200;
    http_response_code($http_code);
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}