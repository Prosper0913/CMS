<?php
// ============================================================
//  api/bio_enroll_done.php
//  Called by ESP32 after enrollment succeeds or fails.
//  Updates the queue row, then chains the next student if
//  a bulk section enrollment is in progress (session-based).
//
//  POST key=DEVICE_KEY & queue_id=N & result=done|failed
// ============================================================
session_start();
header('Content-Type: application/json');
require_once '../config/db.php';

$device_key = trim($_POST['key']      ?? '');
$queue_id   = (int)($_POST['queue_id'] ?? 0);
$result     = ($_POST['result'] ?? '') === 'done' ? 'done' : 'failed';

if ($device_key === '' || $queue_id === 0) {
    die(json_encode(['status'=>'error','message'=>'Missing params']));
}

$dq = $conn->prepare("SELECT id FROM bio_devices WHERE device_key=? LIMIT 1");
$dq->bind_param('s', $device_key); $dq->execute();
$device = $dq->get_result()->fetch_assoc();
if (!$device) { die(json_encode(['status'=>'error','message'=>'Unknown device'])); }

$device_id = (int)$device['id'];

// Mark this queue row done/failed
$upd = $conn->prepare("UPDATE bio_enroll_queue SET status=? WHERE id=? AND device_id=?");
$upd->bind_param('sii', $result, $queue_id, $device_id);
$upd->execute();

// ── Chain next student in bulk section enrollment ─────────────
$next_queued = false;
if (isset($_SESSION['bulk_enroll_queue'])) {
    $bq = &$_SESSION['bulk_enroll_queue'];
    if ((int)$bq['device_id'] === $device_id && $bq['index'] < count($bq['students'])) {
        $next_student = $bq['students'][$bq['index']];
        $bq['index']++;
        // Clear the just-finished row so the UNIQUE(device_id) key is free
        $del = $conn->prepare(
            "DELETE FROM bio_enroll_queue WHERE device_id=? AND status IN ('done','failed')"
        );
        $del->bind_param('i', $device_id);
        $del->execute();
        // Queue the next student
        $ins = $conn->prepare(
            "INSERT INTO bio_enroll_queue (device_id, student_id, queued_at, status)
             VALUES (?, ?, NOW(), 'pending')
             ON DUPLICATE KEY UPDATE student_id=VALUES(student_id), queued_at=NOW(), status='pending'"
        );
        $ins->bind_param('is', $device_id, $next_student);
        $ins->execute();
        $next_queued   = true;
        $remaining     = count($bq['students']) - $bq['index'];
        // Clear session when done
        if ($bq['index'] >= count($bq['students'])) {
            unset($_SESSION['bulk_enroll_queue']);
        }
        echo json_encode([
            'status'        => 'ok',
            'next_student'  => $next_student,
            'remaining'     => $remaining,
        ]);
        exit;
    } else {
        // Done with bulk queue
        unset($_SESSION['bulk_enroll_queue']);
    }
}

echo json_encode(['status' => 'ok', 'next_student' => null]);