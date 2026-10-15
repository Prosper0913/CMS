<?php
// ============================================================
// api/rfid_enroll.php   (REVISED — no ESP32, no device_key)
//
// Called by JS in teacher/biometric.php's "Enroll via RFID Card"
// panel, directly from the teacher's own browser tab. The USB
// reader is a plug-and-play HID keyboard-emulation device: when a
// card is tapped it "types" the UID into a focused text input on
// the page, followed by Enter. The page's JS catches that Enter,
// reads the typed string as card_uid, and POSTs it here using a
// normal fetch() call — the browser is already authenticated via
// the teacher's PHP session, so there's no device secret involved.
//
// POST /classroom/api/rfid_enroll.php
// Body (JSON or form):
//   student_id = "2024-001"
//   card_uid   = "0123456789"   (whatever the reader types — digits or hex)
//
// Response:
//   { "status":"ok", "student":"Ana Reyes" }
//   { "status":"error", "message":"..." }
// ============================================================

session_start();
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

header('Content-Type: application/json');

function fail($msg, $httpCode = 400) {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

$teacher_id = $_SESSION['user_id'];

// ── Parse body ────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = !empty($raw) ? (json_decode($raw, true) ?? []) : [];
$data = array_merge($_POST, $data);

$student_id = trim($data['student_id'] ?? '');
// USB HID readers vary: some type the UID as decimal digits, some as
// hex. Accept either — just require it to be a plausible card code,
// not an empty/garbage scan.
$card_uid   = strtoupper(trim($data['card_uid'] ?? ''));

if ($student_id === '') fail('Missing student_id');
if ($card_uid === '')   fail('No card detected — tap the card again');
if (!preg_match('/^[0-9A-F]{4,20}$/', $card_uid)) fail('Card code looks invalid — try tapping again');

// ── Verify this teacher actually teaches this student ──────────
// (mirrors teacherOwnsStudent() in config/db.php — inlined here so
// this endpoint has no other dependency than db.php + auth.php)
$ov = $conn->prepare(
    "SELECT 1 FROM subject_enrollments se
     JOIN subjects s ON s.id = se.subject_id
     WHERE se.student_id = ? AND s.teacher_id = ? LIMIT 1"
);
$ov->bind_param('si', $student_id, $teacher_id);
$ov->execute(); $ov->store_result();
if ($ov->num_rows === 0) fail('That student is not in any of your subjects', 403);

// ── Validate student ───────────────────────────────────────────
$sq = $conn->prepare("SELECT first_name, last_name FROM students WHERE student_id=? LIMIT 1");
$sq->bind_param('s', $student_id);
$sq->execute();
$student = $sq->get_result()->fetch_assoc();
if (!$student) fail("Student '$student_id' not found", 404);
$display = $student['first_name'] . ' ' . $student['last_name'];

// ── Reject a card already bound to a DIFFERENT student ─────────
// (Re-tapping the SAME student's own card is fine — that's just a
// re-enroll and falls through to the upsert below.)
$cq = $conn->prepare("SELECT student_id FROM rfid_cards WHERE card_uid=? LIMIT 1");
$cq->bind_param('s', $card_uid);
$cq->execute();
$existing = $cq->get_result()->fetch_assoc();
if ($existing && $existing['student_id'] !== $student_id) {
    fail("That card is already registered to a different student (ID {$existing['student_id']}). " .
         "Use a different card, or clear the old registration first.", 409);
}

// ── Upsert: one card per student, overwriting any previous one ─
$ins = $conn->prepare(
    "INSERT INTO rfid_cards (student_id, card_uid, enrolled_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE card_uid = VALUES(card_uid), enrolled_at = NOW()"
);
$ins->bind_param('ss', $student_id, $card_uid);
if (!$ins->execute()) {
    fail('DB error: ' . $conn->error, 500);
}

echo json_encode(['status' => 'ok', 'student' => $display]);