<?php
// ============================================================
//  classroomv2/includes/sync_to_tooltrack.php
//  Push-side helper. Called by admin/sections.php and
//  admin/students.php whenever a roster-affecting change
//  happens — builds the affected section+subject roster from
//  classroom_db2 and POSTs it to tooltrack's receive endpoint.
//
//  CRITICAL: this helper NEVER throws. Every admin action that
//  calls it must complete successfully even if tooltrack is
//  down. Failures are logged via error_log() and silently
//  swallowed — the next admin action will retry naturally.
//
//  FPST filter (per user's #5 answer): pushes ONLY fire when
//  the affected section's course = 'FPST' (case-insensitive).
//  All other courses are silently skipped — no log noise.
//
//  Usage (from admin pages):
//    require_once __DIR__ . '/../includes/sync_to_tooltrack.php';
//    push_section_subject_to_tooltrack($conn, $section_id, $subject_id);
//    push_all_fpst_subjects_for_section($conn, $section_id);
//    push_all_fpst_subjects_for_student($conn, $student_id);
//    push_student_deletion_to_tooltrack($student_id);
// ============================================================

// ── Config — EDIT THESE ──────────────────────────────────────
// Tooltrack's base URL — where its receive_masterlist.php and
// receive_student_deletion.php live. Adjust if tooltrack is
// served from a different host/port/path.
const TOOLTRACK_API_BASE = 'http://localhost/tooltrack';

// Shared secret — must match $TOOLTRACK_RECEIVE_KEY in
// tooltrack/_receive_common.php. Same value as CMS_API_KEY in
// sync_fpst_masterlist.php so there's only one secret to manage.
const TOOLTRACK_RECEIVE_KEY = 'a888034173f952595606d57bf49804937dda9faea3d60c89426a7d0c4a239eda';

// Course filter — only sections whose course matches this
// (case-insensitive) are synced. Set to '' to sync ALL sections
// regardless of course (subject filter below is what matters).
const SYNC_COURSE_FILTER = '';

// Subject filter — only subjects whose subject_name matches this
// (case-insensitive) are synced to tooltrack. This is the PRIMARY
// filter: any section that has a subject named 'Foods 9' will sync.
const SYNC_SUBJECT_FILTER = 'Foods 9';

// ── Internal: HTTP POST a JSON payload to tooltrack ──────────
// Returns ['ok'=>bool, 'http'=>int, 'body'=>string, 'json'=>array|null].
// Never throws.
function _tooltrack_post($path, $payload) {
    $url = TOOLTRACK_API_BASE . '/' . ltrim($path, '/');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Tooltrack-Key: ' . TOOLTRACK_RECEIVE_KEY,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log("[sync_to_tooltrack] curl error POST $url: $err");
        return ['ok' => false, 'http' => 0, 'body' => $err, 'json' => null];
    }
    $decoded = json_decode($raw, true);
    $ok = ($http === 200) && is_array($decoded) && !empty($decoded['success']);
    if (!$ok) {
        error_log(sprintf(
            "[sync_to_tooltrack] non-200 from %s (HTTP %d): %s",
            $url, $http, substr($raw, 0, 500)
        ));
    }
    return ['ok' => $ok, 'http' => $http, 'body' => $raw, 'json' => $decoded];
}

// ── Internal: build the roster for a section+subject ─────────
// Mirrors the build_roster() query in api/masterlist.php.
function _build_roster_for_sync($conn, $section_id, $subject_id) {
    $stmt = $conn->prepare(
        "SELECT s.student_id, s.first_name, s.middle_initial, s.last_name
         FROM subject_enrollments se
         JOIN students s ON s.student_id = se.student_id
         WHERE se.section_id = ? AND se.subject_id = ?
         ORDER BY s.last_name, s.first_name"
    );
    $stmt->bind_param('ii', $section_id, $subject_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $students = [];
    while ($r = $res->fetch_assoc()) {
        $students[] = [
            'student_id'     => $r['student_id'],
            'first_name'     => $r['first_name'],
            'middle_initial' => $r['middle_initial'] ?? '',
            'last_name'      => $r['last_name'],
        ];
    }
    return $students;
}

// ── Internal: fetch section + subject metadata ───────────────
function _load_section_for_sync($conn, $section_id) {
    $stmt = $conn->prepare(
        "SELECT id, section_name, course FROM sections WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $section_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc(); // null if not found
}

function _load_subject_for_sync($conn, $subject_id) {
    $stmt = $conn->prepare(
        "SELECT id, subject_code, subject_name FROM subjects WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $subject_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// ── Internal: is this section's course the one we sync? ──────
// If SYNC_COURSE_FILTER is empty, ALL sections pass (no course filter).
function _is_sync_course($section_row) {
    if (!$section_row) return false;
    if (SYNC_COURSE_FILTER === '') return true;
    $course = strtoupper(trim((string)($section_row['course'] ?? '')));
    return $course === strtoupper(SYNC_COURSE_FILTER);
}

// ── Internal: is this subject's name one we sync? ────────────
// If SYNC_SUBJECT_FILTER is empty, all subjects pass (old behavior).
// Otherwise, only subjects whose subject_name matches the filter
// (case-insensitive) pass.
function _is_sync_subject($subject_row) {
    if (!$subject_row) return false;
    if (SYNC_SUBJECT_FILTER === '') return true;
    $name = strtoupper(trim((string)($subject_row['subject_name'] ?? '')));
    return $name === strtoupper(trim(SYNC_SUBJECT_FILTER));
}

// ── Public: push one section+subject's roster ────────────────
// This is the core sync call. Builds the current roster from
// classroom_db2 and POSTs it. Tooltrack upserts and deactivates
// as needed. Safe to call repeatedly — idempotent.
//
// Returns true if push succeeded, false otherwise (never throws).
function push_section_subject_to_tooltrack($conn, $section_id, $subject_id) {
    $section_id = (int)$section_id;
    $subject_id = (int)$subject_id;
    if ($section_id <= 0 || $subject_id <= 0) return false;

    $section = _load_section_for_sync($conn, $section_id);
    if (!_is_sync_course($section)) {
        // Non-FPST section — silently skip per the user's #5 answer.
        return true;
    }
    $subject = _load_subject_for_sync($conn, $subject_id);
    if (!$subject) {
        error_log("[sync_to_tooltrack] subject_id=$subject_id not found — skipping push");
        return false;
    }
    // Subject name filter — if SYNC_SUBJECT_FILTER is set, only
    // push subjects matching that name (e.g. 'Foods 9'). Other
    // subjects are silently skipped.
    if (!_is_sync_subject($subject)) {
        return true;
    }

    $students = _build_roster_for_sync($conn, $section_id, $subject_id);

    $payload = [
        'section' => [
            'id'     => (int)$section['id'],
            'name'   => $section['section_name'],
            'course' => $section['course'],
        ],
        'subject' => [
            'id'     => (int)$subject['id'],
            'code'   => $subject['subject_code'],
            'name'   => $subject['subject_name'],
        ],
        'students' => $students,
    ];

    $r = _tooltrack_post('receive_masterlist.php', $payload);
    return $r['ok'];
}

// ── Public: push every FPST subject taught to a section ──────
// Used after a student is added/removed from a section — we
// don't know which specific subject the admin cares about, so
// we push them all (the FPST ones). Cheap because each push is
// a few KB and runs async from the admin's POV.
//
// Returns the number of subjects successfully pushed.
function push_all_fpst_subjects_for_section($conn, $section_id) {
    $section_id = (int)$section_id;
    if ($section_id <= 0) return 0;

    $section = _load_section_for_sync($conn, $section_id);
    if (!_is_sync_course($section)) return 0;

    // Find every subject taught to this section. subjects.section
    // stores the section NAME as text, so we match on that.
    // If SYNC_SUBJECT_FILTER is set, also filter by subject_name.
    if (SYNC_SUBJECT_FILTER !== '') {
        $stmt = $conn->prepare(
            "SELECT id FROM subjects
             WHERE TRIM(section) = TRIM(?)
               AND UPPER(TRIM(subject_name)) = UPPER(TRIM(?))
             ORDER BY id"
        );
        $subj_filter = SYNC_SUBJECT_FILTER;
        $stmt->bind_param('ss', $section['section_name'], $subj_filter);
    } else {
        $stmt = $conn->prepare(
            "SELECT id FROM subjects WHERE TRIM(section) = TRIM(?) ORDER BY id"
        );
        $stmt->bind_param('s', $section['section_name']);
    }
    $stmt->execute();
    $subject_ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');

    $pushed = 0;
    foreach ($subject_ids as $sid) {
        if (push_section_subject_to_tooltrack($conn, $section_id, $sid)) {
            $pushed++;
        }
    }
    return $pushed;
}

// ── Public: push every FPST subject a student is enrolled in ─
// Used after a student's name is updated — we want tooltrack to
// see the new name. Pushes each FPST section+subject pair the
// student is currently in.
//
// Returns the number of (section, subject) pairs successfully pushed.
function push_all_fpst_subjects_for_student($conn, $student_id) {
    $student_id = trim((string)$student_id);
    if ($student_id === '') return 0;

    // Find every (section_id, subject_id) the student is enrolled in.
    // If SYNC_SUBJECT_FILTER is set, filter by subject_name.
    // If SYNC_COURSE_FILTER is also set, filter by section course too.
    if (SYNC_SUBJECT_FILTER !== '' && SYNC_COURSE_FILTER !== '') {
        $stmt = $conn->prepare(
            "SELECT se.section_id, se.subject_id
             FROM subject_enrollments se
             JOIN sections sec ON sec.id = se.section_id
             JOIN subjects sub ON sub.id = se.subject_id
             WHERE se.student_id = ?
               AND se.section_id IS NOT NULL
               AND UPPER(TRIM(sec.course)) = UPPER(?)
               AND UPPER(TRIM(sub.subject_name)) = UPPER(TRIM(?))
             ORDER BY se.section_id, se.subject_id"
        );
        $cf = SYNC_COURSE_FILTER;
        $sf = SYNC_SUBJECT_FILTER;
        $stmt->bind_param('sss', $student_id, $cf, $sf);
    } elseif (SYNC_SUBJECT_FILTER !== '') {
        $stmt = $conn->prepare(
            "SELECT se.section_id, se.subject_id
             FROM subject_enrollments se
             JOIN subjects sub ON sub.id = se.subject_id
             WHERE se.student_id = ?
               AND se.section_id IS NOT NULL
               AND UPPER(TRIM(sub.subject_name)) = UPPER(TRIM(?))
             ORDER BY se.section_id, se.subject_id"
        );
        $sf = SYNC_SUBJECT_FILTER;
        $stmt->bind_param('ss', $student_id, $sf);
    } elseif (SYNC_COURSE_FILTER !== '') {
        $stmt = $conn->prepare(
            "SELECT se.section_id, se.subject_id
             FROM subject_enrollments se
             JOIN sections sec ON sec.id = se.section_id
             WHERE se.student_id = ?
               AND se.section_id IS NOT NULL
               AND UPPER(TRIM(sec.course)) = UPPER(?)
             ORDER BY se.section_id, se.subject_id"
        );
        $cf = SYNC_COURSE_FILTER;
        $stmt->bind_param('ss', $student_id, $cf);
    } else {
        $stmt = $conn->prepare(
            "SELECT se.section_id, se.subject_id
             FROM subject_enrollments se
             WHERE se.student_id = ?
               AND se.section_id IS NOT NULL
             ORDER BY se.section_id, se.subject_id"
        );
        $stmt->bind_param('s', $student_id);
    }
    $stmt->execute();
    $pairs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $pushed = 0;
    foreach ($pairs as $p) {
        if (push_section_subject_to_tooltrack($conn, (int)$p['section_id'], (int)$p['subject_id'])) {
            $pushed++;
        }
    }
    return $pushed;
}

// ── Public: tell tooltrack a student was deleted ─────────────
// Soft-deactivates the borrower + all their enrollments in
// tooltrack. Does NOT delete the borrower row (preserves
// transaction history).
function push_student_deletion_to_tooltrack($student_id) {
    $student_id = trim((string)$student_id);
    if ($student_id === '') return false;
    $r = _tooltrack_post('receive_student_deletion.php', ['student_id' => $student_id]);
    return $r['ok'];
}

// ── Public: push every FPST section for a subject ────────────
// Used by teacher/subject_view.php — we only know the subject_id
// there (the subject's section is stored as TEXT in subjects.section,
// not as a section_id FK). Looks up every section whose name matches
// subjects.section, filters to FPST via push_section_subject_to_tooltrack,
// and pushes each one.
//
// Handles the duplicate-section-name case correctly: if multiple
// sections share the same name (e.g. an original + a teacher clone),
// each one is pushed.
//
// Returns the number of (section, subject) pairs successfully pushed.
function push_subject_to_tooltrack($conn, $subject_id) {
    $subject_id = (int)$subject_id;
    if ($subject_id <= 0) return 0;

    $subject = _load_subject_for_sync($conn, $subject_id);
    if (!$subject) return 0;

    // Subject name filter — if set, only push subjects matching.
    if (!_is_sync_subject($subject)) return 0;

    $section_text = trim((string)$subject['section']);
    if ($section_text === '') return 0;

    // Find every section whose name matches subjects.section.
    // There can be more than one — sections can share names
    // (e.g. a teacher-cloned section inherits the original's name).
    $stmt = $conn->prepare(
        "SELECT id FROM sections WHERE TRIM(section_name) = TRIM(?) ORDER BY id"
    );
    $stmt->bind_param('s', $section_text);
    $stmt->execute();
    $section_ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');

    $pushed = 0;
    foreach ($section_ids as $sid) {
        if (push_section_subject_to_tooltrack($conn, (int)$sid, $subject_id)) {
            $pushed++;
        }
    }
    return $pushed;
}

// ── Public: auto-enroll a student in every FPST subject for a section
// Mirrors the bulk-enroll flow that runs at subject_section_request
// approval time (admin/sections.php respond_request). Without this,
// a student added to a section AFTER approval never gets a
// subject_enrollments row, so the masterlist push doesn't pick
// them up and they never appear in tooltrack.
//
// Behavior:
//   - Loads the section; if course != FPST, returns 0 silently.
//   - Finds every subject whose `section` text matches the section's name.
//   - INSERT IGNORE INTO subject_enrollments (subject_id, student_id, section_id)
//     for each — existing rows aren't disturbed.
//   - INSERT IGNORE INTO subject_grades (subject_id, student_id) for each —
//     mirrors the approval flow so the student has a grade row.
//   - Returns the count of subjects the student was enrolled in.
//
// After calling this, the caller should call push_all_fpst_subjects_for_section()
// to actually sync the updated rosters to tooltrack.
//
// Side effect: the student will now appear in every FPST subject's
// roster in teacher/subject_view.php. This is the same thing that
// happens when a teacher clicks "Enroll section" — we're just doing
// it automatically on section-add.
function auto_enroll_student_in_fpst_subjects($conn, $section_id, $student_id) {
    $section_id = (int)$section_id;
    $student_id = trim((string)$student_id);
    if ($section_id <= 0 || $student_id === '') return 0;

    $section = _load_section_for_sync($conn, $section_id);
    if (!_is_sync_course($section)) return 0;

    // Find every subject taught to this section (subjects.section is
    // text, matches section_name). If SYNC_SUBJECT_FILTER is set,
    // only enroll in matching subjects (e.g. 'Foods 9' only).
    if (SYNC_SUBJECT_FILTER !== '') {
        $stmt = $conn->prepare(
            "SELECT id FROM subjects
             WHERE TRIM(section) = TRIM(?)
               AND UPPER(TRIM(subject_name)) = UPPER(TRIM(?))
             ORDER BY id"
        );
        $subj_filter = SYNC_SUBJECT_FILTER;
        $stmt->bind_param('ss', $section['section_name'], $subj_filter);
    } else {
        $stmt = $conn->prepare(
            "SELECT id FROM subjects WHERE TRIM(section) = TRIM(?) ORDER BY id"
        );
        $stmt->bind_param('s', $section['section_name']);
    }
    $stmt->execute();
    $subject_ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');

    $enrolled = 0;
    foreach ($subject_ids as $subject_id) {
        // subject_enrollments (the row that drives the masterlist query)
        $e1 = $conn->prepare(
            "INSERT IGNORE INTO subject_enrollments (subject_id, student_id, section_id) VALUES (?, ?, ?)"
        );
        $e1->bind_param('isi', $subject_id, $student_id, $section_id);
        $e1->execute();

        // subject_grades (mirrors the approval flow so the student has
        // a grade row — same pattern admin/sections.php uses)
        $e2 = $conn->prepare(
            "INSERT IGNORE INTO subject_grades (subject_id, student_id) VALUES (?, ?)"
        );
        $e2->bind_param('is', $subject_id, $student_id);
        $e2->execute();

        $enrolled++;
    }
    return $enrolled;
}

// ── Public: auto-UNenroll a student from every FPST subject for a section
// SYMMETRIC with auto_enroll_student_in_fpst_subjects(). When a student
// is removed from a section in admin/sections.php, we also delete their
// subject_enrollments rows for every FPST subject taught to that section
// — otherwise they'd still appear in teacher/subject_view.php's subject
// rosters, and the subsequent tooltrack push would still include them
// (so tooltrack wouldn't deactivate their enrollment either).
//
// Behavior:
//   - Loads the section; if course != FPST, returns 0 silently.
//   - Finds every subject whose `section` text matches the section's name.
//   - DELETE FROM subject_enrollments WHERE subject_id=? AND student_id=?
//     AND section_id=? for each — scoped by section_id so we don't touch
//     any enrollment the student might have in the same subject under a
//     different section (rare but possible).
//   - subject_grades rows are PRESERVED — same behavior as
//     teacher/subject_view.php's unenroll_student (the success message
//     there says "Their scores and grades are preserved"). If the student
//     is re-enrolled later, INSERT IGNORE will skip the existing grade
//     row, so old scores survive.
//   - Returns the count of subjects processed.
//
// After calling this, the caller should call push_all_fpst_subjects_for_section()
// so tooltrack sees the updated (smaller) rosters and deactivates the
// removed student's enrollment rows.
function auto_unenroll_student_from_fpst_subjects($conn, $section_id, $student_id) {
    $section_id = (int)$section_id;
    $student_id = trim((string)$student_id);
    if ($section_id <= 0 || $student_id === '') return 0;

    $section = _load_section_for_sync($conn, $section_id);
    if (!_is_sync_course($section)) return 0;

    // Find every subject taught to this section. If SYNC_SUBJECT_FILTER
    // is set, only unenroll from matching subjects (e.g. 'Foods 9' only).
    if (SYNC_SUBJECT_FILTER !== '') {
        $stmt = $conn->prepare(
            "SELECT id FROM subjects
             WHERE TRIM(section) = TRIM(?)
               AND UPPER(TRIM(subject_name)) = UPPER(TRIM(?))
             ORDER BY id"
        );
        $subj_filter = SYNC_SUBJECT_FILTER;
        $stmt->bind_param('ss', $section['section_name'], $subj_filter);
    } else {
        $stmt = $conn->prepare(
            "SELECT id FROM subjects WHERE TRIM(section) = TRIM(?) ORDER BY id"
        );
        $stmt->bind_param('s', $section['section_name']);
    }
    $stmt->execute();
    $subject_ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');

    $unenrolled = 0;
    foreach ($subject_ids as $subject_id) {
        // Delete the enrollment row scoped to THIS section, so a student
        // who happens to be enrolled in the same subject under a different
        // section isn't affected. subject_grades is intentionally left
        // untouched — see the function header comment.
        $del = $conn->prepare(
            "DELETE FROM subject_enrollments
             WHERE subject_id = ? AND student_id = ? AND section_id = ?"
        );
        $del->bind_param('isi', $subject_id, $student_id, $section_id);
        $del->execute();
        $unenrolled++;
    }
    return $unenrolled;
}
