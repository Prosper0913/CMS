<?php
// ============================================================
//  api/bio_enroll_done.php
//  Called by ESP32 after enrollment succeeds or fails.
//  Updates the queue row, then chains the next student if
//  a bulk section enrollment is in progress.
//
//  Bulk-enroll remaining state lives on the bio_devices row
//  (bulk_enroll_students / bulk_enroll_index), NOT in $_SESSION —
//  this request comes from the ESP32 device itself over its own
//  HTTP connection, which never carries the teacher's browser
//  session cookie, so a PHP session here would always be a fresh,
//  empty one. The device already authenticates via device_key,
//  which is enough to look up its own persisted queue state.
//
//  POST key=DEVICE_KEY & queue_id=N & result=done|failed
// ============================================================
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
$devq = $conn->prepare("SELECT bulk_enroll_students, bulk_enroll_index FROM bio_devices WHERE id=?");
$devq->bind_param('i', $device_id);
$devq->execute();
$devrow = $devq->get_result()->fetch_assoc();

$remaining = $devrow ? json_decode($devrow['bulk_enroll_students'] ?? '[]', true) : [];
$bindex    = $devrow ? (int)$devrow['bulk_enroll_index'] : 0;

if (!empty($remaining) && is_array($remaining) && $bindex < count($remaining)) {
    $next_student = $remaining[$bindex];
    $bindex++;

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

    $left = count($remaining) - $bindex;
    if ($bindex >= count($remaining)) {
        // Done — clear the persisted queue on the device row
        $clr = $conn->prepare("UPDATE bio_devices SET bulk_enroll_students=NULL, bulk_enroll_index=NULL WHERE id=?");
        $clr->bind_param('i', $device_id);
        $clr->execute();
    } else {
        $upd2 = $conn->prepare("UPDATE bio_devices SET bulk_enroll_index=? WHERE id=?");
        $upd2->bind_param('ii', $bindex, $device_id);
        $upd2->execute();
    }

    echo json_encode([
        'status'       => 'ok',
        'next_student' => $next_student,
        'remaining'    => $left,
    ]);
    exit;
}

echo json_encode(['status' => 'ok', 'next_student' => null]);