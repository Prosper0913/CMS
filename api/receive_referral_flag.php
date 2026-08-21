<?php
// ============================================================
//  classroomv2/api/receive_referral_flag.php
//  Inbound endpoint — Guidance POSTs here when a student's
//  referral status changes.
// ============================================================

 $DB_HOST = 'localhost';
 $DB_NAME = 'classroom_db2';
 $DB_USER = 'root';
 $DB_PASS = '';

 $SYNC_RECEIVE_KEY = '30fceb36b4cc5c5422ab192d5bead0de7dd88596c37a372c0e14abc1551ad0e8';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
 $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
 $conn->set_charset('utf8mb4');

// Authenticate
 $sent = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';
if ($sent === '' || !hash_equals($SYNC_RECEIVE_KEY, $sent)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid or missing X-Sync-Key header.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Only POST is supported.']);
    exit;
}

 $raw = file_get_contents('php://input');
if ($raw === '' || $raw === false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Empty request body.']);
    exit;
}
 $body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Request body must be valid JSON.']);
    exit;
}

 $student_id = trim((string)($body['student_id'] ?? ''));
 $has_active = !empty($body['has_active_referral']);
 $referral_count = (int)($body['referral_count'] ?? ($has_active ? 1 : 0));
 $referral_ids = $body['referral_ids'] ?? [];
if (!is_array($referral_ids)) $referral_ids = [];

if ($student_id === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'student_id is required.']);
    exit;
}

try {
    $upd = $conn->prepare(
        "UPDATE students
         SET has_active_referral = ?,
             referral_flag_synced_at = NOW()
         WHERE student_id = ?"
    );
    $flag = $has_active ? 1 : 0;
    $upd->bind_param('is', $flag, $student_id);
    $upd->execute();

    if ($upd->affected_rows === 0 && $conn->affected_rows === 0) {
        $chk = $conn->prepare("SELECT 1 FROM students WHERE student_id = ? LIMIT 1");
        $chk->bind_param('s', $student_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows === 0) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'note'    => "Student '$student_id' not found in CMS — flag not stored (likely deleted).",
                'student_id' => $student_id,
            ]);
            exit;
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'student_id' => $student_id,
        'has_active_referral' => $has_active,
        'referral_count' => $referral_count,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}