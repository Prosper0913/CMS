<?php
// ============================================================
//  GET /api/masterlist.php
//  Header: X-API-Key: <key>
//
//  Works for ANY integrated system, not just one — see the
//  "MULTI-SYSTEM DESIGN" comment in api/_api_common.php. Each API
//  key is scoped to one course (or unrestricted), and that's what
//  determines what a given key can see here — nothing in this
//  file is hardcoded to a specific course/system.
//
//  Two ways to call this:
//
//  1) By CMS's internal IDs (original, still supported):
//       ?section_id=15&subject_id=11
//
//  2) By human-readable name (for callers that don't know/store
//     CMS's internal IDs):
//       ?course=FPST&subject_name=Food+Test
//     This can match MORE THAN ONE section if the same subject
//     name is taught to multiple sections of that course, so this
//     mode returns a `groups` array — one entry per matching
//     section+subject pair, each with its own roster.
//
//  Both modes only ever return data for a course the calling key
//  is authorized for (enforced server-side via authorize_course()
//  in _api_common.php), so a key can only ever pull rosters from
//  its own course, never any other department's students.
// ============================================================
require_once __DIR__ . '/_api_common.php';
$endpoint = 'masterlist';
$key = authenticate_api_key($conn, $endpoint);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_fail(405, 'Only GET is supported.', $conn, $endpoint, $key['id']);
}

function build_roster($conn, $section_id, $subject_id) {
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
    while ($row = $res->fetch_assoc()) {
        $mi = trim((string)$row['middle_initial']);
        $full_name = trim($row['last_name'] . ', ' . $row['first_name'] . ($mi !== '' ? ' ' . $mi . '.' : ''));
        $students[] = [
            'student_id'     => $row['student_id'],
            'full_name'      => $full_name,
            'first_name'     => $row['first_name'],
            'middle_initial' => $row['middle_initial'],
            'last_name'      => $row['last_name'],
        ];
    }
    return $students;
}

$section_id  = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$subject_id  = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$course      = isset($_GET['course']) ? trim((string)$_GET['course']) : '';
$subject_name = isset($_GET['subject_name']) ? trim((string)$_GET['subject_name']) : '';

// ── Mode 1: by internal IDs ─────────────────────────────────
if ($section_id && $subject_id) {
    $section = require_authorized_section($conn, $key, $section_id, $endpoint);

    $subj = $conn->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE id = ? LIMIT 1");
    $subj->bind_param('i', $subject_id);
    $subj->execute();
    $subject = $subj->get_result()->fetch_assoc();
    if (!$subject) {
        api_fail(404, 'Subject not found.', $conn, $endpoint, $key['id']);
    }

    $students = build_roster($conn, $section_id, $subject_id);

    echo json_encode([
        'success' => true,
        'section' => ['id' => (int)$section['id'], 'name' => $section['section_name']],
        'subject' => ['id' => (int)$subject['id'], 'code' => $subject['subject_code'], 'name' => $subject['subject_name']],
        'count'   => count($students),
        'students' => $students,
    ]);
    exit;
}

// ── Mode 2: by course + subject name ────────────────────────
if ($course !== '' && $subject_name !== '') {
    // Generic check: this key must be authorized for the COURSE
    // being requested — not hardcoded to any one integrated system.
    // A key scoped to "BSIT" gets a 403 here if it asks for course
    // "FPST", and vice versa. Unrestricted keys (allowed_course
    // blank) pass straight through.
    authorize_course($key, $course, $endpoint, $conn);

    // Every subject taught under a section of the requested course,
    // matching the given subject name (case-insensitive). subjects.section
    // stores the section NAME as text, so this joins on that to find
    // the section id.
    $stmt = $conn->prepare(
        "SELECT sec.id AS section_id, sec.section_name, sec.course,
                sub.id AS subject_id, sub.subject_code, sub.subject_name
         FROM subjects sub
         JOIN sections sec ON sec.section_name = sub.section
         WHERE UPPER(TRIM(sec.course)) = UPPER(TRIM(?))
           AND UPPER(TRIM(sub.subject_name)) = UPPER(TRIM(?))
         ORDER BY sec.section_name"
    );
    $stmt->bind_param('ss', $course, $subject_name);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($matches)) {
        api_fail(404, "No subject named \"$subject_name\" found under course \"$course\".", $conn, $endpoint, $key['id']);
    }

    $groups = [];
    foreach ($matches as $m) {
        $students = build_roster($conn, (int)$m['section_id'], (int)$m['subject_id']);
        $groups[] = [
            'section'  => ['id' => (int)$m['section_id'], 'name' => $m['section_name']],
            'subject'  => ['id' => (int)$m['subject_id'], 'code' => $m['subject_code'], 'name' => $m['subject_name']],
            'count'    => count($students),
            'students' => $students,
        ];
    }

    echo json_encode([
        'success' => true,
        'query'   => ['course' => $course, 'subject_name' => $subject_name],
        'groups'  => $groups,
    ]);
    exit;
}

api_fail(400, 'Provide either (section_id and subject_id) or (course and subject_name) as query parameters.', $conn, $endpoint, $key['id']);
