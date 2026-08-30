<?php
// ============================================================
//  classroomv2/includes/sync_to_guidance.php
//  Push-side helper for CMS → Guidance Appointment System sync.
//
//  Unlike the FPST/Tooltrack sync (which is course-filtered),
//  this syncs ALL students — every student in classroom_db2
//  should have a corresponding user in guidance_appointment_system.
//
//  CRITICAL: this helper NEVER throws. Every admin action that
//  calls it must complete successfully even if Guidance is down.
//  Failures are logged via error_log() and silently swallowed.
//
//  Usage (from admin/teacher pages):
//    require_once __DIR__ . '/../includes/sync_to_guidance.php';
//    push_student_to_guidance($conn, $student_id);
//    push_student_deletion_to_guidance($student_id);
//
//  What gets pushed:
//    - Student's basic info (id_number, username, password_hash,
//      name, email)
//    - Student's profile (course, year_level, section — from
//      their FIRST section membership)
//    - Student's subject enrollments (every active
//      subject_enrollments row, with section + subject metadata)
//
//  The Guidance receive endpoint upserts all of this and
//  deactivates any enrollment rows not in the payload (so
//  removals propagate automatically).
// ============================================================

// ── Config — EDIT THESE ──────────────────────────────────────
// Guidance's base URL — where receive_student.php and
// receive_student_deletion.php live. Adjust if Guidance is
// served from a different host/port/path.
const GUIDANCE_API_BASE = 'http://localhost/guidance-system';

// Shared secret — must match $SYNC_RECEIVE_KEY in
// guidance/_receive_common.php. Same value as the Tooltrack
// sync for simplicity (one secret for all integrations).
const GUIDANCE_SYNC_KEY = '71e9aa8ae68de3a3b03523526d864ebd6bcfb0253dd3f3532c2c59155fff2353';

// ── Internal: HTTP POST a JSON payload to Guidance ───────────
// Returns ['ok'=>bool, 'http'=>int, 'body'=>string, 'json'=>array|null].
// Never throws.
function _guidance_post($path, $payload) {
    $url = GUIDANCE_API_BASE . '/' . ltrim($path, '/');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Sync-Key: ' . GUIDANCE_SYNC_KEY,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log("[sync_to_guidance] curl error POST $url: $err");
        return ['ok' => false, 'http' => 0, 'body' => $err, 'json' => null];
    }
    $decoded = json_decode($raw, true);
    $ok = ($http === 200) && is_array($decoded) && !empty($decoded['success']);
    if (!$ok) {
        error_log(sprintf(
            "[sync_to_guidance] non-200 from %s (HTTP %d): %s",
            $url, $http, substr($raw, 0, 500)
        ));
    }
    return ['ok' => $ok, 'http' => $http, 'body' => $raw, 'json' => $decoded];
}

// ── Internal: load a student's full state from classroom_db2 ─
// Builds the complete payload for push_student_to_guidance().
// Returns null if the student doesn't exist.
function _build_student_payload_for_guidance($conn, $student_id) {
    $student_id = trim((string)$student_id);
    if ($student_id === '') return null;

    // ── Load student ──
    $st = $conn->prepare(
        "SELECT student_id, username, password, first_name, last_name,
                middle_initial, email
         FROM students WHERE student_id = ? LIMIT 1"
    );
    $st->bind_param('s', $student_id);
    $st->execute();
    $student = $st->get_result()->fetch_assoc();
    if (!$student) return null;

    // ── Load their section memberships ──
    // A student can be in multiple sections. We use the FIRST one
    // (by section_name ASC) for the student_profiles.section field.
    // The full list is encoded in the enrollments below.
    $sec = $conn->prepare(
        "SELECT sec.id, sec.section_name, sec.course, sec.year_level, sec.school_year
         FROM section_students ss
         JOIN sections sec ON sec.id = ss.section_id
         WHERE ss.student_id = ?
         ORDER BY sec.section_name ASC"
    );
    $sec->bind_param('s', $student_id);
    $sec->execute();
    $sections = $sec->get_result()->fetch_all(MYSQLI_ASSOC);

    // Use the first section for the profile fields
    $primary_section = $sections[0] ?? null;
    $profile = [
        'course'     => $primary_section['course']     ?? '',
        'year_level' => $primary_section ? (string)$primary_section['year_level'] : '',
        'section'    => $primary_section['section_name'] ?? '',
    ];

    // ── Load their subject enrollments ──
    // This is what populates student_enrollments in Guidance.
    // Only active enrollments (rows that exist in subject_enrollments)
    // are included — deleted enrollments are omitted, which causes
    // Guidance to deactivate the corresponding rows.
    $enr = $conn->prepare(
        "SELECT se.section_id, se.subject_id,
                sec.section_name, sec.course,
                sub.subject_code, sub.subject_name
         FROM subject_enrollments se
         JOIN sections sec  ON sec.id = se.section_id
         JOIN subjects sub  ON sub.id = se.subject_id
         WHERE se.student_id = ?
           AND se.section_id IS NOT NULL
         ORDER BY sec.section_name, sub.subject_code"
    );
    $enr->bind_param('s', $student_id);
    $enr->execute();
    $enrollments = [];
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

    return [
        'student' => [
            'student_id'      => $student['student_id'],
            'username'        => $student['username'],
            'password_hash'   => $student['password'],  // CMS stores bcrypt hash in students.password
            'first_name'      => $student['first_name'],
            'last_name'       => $student['last_name'],
            'middle_initial'  => $student['middle_initial'] ?? '',
            'email'           => $student['email'] ?? '',
        ],
        'profile' => $profile,
        'enrollments' => $enrollments,
    ];
}

// ── Public: push a student's full current state to Guidance ──
// This is the core sync call. Builds the student's current state
// from classroom_db2 and POSTs it. Guidance upserts the user +
// student_profiles + student_enrollments, and deactivates any
// enrollment rows not in the payload.
//
// Safe to call repeatedly — idempotent.
// Returns true if push succeeded, false otherwise (never throws).
function push_student_to_guidance($conn, $student_id) {
    $payload = _build_student_payload_for_guidance($conn, $student_id);
    if ($payload === null) {
        error_log("[sync_to_guidance] student_id='$student_id' not found — skipping push");
        return false;
    }
    $r = _guidance_post('receive_student.php', $payload);
    return $r['ok'];
}

// ── Public: tell Guidance a student was deleted ──────────────
// Soft-deactivates the user + all their enrollments in Guidance.
// Does NOT delete the user row (preserves appointment/referral history).
function push_student_deletion_to_guidance($student_id) {
    $student_id = trim((string)$student_id);
    if ($student_id === '') return false;
    $r = _guidance_post('receive_student_deletion.php', ['student_id' => $student_id]);
    return $r['ok'];
}