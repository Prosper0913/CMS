<?php
// ============================================================
//  classroomv2/includes/sync_to_oces.php
//  Push-side helper for CMS → OCES sync.
//
//  FILTER: Only syncs students who are enrolled in a subject whose
//  name matches one of SYNC_SUBJECT_FILTERS (case-insensitive).
//  Currently set to ['NSTP 1', 'NSTP 2'].
//
//  If a student HAS an NSTP enrollment → push full state to OCES
//  (upsert).
//  If a student does NOT have any NSTP enrollment → push a
//  deletion to OCES (soft-deactivate, in case they were
//  previously synced). This handles un-enrollment gracefully.
//
//  CRITICAL: this helper NEVER throws. Every CMS action that
//  calls it must complete successfully even if OCES is down.
//
//  Usage (from admin/teacher pages):
//    require_once __DIR__ . '/../includes/sync_to_oces.php';
//    push_student_to_oces($conn, $student_id);
//    push_student_deletion_to_oces($student_id);
// ============================================================

// ── Config — EDIT THESE ──────────────────────────────────────
const OCES_API_BASE = 'http://localhost/capstone';

// Shared secret — must match $SYNC_RECEIVE_KEY in
// oces/_receive_common.php.
const OCES_SYNC_KEY = '1ff3aa6089f6e0897082946894b6f66f7e09b7c9a08651a8bc2c8f36fdf9a28e';

// Subject filter — only students enrolled in at least one of these
// subjects (by subject_name, case-insensitive) sync to OCES.
// Add or remove names as needed. Empty array = sync ALL students.
const SYNC_SUBJECT_FILTERS = ['NSTP 1', 'NSTP 2'];

// ── Internal: HTTP POST a JSON payload to OCES ───────────────
// Never throws.
function _oces_post($path, $payload) {
    $url = OCES_API_BASE . '/' . ltrim($path, '/');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Sync-Key: ' . OCES_SYNC_KEY,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log("[sync_to_oces] curl error POST $url: $err");
        return ['ok' => false, 'http' => 0, 'body' => $err, 'json' => null];
    }
    $decoded = json_decode($raw, true);
    $ok = ($http === 200) && is_array($decoded) && !empty($decoded['success']);
    if (!$ok) {
        error_log(sprintf("[sync_to_oces] non-200 from %s (HTTP %d): %s", $url, $http, substr($raw, 0, 500)));
    }
    return ['ok' => $ok, 'http' => $http, 'body' => $raw, 'json' => $decoded];
}

// ── Internal: load a student's full state from classroom_db2 ─
// Only includes enrollments whose subject_name is in
// SYNC_SUBJECT_FILTERS. Returns null if student doesn't exist.
function _build_student_payload_for_oces($conn, $student_id) {
    $student_id = trim((string)$student_id);
    if ($student_id === '') return null;

    // Load student
    $st = $conn->prepare(
        "SELECT student_id, username, password, first_name, last_name,
                middle_initial, email
         FROM students WHERE student_id = ? LIMIT 1"
    );
    $st->bind_param('s', $student_id);
    $st->execute();
    $student = $st->get_result()->fetch_assoc();
    if (!$student) return null;

    // Load their section memberships (for profile fields)
    $sec = $conn->prepare(
        "SELECT sec.id, sec.section_name, sec.course, sec.year_level
         FROM section_students ss
         JOIN sections sec ON sec.id = ss.section_id
         WHERE ss.student_id = ?
         ORDER BY sec.section_name ASC"
    );
    $sec->bind_param('s', $student_id);
    $sec->execute();
    $sections = $sec->get_result()->fetch_all(MYSQLI_ASSOC);

    $primary = $sections[0] ?? null;
    $profile = [
        'course'     => $primary['course']     ?? '',
        'year_level' => $primary ? (string)$primary['year_level'] : '',
        'section'    => $primary['section_name'] ?? '',
    ];

    // Load enrollments FILTERED to only NSTP 1 / NSTP 2 subjects
    // (or whatever is in SYNC_SUBJECT_FILTERS)
    $enrollments = [];
    if (!empty(SYNC_SUBJECT_FILTERS)) {
        // Build the IN (...) clause for subject names
        $placeholders = implode(',', array_fill(0, count(SYNC_SUBJECT_FILTERS), '?'));
        $enr = $conn->prepare(
            "SELECT se.section_id, se.subject_id,
                    sec.section_name, sec.course,
                    sub.subject_code, sub.subject_name
             FROM subject_enrollments se
             JOIN sections sec ON sec.id = se.section_id
             JOIN subjects sub ON sub.id = se.subject_id
             WHERE se.student_id = ?
               AND se.section_id IS NOT NULL
               AND UPPER(TRIM(sub.subject_name)) IN ($placeholders)
             ORDER BY sec.section_name, sub.subject_code"
        );
        // Bind student_id first, then the subject names (all uppercased + trimmed)
        $types = 's' . str_repeat('s', count(SYNC_SUBJECT_FILTERS));
        $params = array_merge([$student_id], array_map('strtoupper', SYNC_SUBJECT_FILTERS));
        $enr->bind_param($types, ...$params);
        $enr->execute();
        $res = $enr->get_result();
        while ($r = $res->fetch_assoc()) {
            $enrollments[] = [
                'cms_section_id' => (int)$r['section_id'],
                'cms_subject_id' => (int)$r['subject_id'],
                'course'         => $r['course']        ?? '',
                'section_name'   => $r['section_name']  ?? '',
                'subject_code'   => $r['subject_code']  ?? '',
                'subject_name'   => $r['subject_name']  ?? '',
            ];
        }
    }

    return [
        'student' => [
            'student_id'      => $student['student_id'],
            'username'        => $student['username'],
            'password_hash'   => $student['password'],
            'first_name'      => $student['first_name'],
            'last_name'       => $student['last_name'],
            'middle_initial'  => $student['middle_initial'] ?? '',
            'email'           => $student['email'] ?? '',
        ],
        'profile' => $profile,
        'enrollments' => $enrollments,
    ];
}

// ── Public: push a student's full current state to OCES ─────
// This is the core sync call. Builds the student's current state
// from classroom_db2 and POSTs it.
//
// If the student has NO NSTP enrollment, instead pushes a deletion
// to OCES (so OCES deactivates them if they were previously synced).
//
// Safe to call repeatedly — idempotent.
// Returns true if push succeeded, false otherwise (never throws).
function push_student_to_oces($conn, $student_id) {
    $payload = _build_student_payload_for_oces($conn, $student_id);
    if ($payload === null) {
        error_log("[sync_to_oces] student_id='$student_id' not found — skipping push");
        return false;
    }

    // If the student has no NSTP enrollment, push a deletion instead.
    // This deactivates them in OCES if they were previously synced.
    if (empty($payload['enrollments'])) {
        return push_student_deletion_to_oces($student_id);
    }

    $r = _oces_post('receive_student.php', $payload);
    return $r['ok'];
}

// ── Public: tell OCES a student was deleted ──────────────────
// Soft-deactivates the user + all their enrollments in OCES.
// Does NOT delete the user row (preserves history).
function push_student_deletion_to_oces($student_id) {
    $student_id = trim((string)$student_id);
    if ($student_id === '') return false;
    $r = _oces_post('receive_student_deletion.php', ['student_id' => $student_id]);
    return $r['ok'];
}
