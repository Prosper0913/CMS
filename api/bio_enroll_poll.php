<?php
// ============================================================
//  api/bio_enroll_poll.php
//  Called by ESP32 every few seconds to check if a teacher
//  has queued an enrollment for this device.
// ============================================================
header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// Auto-recover stuck 'enrolling' rows older than 2 minutes
$conn->query(
    "UPDATE bio_enroll_queue SET status='pending'
     WHERE status='enrolling'
     AND queued_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
);

$device_key = trim($_GET['key'] ?? '');
if ($device_key === '') {
    http_response_code(400);
    die(json_encode(['status'=>'error','message'=>'Missing key']));
}

// Authenticate device
$dq = $conn->prepare("SELECT id FROM bio_devices WHERE device_key=? LIMIT 1");
$dq->bind_param('s', $device_key);
$dq->execute();
$device = $dq->get_result()->fetch_assoc();

if (!$device) {
    http_response_code(403);
    die(json_encode(['status'=>'error','message'=>'Unknown device']));
}

$device_id = (int)$device['id'];

// Update last_seen
$upd = $conn->prepare("UPDATE bio_devices SET last_seen=NOW() WHERE id=?");
$upd->bind_param('i', $device_id); 
$upd->execute();

// ── FIXED QUERY ─────────────────────────────────────────────
// Now looks for 'pending' OR 'enrolling' statuses. 
// If the ESP32 dropped the connection or rebooted, it can catch its own session.
$eq = $conn->prepare(
    "SELECT eq.id, eq.student_id, eq.status AS current_status,
            CONCAT(s.first_name,' ',s.last_name) AS name
     FROM bio_enroll_queue eq
     JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci
                      = eq.student_id COLLATE utf8mb4_unicode_ci
     WHERE eq.device_id=? AND eq.status IN ('pending', 'enrolling')
     ORDER BY eq.queued_at ASC LIMIT 1"
);
$eq->bind_param('i', $device_id);
$eq->execute();
$row = $eq->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['status'=>'idle']);
    exit;
}

// Only update database to 'enrolling' if it wasn't already marked as such
if ($row['current_status'] === 'pending') {
    $upd2 = $conn->prepare("UPDATE bio_enroll_queue SET status='enrolling' WHERE id=?");
    $upd2->bind_param('i', (int)$row['id']); 
    $upd2->execute();
}

echo json_encode([
    'status'     => 'enroll',
    'queue_id'   => (int)$row['id'],
    'student_id' => $row['student_id'],
    'name'       => $row['name'],
]);