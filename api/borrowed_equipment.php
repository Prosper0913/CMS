<?php
// ============================================================
//  POST /api/borrowed_equipment.php
//  Header: X-API-Key: <key>
//  Body (JSON):
//    {
//      "student_id":     "2023-00101",
//      "section_id":     15,
//      "subject_id":     11,
//      "equipment_name": "Digital Multimeter",
//      "quantity":       1,
//      "borrow_date":    "2026-08-07",
//      "status":         "borrowed",   // borrowed | returned | overdue | lost
//      "approved_by":    "Engr. Dela Cruz",
//      "external_id":    "FPST-0001"   // optional, but recommended:
//                                      // send the SAME external_id again
//                                      // when the status changes (e.g. to
//                                      // "returned") and this endpoint will
//                                      // UPDATE the existing row instead of
//                                      // creating a duplicate.
//    }
//
//  The record is only accepted if the student is actually enrolled
//  in that section+subject, AND the calling key is authorized for
//  that section's course (see api/_api_common.php) — this endpoint
//  can't be used to attach equipment to an arbitrary student or a
//  course outside the key's own scope.
// ============================================================
require_once __DIR__ . '/_api_common.php';
$endpoint = 'borrowed_equipment';
$key = authenticate_api_key($conn, $endpoint);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_fail(405, 'Only POST is supported.', $conn, $endpoint, $key['id']);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    api_fail(400, 'Request body must be valid JSON.', $conn, $endpoint, $key['id']);
}

$required = ['student_id','section_id','subject_id','equipment_name','quantity','borrow_date','status','approved_by'];
foreach ($required as $f) {
    if (!isset($body[$f]) || $body[$f] === '') {
        api_fail(400, "Missing required field: $f", $conn, $endpoint, $key['id']);
    }
}

$student_id     = trim((string)$body['student_id']);
$section_id     = (int)$body['section_id'];
$subject_id     = (int)$body['subject_id'];
$equipment_name = trim((string)$body['equipment_name']);
$quantity       = max(1, (int)$body['quantity']);
$borrow_date    = trim((string)$body['borrow_date']);
$status         = trim((string)$body['status']);
$approved_by    = trim((string)$body['approved_by']);
$external_id    = (isset($body['external_id']) && $body['external_id'] !== '') ? trim((string)$body['external_id']) : null;

$valid_statuses = ['borrowed', 'returned', 'overdue', 'lost'];
if (!in_array($status, $valid_statuses, true)) {
    api_fail(400, 'status must be one of: ' . implode(', ', $valid_statuses), $conn, $endpoint, $key['id']);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $borrow_date)) {
    api_fail(400, 'borrow_date must be in YYYY-MM-DD format.', $conn, $endpoint, $key['id']);
}

require_authorized_section($conn, $key, $section_id, $endpoint);

// The student must actually be enrolled in this exact section+subject.
$chk = $conn->prepare(
    "SELECT 1 FROM subject_enrollments WHERE student_id=? AND section_id=? AND subject_id=? LIMIT 1"
);
$chk->bind_param('sii', $student_id, $section_id, $subject_id);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    api_fail(404, 'That student is not enrolled in the given section/subject.', $conn, $endpoint, $key['id']);
}

try {
    $api_key_id = $key['id'];

    if ($external_id !== null) {
        $stmt = $conn->prepare(
            "INSERT INTO fpst_borrowed_equipment
                (external_id, student_id, section_id, subject_id, equipment_name, quantity, borrow_date, status, approved_by, api_key_id)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                student_id=VALUES(student_id), section_id=VALUES(section_id), subject_id=VALUES(subject_id),
                equipment_name=VALUES(equipment_name), quantity=VALUES(quantity), borrow_date=VALUES(borrow_date),
                status=VALUES(status), approved_by=VALUES(approved_by), api_key_id=VALUES(api_key_id)"
        );
        $stmt->bind_param('ssiisisssi',
            $external_id, $student_id, $section_id, $subject_id, $equipment_name,
            $quantity, $borrow_date, $status, $approved_by, $api_key_id
        );
        $stmt->execute();

        // Fetch the row id reliably (works whether this was an insert or an update).
        $sel = $conn->prepare("SELECT id FROM fpst_borrowed_equipment WHERE external_id = ? LIMIT 1");
        $sel->bind_param('s', $external_id);
        $sel->execute();
        $record_id = (int)($sel->get_result()->fetch_assoc()['id'] ?? 0);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO fpst_borrowed_equipment
                (student_id, section_id, subject_id, equipment_name, quantity, borrow_date, status, approved_by, api_key_id)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('siisisssi',
            $student_id, $section_id, $subject_id, $equipment_name,
            $quantity, $borrow_date, $status, $approved_by, $api_key_id
        );
        $stmt->execute();
        $record_id = $conn->insert_id;
    }

    echo json_encode(['success' => true, 'message' => 'Borrow record synced.', 'id' => $record_id]);
} catch (Throwable $e) {
    api_fail(500, 'Database error: ' . $e->getMessage(), $conn, $endpoint, $key['id']);
}
