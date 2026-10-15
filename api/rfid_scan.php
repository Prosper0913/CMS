<?php
// ============================================================
// api/rfid_scan.php   (REVISED — no ESP32, no device_key)
//
// Called by JS in teacher/subject_view.php's biometric tab,
// directly from the teacher's own browser. The USB reader types
// the tapped card's UID into a focused input followed by Enter;
// the page's JS catches that and fetch()'s this endpoint with the
// subject the teacher is currently viewing. Auth is the teacher's
// PHP session (same as every other action on that page) — there is
// no device_key, because there is no separate networked device.
//
// This endpoint does NOT care whether the active attendance session
// for the subject was started with a fingerprint device attached or
// with none at all ("RFID / manual only") — it looks the session up
// by subject_id, so RFID can either ride alongside an active
// fingerprint session as a fallback input, or be the only input in
// use for that subject.
//
// POST /classroom/api/rfid_scan.php
// Body (JSON or form):
//   subject_id = 12
//   card_uid   = "0123456789"
//   date       = YYYY-MM-DD   (optional — server falls back to its own clock)
//   time       = HH:MM:SS     (optional)
//
// Response:
//   { "status":"present", "student":"Ana Reyes", "action":"Present" }
//   { "status":"late",    "student":"Ana Reyes", "action":"Late" }
//   { "status":"dup",     "student":"Ana Reyes", "action":"Already marked" }
//   { "status":"ok", "result":"NO_MATCH" }   -- unregistered/unknown card
//   { "status":"error", "message":"..." }
// ============================================================

session_start();
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

header('Content-Type: application/json');

$teacher_id = $_SESSION['user_id'];

// ── Parse body ────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = !empty($raw) ? (json_decode($raw, true) ?? []) : [];
$data = array_merge($_POST, $data);

$subject_id = (int)($data['subject_id'] ?? 0);
$card_uid   = strtoupper(trim($data['card_uid'] ?? ''));
$scan_date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'] ?? '') ? $data['date'] : date('Y-m-d');
$scan_time  = preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['time'] ?? '') ? $data['time'] : date('H:i:s');
$ip         = $_SERVER['REMOTE_ADDR'] ?? '';

if ($subject_id === 0) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Missing subject_id']));
}
if ($card_uid === '') {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'No card detected']));
}

// ── Verify this teacher owns the subject ───────────────────────
$ov = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=? LIMIT 1");
$ov->bind_param('ii', $subject_id, $teacher_id);
$ov->execute(); $ov->store_result();
if ($ov->num_rows === 0) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Not your subject']));
}

// ── Auto-expire sessions (same housekeeping as bio_config.php / bio_record.php) ─
$conn->query(
    "UPDATE bio_sessions SET status='ended', ended_at=NOW()
     WHERE status='active' AND auto_expire_at IS NOT NULL AND auto_expire_at < NOW()"
);

// ── Find the active session for THIS SUBJECT ───────────────────
// Keyed by subject_id, not device_id — a fingerprint-started session
// and an RFID tap are just two inputs into the same open window.
$sq = $conn->prepare(
    "SELECT id AS session_id, started_at, late_after_minutes, device_id
     FROM bio_sessions
     WHERE subject_id=? AND status='active'
     ORDER BY started_at DESC LIMIT 1"
);
$sq->bind_param('i', $subject_id);
$sq->execute();
$session = $sq->get_result()->fetch_assoc();
if (!$session) {
    die(json_encode(['status' => 'error', 'message' => 'No active session. Start one on this subject\'s Biometric tab first.']));
}
$session_id         = (int)$session['session_id'];
$session_started_at = $session['started_at'];
$late_after_minutes  = (int)$session['late_after_minutes'];
$device_id           = $session['device_id'] !== null ? (int)$session['device_id'] : null;

// ── Look up the card ────────────────────────────────────────────
// This is the RFID "match" step — a direct UID lookup instead of
// fingerprint minutiae comparison. An unknown/unregistered card is
// reported exactly like a fingerprint NO_MATCH.
$cq = $conn->prepare("SELECT student_id FROM rfid_cards WHERE card_uid=? LIMIT 1");
$cq->bind_param('s', $card_uid);
$cq->execute();
$card = $cq->get_result()->fetch_assoc();
if (!$card) {
    writelog($conn, $device_id, $session_id, null, $subject_id,
              "$scan_date $scan_time", $ip, 'unknown_card', "Unregistered card UID $card_uid");
    echo json_encode(['status' => 'ok', 'result' => 'NO_MATCH']);
    exit;
}
$student_id = $card['student_id'];

$sq2 = $conn->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_id=? LIMIT 1");
$sq2->bind_param('s', $student_id);
$sq2->execute();
$student = $sq2->get_result()->fetch_assoc();
if (!$student) {
    // Card points at a student record that no longer exists (deleted
    // after enrollment). Treat it the same as an unknown card rather
    // than a 500 — the card is effectively orphaned either way.
    writelog($conn, $device_id, $session_id, $student_id, $subject_id,
             "$scan_date $scan_time", $ip, 'unknown_card', "Card UID $card_uid points at missing student $student_id");
    echo json_encode(['status' => 'ok', 'result' => 'NO_MATCH']);
    exit;
}
$display = $student['first_name'] . ' ' . $student['last_name'];

// ── Verify enrollment in THIS subject ──────────────────────────
$eq = $conn->prepare("SELECT id FROM subject_enrollments WHERE subject_id=? AND student_id=? LIMIT 1");
$eq->bind_param('is', $subject_id, $student_id);
$eq->execute(); $eq->store_result();
if ($eq->num_rows === 0) {
    $msg = "$display is not enrolled in this subject";
    writelog($conn, $device_id, $session_id, $student_id, $subject_id,
             "$scan_date $scan_time", $ip, 'not_enrolled', $msg);
    die(json_encode(['status' => 'error', 'message' => $msg]));
}

// ── Duplicate check ─────────────────────────────────────────────
$ck = $conn->prepare("SELECT id FROM attendance WHERE subject_id=? AND student_id=? AND date=? LIMIT 1");
$ck->bind_param('iss', $subject_id, $student_id, $scan_date);
$ck->execute(); $ck->store_result();
if ($ck->num_rows > 0) {
    writelog($conn, $device_id, $session_id, $student_id, $subject_id,
             "$scan_date $scan_time", $ip, 'dup', 'Already marked today');
    die(json_encode(['status' => 'dup', 'student' => $display, 'action' => 'Already marked']));
}

// ── Determine Present / Late ────────────────────────────────────
// Same approach as bio_record.php: let MySQL compare the scan
// datetime against session_start + late_after_minutes, so the
// browser's own clock can't disagree with the threshold the teacher
// actually configured for this session.
$scan_datetime = "$scan_date $scan_time";
$lateq = $conn->prepare("SELECT (? >= DATE_ADD(?, INTERVAL ? MINUTE)) AS is_late");
$lateq->bind_param('ssi', $scan_datetime, $session_started_at, $late_after_minutes);
$lateq->execute();
$is_late    = (bool)$lateq->get_result()->fetch_assoc()['is_late'];
$att_status = $is_late ? 'Late' : 'Present';
$source     = 'RFID';

// ── Insert attendance ───────────────────────────────────────────
$ins = $conn->prepare(
    "INSERT INTO attendance (subject_id, student_id, date, time_in, status, remarks, source)
     VALUES (?,?,?,?,?,'',?)"
);
$ins->bind_param('isssss', $subject_id, $student_id, $scan_date, $scan_time, $att_status, $source);
if (!$ins->execute()) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]));
}

recalcSubjectGrade($conn, $subject_id, $student_id);
if (function_exists('checkAbsenceStreakNotification')) {
    checkAbsenceStreakNotification($conn, $subject_id, $student_id);
}

writelog($conn, $device_id, $session_id, $student_id, $subject_id,
         "$scan_date $scan_time", $ip, strtolower($att_status),
         "Marked $att_status via RFID (card $card_uid)");

echo json_encode([
    'status' => strtolower($att_status),
    'student' => $display,
    'action' => $att_status,
]);

// ── Helper (identical shape to bio_record.php's, so biometric_log
//     stays a single shared table for both attendance methods).
//     $device_id may be NULL for an RFID-only session — the column
//     already allows that. ─────────────────────────────────────
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