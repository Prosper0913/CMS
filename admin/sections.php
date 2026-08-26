<?php
// ============================================================
//  admin/sections.php
//  Sections are now created and rostered by admin only (teachers
//  no longer own or create sections/students — see
//  teacher/add_subject.php for how teachers request a section's
//  roster be enrolled into their subject).
//
//  Features:
//    - Create / edit / delete sections
//    - Add / remove students from a section's roster
//    - Approve / deny pending subject-section enrollment requests
//      from teachers (this is what actually creates
//      subject_enrollments rows once approved)
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';
// Push-based sync to tooltrack (no-op for non-FPST sections, never throws).
// See includes/sync_to_tooltrack.php for the full design.
require_once __DIR__ . '/../includes/sync_to_tooltrack.php';
require_once __DIR__ . '/../includes/sync_to_guidance.php';

$admin_id    = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// ── CREATE section ───────────────────────────────────────────
if (isset($_POST['create_section'])) {
    $name    = trim($_POST['section_name']);
    $desc    = trim($_POST['section_desc'] ?? '');
    $course  = trim($_POST['course'] ?? '');
    $year    = (int)($_POST['year_level'] ?? 1);
    $sy      = trim($_POST['school_year'] ?? '');

    if ($name === '') {
        $error_msg = "Section name is required.";
    } else {
        $chk = $conn->prepare("SELECT id FROM sections WHERE section_name = ? LIMIT 1");
        $chk->bind_param('s', $name);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "A section named <strong>" . htmlspecialchars($name) . "</strong> already exists.";
        } else {
            $ins = $conn->prepare(
                "INSERT INTO sections (section_name, description, course, year_level, school_year, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $ins->bind_param('sssisi', $name, $desc, $course, $year, $sy, $admin_id);
            $ins->execute();
            header("Location: sections.php?sec=" . $conn->insert_id . "&msg=created");
            exit;
        }
    }
}

// ── UPDATE section ───────────────────────────────────────────
if (isset($_POST['update_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $name   = trim($_POST['section_name']);
    $desc   = trim($_POST['section_desc'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $year   = (int)($_POST['year_level'] ?? 1);
    $sy     = trim($_POST['school_year'] ?? '');

    if ($name === '') {
        $error_msg = "Section name is required.";
    } else {
        $upd = $conn->prepare(
            "UPDATE sections SET section_name=?, description=?, course=?, year_level=?, school_year=? WHERE id=?"
        );
        $upd->bind_param('sssisi', $name, $desc, $course, $year, $sy, $sec_id);
        $upd->execute();
        header("Location: sections.php?sec={$sec_id}&msg=updated");
        exit;
    }
}

// ── DELETE section ───────────────────────────────────────────
if (isset($_POST['delete_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $conn->begin_transaction();
    try {
        $d1 = $conn->prepare("DELETE FROM section_students WHERE section_id = ?");
        $d1->bind_param('i', $sec_id); $d1->execute();
        $d2 = $conn->prepare("DELETE FROM subject_section_requests WHERE section_id = ?");
        $d2->bind_param('i', $sec_id); $d2->execute();
        $d3 = $conn->prepare("DELETE FROM sections WHERE id = ?");
        $d3->bind_param('i', $sec_id); $d3->execute();
        $conn->commit();
        // Note: subject_enrollments rows tagged with this section_id are left
        // intact — students stay enrolled in whatever subjects they're in;
        // only the section tag becomes orphaned.
        header("Location: sections.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Could not delete: " . $e->getMessage();
    }
}

// ── ADD student to section ───────────────────────────────────
if (isset($_POST['add_to_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $sid    = trim($_POST['student_id']);
    if ($sid === '') {
        $error_msg = "Please select a student.";
    } else {
        $chk = $conn->prepare("SELECT id FROM section_students WHERE section_id=? AND student_id=? LIMIT 1");
        $chk->bind_param('is', $sec_id, $sid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "Student is already in this section.";
        } else {
            $ins = $conn->prepare("INSERT INTO section_students (section_id, student_id) VALUES (?, ?)");
            $ins->bind_param('is', $sec_id, $sid);
            $ins->execute();
            // ── Auto-enroll + push to tooltrack: if this is an FPST section,
            // the student needs subject_enrollments rows for the masterlist
            // query to pick them up (section_students alone isn't enough).
            // auto_enroll_student_in_fpst_subjects() mirrors the approval-time
            // bulk-enroll flow — INSERT IGNORE for every FPST subject taught
            // to this section. Then push_all_fpst_subjects_for_section() sends
            // the updated rosters to tooltrack. Non-FPST sections skip both
            // calls. Failures never break the add.
            auto_enroll_student_in_fpst_subjects($conn, $sec_id, $sid);
            push_all_fpst_subjects_for_section($conn, $sec_id);
            // Push to Guidance: ALL students sync
            push_student_to_guidance($conn, $sid);
            header("Location: sections.php?sec={$sec_id}&msg=student_added");
            exit;
        }
    }
}

// ── REMOVE student from section ──────────────────────────────
if (isset($_POST['remove_from_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $sid    = trim($_POST['student_id']);
    $del = $conn->prepare("DELETE FROM section_students WHERE section_id=? AND student_id=?");
    $del->bind_param('is', $sec_id, $sid);
    $del->execute();
    // ── Auto-UNenroll + push to tooltrack: SYMMETRIC with the add flow.
    // If this is an FPST section, also delete the student's
    // subject_enrollments rows for every FPST subject taught to this
    // section — otherwise they'd still appear in teacher/subject_view.php's
    // subject rosters, and the masterlist push would still include them
    // (so tooltrack wouldn't deactivate their enrollment either).
    // subject_grades rows are PRESERVED (matches teacher/subject_view.php's
    // unenroll behavior — grades survive so a re-enrolled student doesn't
    // lose old scores). Then push_all_fpst_subjects_for_section() sends
    // the updated (smaller) rosters to tooltrack. Non-FPST sections skip
    // both calls. Failures never break the removal.
    auto_unenroll_student_from_fpst_subjects($conn, $sec_id, $sid);
    push_all_fpst_subjects_for_section($conn, $sec_id);
    header("Location: sections.php?sec={$sec_id}&msg=student_removed");
    exit;
}

// ── APPROVE / DENY a subject-section enrollment request ───────
if (isset($_POST['respond_request'])) {
    $req_id   = (int)$_POST['request_id'];
    $decision = $_POST['decision'] === 'approve' ? 'approved' : 'denied';

    $rq = $conn->prepare("SELECT * FROM subject_section_requests WHERE id=? AND status='pending'");
    $rq->bind_param('i', $req_id);
    $rq->execute();
    $reqrow = $rq->get_result()->fetch_assoc();

    if (!$reqrow) {
        $error_msg = "Request not found or already handled.";
    } else {
        $conn->begin_transaction();
        try {
            if ($decision === 'approved') {
                // Enroll every student currently in this section into the subject
                $sq = $conn->prepare("SELECT student_id FROM section_students WHERE section_id=?");
                $sq->bind_param('i', $reqrow['section_id']);
                $sq->execute();
                $srows = $sq->get_result();
                while ($sr = $srows->fetch_assoc()) {
                    $e1 = $conn->prepare(
                        "INSERT IGNORE INTO subject_enrollments (subject_id,student_id,section_id) VALUES (?,?,?)"
                    );
                    $e1->bind_param("isi", $reqrow['subject_id'], $sr['student_id'], $reqrow['section_id']);
                    $e1->execute();
                    $e2 = $conn->prepare(
                        "INSERT IGNORE INTO subject_grades (subject_id,student_id) VALUES (?,?)"
                    );
                    $e2->bind_param("is", $reqrow['subject_id'], $sr['student_id']);
                    $e2->execute();
                }
            }
            $upd = $conn->prepare(
                "UPDATE subject_section_requests
                 SET status=?, reviewed_by=?, responded_at=NOW()
                 WHERE id=?"
            );
            $upd->bind_param('sii', $decision, $admin_id, $req_id);
            $upd->execute();
            $conn->commit();
            // ── Push to tooltrack: if this was an approval AND the section's
            // course is FPST, send the full section+subject roster to tooltrack
            // so those students immediately appear as borrowable borrowers
            // (with their section/subject membership recorded). Non-FPST and
            // denials are no-ops. Failures never break the approval itself.
            if ($decision === 'approved') {
                push_section_subject_to_tooltrack($conn, (int)$reqrow['section_id'], (int)$reqrow['subject_id']);
                // Push to Guidance: every student in this section gets new enrollment
                $sq2 = $conn->prepare("SELECT student_id FROM section_students WHERE section_id = ?");
                $sq2->bind_param('i', $reqrow['section_id']);
                $sq2->execute();
                $srows2 = $sq2->get_result();
                while ($sr2 = $srows2->fetch_assoc()) {
                    push_student_to_guidance($conn, $sr2['student_id']);
                }
            }
            $success_msg = $decision === 'approved'
                ? "Request approved — the section's roster has been enrolled."
                : "Request denied.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error: " . $e->getMessage();
        }
    }
}

// ── Flash messages ───────────────────────────────────────────
if (isset($_GET['msg'])) {
    $msgs = [
        'created'=>'Section created.', 'updated'=>'Section updated.', 'deleted'=>'Section deleted.',
        'student_added'=>'Student added to section.', 'student_removed'=>'Student removed from section.',
    ];
    $success_msg = $msgs[$_GET['msg']] ?? $success_msg;
}

// ── Load all sections with counts ─────────────────────────────
$sections_res = $conn->query(
    "SELECT s.*, COUNT(ss.student_id) AS student_count
     FROM sections s
     LEFT JOIN section_students ss ON ss.section_id = s.id
     GROUP BY s.id
     ORDER BY s.section_name ASC"
);
$all_sections = [];
while ($r = $sections_res->fetch_assoc()) $all_sections[] = $r;

// ── Active section (for roster panel) ──────────────────────────
$active_sec_id  = (int)($_GET['sec'] ?? ($all_sections[0]['id'] ?? 0));
$active_section = null;
foreach ($all_sections as $s) { if ((int)$s['id'] === $active_sec_id) { $active_section = $s; break; } }

$roster = [];
if ($active_section) {
    $rr = $conn->prepare(
        "SELECT st.student_id, st.last_name, st.first_name, st.middle_initial
         FROM section_students ss
         JOIN students st ON st.student_id = ss.student_id
         WHERE ss.section_id = ?
         ORDER BY st.last_name ASC"
    );
    $rr->bind_param('i', $active_sec_id);
    $rr->execute();
    $roster_res = $rr->get_result();
    while ($row = $roster_res->fetch_assoc()) $roster[] = $row;
}

// Students not yet in the active section (for the add-student picker) —
// filtered to the section's course when it has one set. Students with
// no course on file are shown regardless, so they're never invisible
// just because their course hasn't been filled in yet.
$not_in_section = [];
if ($active_section) {
    $in_ids = array_map(fn($r) => $r['student_id'], $roster);
    $section_course = trim((string)($active_section['course'] ?? ''));
    $course_clause = $section_course !== ''
        ? "AND (course IS NULL OR course = '' OR UPPER(TRIM(course)) = UPPER(TRIM(?)))"
        : "";

    if ($in_ids) {
        $ph = implode(',', array_fill(0, count($in_ids), '?'));
        $ne = $conn->prepare(
            "SELECT student_id, last_name, first_name, course FROM students
             WHERE student_id NOT IN ($ph) $course_clause ORDER BY last_name ASC"
        );
        $types = str_repeat('s', count($in_ids)) . ($section_course !== '' ? 's' : '');
        $bind_args = $in_ids;
        if ($section_course !== '') $bind_args[] = $section_course;
        $ne->bind_param($types, ...$bind_args);
    } else {
        $ne = $conn->prepare(
            "SELECT student_id, last_name, first_name, course FROM students
             WHERE 1=1 $course_clause ORDER BY last_name ASC"
        );
        if ($section_course !== '') $ne->bind_param('s', $section_course);
    }
    $ne->execute();
    $not_in_section_res = $ne->get_result();
    while ($row = $not_in_section_res->fetch_assoc()) $not_in_section[] = $row;
}

// ── Pending subject-section enrollment requests ────────────────
$pending_requests = $conn->query(
    "SELECT r.*, sub.subject_code, sub.subject_name, sub.section AS subject_section_label,
            sec.section_name,
            (SELECT COUNT(*) FROM section_students WHERE section_id = r.section_id) AS section_size,
            u.username AS teacher_username, u.display_name AS teacher_display_name
     FROM subject_section_requests r
     JOIN subjects sub ON sub.id = r.subject_id
     JOIN sections sec ON sec.id = r.section_id
     JOIN users u      ON u.id  = r.requesting_teacher_id
     WHERE r.status = 'pending'
     ORDER BY r.created_at ASC"
);

$active_nav = 'sections';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Manage Sections — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-sections">
<div class="app-shell">


<?php $active_nav = 'sections'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-building-community" style="color:var(--accent)"></i> Manage Sections</h1>
    <p>Create sections and manage their rosters. Teachers request a section be enrolled into their subject — approve those requests below.</p>
  </div>

<hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i><div><?php echo $success_msg; ?></div></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i><div><?php echo $error_msg; ?></div></div>
  <?php endif; ?>

  <!-- ── PENDING ENROLLMENT REQUESTS ── -->
  <?php if ($pending_requests->num_rows > 0): ?>
  <div class="card" style="margin-bottom:20px;border-color:rgba(251,191,36,.35);">
    <p class="card-title"><i class="ti ti-hand-stop" style="color:var(--yellow);"></i>
      Pending Section Requests (<?php echo $pending_requests->num_rows; ?>)
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Teacher</th>
            <th>Subject</th>
            <th>Section</th>
            <th>Message</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($r = $pending_requests->fetch_assoc()): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['teacher_display_name'] ?: $r['teacher_username']); ?></td>
            <td>
              <?php echo htmlspecialchars($r['subject_code'].' — '.$r['subject_name']); ?>
              <div style="font-size:11px;color:var(--text7);"><?php echo htmlspecialchars($r['subject_section_label']); ?></div>
            </td>
            <td>
              <?php echo htmlspecialchars($r['section_name']); ?>
              <span class="badge badge-blue"><?php echo (int)$r['section_size']; ?> students</span>
            </td>
            <td style="font-size:12px;color:var(--text2);max-width:220px;"><?php echo htmlspecialchars($r['message'] ?: '—'); ?></td>
            <td>
              <div class="td-actions">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="decision" value="approve">
                  <button type="submit" name="respond_request" class="btn btn-sm btn-primary"><i class="ti ti-check"></i> Approve</button>
                </form>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="decision" value="deny">
                  <button type="submit" name="respond_request" class="btn btn-sm btn-outline"><i class="ti ti-x"></i> Deny</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="two-col">

    <!-- ── Section list + create ── -->
    <div>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <p class="card-title" style="margin:0;"><i class="ti ti-list"></i> All Sections (<?php echo count($all_sections); ?>)</p>
          <button type="button" class="btn btn-sm btn-primary" onclick="openCreateModal()"><i class="ti ti-plus"></i> New</button>
        </div>
        <?php if (empty($all_sections)): ?>
        <div class="empty-state"><i class="ti ti-building-community"></i><p>No sections yet. Create your first one.</p></div>
        <?php else: ?>
        <?php foreach ($all_sections as $s): ?>
        <a href="sections.php?sec=<?php echo (int)$s['id']; ?>"
           style="display:block;text-decoration:none;padding:10px 12px;border-radius:8px;margin-bottom:6px;
             background:<?php echo ((int)$s['id']===$active_sec_id)?'var(--bg5)':'transparent'; ?>;
             border:1px solid <?php echo ((int)$s['id']===$active_sec_id)?'var(--text7)':'var(--border2)'; ?>;">
          <div style="font-weight:500;color:var(--text6);"><?php echo htmlspecialchars($s['section_name']); ?></div>
          <div style="font-size:11px;color:var(--text7);">
            <?php echo (int)$s['student_count']; ?> student<?php echo $s['student_count']!=1?'s':''; ?>
            <?php echo $s['course'] ? ' · '.htmlspecialchars($s['course']) : ''; ?>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Active section roster ── -->
    <div class="card">
      <?php if (!$active_section): ?>
      <div class="empty-state"><i class="ti ti-building-community"></i><p>Select or create a section to manage its roster.</p></div>
      <?php else: ?>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:8px;">
        <p class="card-title" style="margin:0;"><i class="ti ti-users"></i> <?php echo htmlspecialchars($active_section['section_name']); ?></p>
        <div style="display:flex;gap:6px;">
          <button type="button" class="btn btn-sm btn-outline" onclick="openEditModal()"><i class="ti ti-edit"></i> Edit</button>
          <button type="button" class="btn btn-sm btn-danger"
            onclick="openDeleteModal(<?php echo (int)$active_section['id']; ?>, '<?php echo htmlspecialchars(addslashes($active_section['section_name'])); ?>')">
            <i class="ti ti-trash"></i>
          </button>
        </div>
      </div>
      <p style="font-size:12px;color:var(--text7);margin-bottom:14px;">
        <?php echo htmlspecialchars($active_section['course'] ?: '—'); ?> · Year <?php echo (int)$active_section['year_level']; ?>
        <?php echo $active_section['school_year'] ? ' · '.htmlspecialchars($active_section['school_year']) : ''; ?>
      </p>

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <span style="font-size:12px;color:var(--text7);"><?php echo count($roster); ?> students</span>
        <button type="button" class="btn btn-sm btn-primary" onclick="openAddStudentModal()"><i class="ti ti-user-plus"></i> Add Student</button>
      </div>

      <div class="table-wrap">
        <table>
          <thead><tr><th>Student</th><th>Student ID</th><th></th></tr></thead>
          <hr class="thin-line">
          <tbody>
            <?php if (empty($roster)): ?>
            <tr><td colspan="3"><div class="empty-state"><i class="ti ti-users-off"></i><p class="black-font">No students in this section yet.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($roster as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></td>
              <td class="td-mono"><?php echo htmlspecialchars($r['student_id']); ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('Remove this student from the section?');">
                  <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
                  <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($r['student_id']); ?>">
                  <button type="submit" name="remove_from_section" class="btn btn-sm btn-delete"><i class="ti ti-x"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- Create Modal -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <h3><i class="ti ti-plus" style="color:var(--accent);"></i> Create Section</h3>
    <form method="POST">
      <div class="form-group">
        <label>Section Name <span style="color:var(--red)">*</span></label>
        <input type="text" name="section_name" class="form-control" required placeholder="e.g. BSIT 3A">
      </div>
      <div class="form-group">
        <label>Course</label>
        <input type="text" name="course" class="form-control" placeholder="e.g. BSIT">
      </div>
      <div class="form-group">
        <label>Year Level</label>
        <input type="number" name="year_level" class="form-control" value="1" min="1" max="6">
      </div>
      <div class="form-group">
        <label>School Year</label>
        <input type="text" name="school_year" class="form-control" placeholder="e.g. 2026-2027">
      </div>
      <div class="form-group">
        <label>Description</label>
        <input type="text" name="section_desc" class="form-control">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="create_section" class="btn btn-primary" style="flex:1;justify-content:center;"><i class="ti ti-check"></i> Create</button>
        <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <h3><i class="ti ti-edit" style="color:var(--accent);"></i> Edit Section</h3>
    <form method="POST">
      <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
      <div class="form-group">
        <label>Section Name <span style="color:var(--red)">*</span></label>
        <input type="text" name="section_name" class="form-control" required
          value="<?php echo htmlspecialchars($active_section['section_name'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Course</label>
        <input type="text" name="course" class="form-control" value="<?php echo htmlspecialchars($active_section['course'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Year Level</label>
        <input type="number" name="year_level" class="form-control" min="1" max="6"
          value="<?php echo (int)($active_section['year_level'] ?? 1); ?>">
      </div>
      <div class="form-group">
        <label>School Year</label>
        <input type="text" name="school_year" class="form-control" value="<?php echo htmlspecialchars($active_section['school_year'] ?? ''); ?>">
      </div>
      <div class="form-group">
        <label>Description</label>
        <input type="text" name="section_desc" class="form-control" value="<?php echo htmlspecialchars($active_section['description'] ?? ''); ?>">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="update_section" class="btn btn-primary" style="flex:1;justify-content:center;"><i class="ti ti-check"></i> Save</button>
        <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3><i class="ti ti-alert-triangle" style="color:var(--red);"></i> Delete Section</h3>
    <p class="modal-sub" id="deleteModalMsg">Students will not be deleted — only the section itself and its roster link.</p>
    <form method="POST">
      <input type="hidden" name="sec_id" id="deleteSectionId">
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="delete_section" class="btn btn-danger" style="flex:1;justify-content:center;"><i class="ti ti-trash"></i> Yes, Delete</button>
        <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Student Modal -->
<div class="modal-overlay" id="addStudentModal">
  <div class="modal">
    <h3><i class="ti ti-user-plus" style="color:var(--accent);"></i> Add Student to Section</h3>
    <?php if (!empty($active_section['course'])): ?>
    <p style="font-size:12px;color:var(--text7);margin-top:-6px;">
      Showing students in <strong><?php echo htmlspecialchars($active_section['course']); ?></strong> (plus students with no course set).
    </p>
    <?php endif; ?>
    <?php if (empty($not_in_section)): ?>
      <p style="font-size:13px;color:var(--text7);">All students are already in this section.</p>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
      <div class="form-group">
        <input type="text" id="addStudentSearch" class="form-control" placeholder="Type to filter…" oninput="filterAddStudentList()" style="margin-bottom:6px;">
        <select name="student_id" id="addStudentSelect" class="form-control" size="8" style="height:auto;padding:4px;" required>
          <?php foreach ($not_in_section as $ns): ?>
          <option value="<?php echo htmlspecialchars($ns['student_id']); ?>"
            data-label="<?php echo strtolower($ns['last_name'].' '.$ns['first_name'].' '.$ns['student_id']); ?>">
            <?php echo htmlspecialchars($ns['last_name'].', '.$ns['first_name'].' ('.$ns['student_id'].')'); ?><?php echo $ns['course'] ? ' — '.htmlspecialchars($ns['course']) : ''; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="add_to_section" class="btn btn-primary" style="flex:1;justify-content:center;"><i class="ti ti-user-plus"></i> Add</button>
        <button type="button" class="btn btn-outline" onclick="closeAddStudentModal()">Cancel</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
function openCreateModal(){document.getElementById('createModal').classList.add('open');}
function closeCreateModal(){document.getElementById('createModal').classList.remove('open');}
function openEditModal(){document.getElementById('editModal').classList.add('open');}
function closeEditModal(){document.getElementById('editModal').classList.remove('open');}
function openAddStudentModal(){document.getElementById('addStudentModal').classList.add('open');}
function closeAddStudentModal(){document.getElementById('addStudentModal').classList.remove('open');}
function openDeleteModal(id,name){
  document.getElementById('deleteSectionId').value=id;
  document.getElementById('deleteModalMsg').innerHTML='Delete <strong>'+name+'</strong>? Students will not be deleted — only the section itself.';
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal(){document.getElementById('deleteModal').classList.remove('open');}
['createModal','editModal','deleteModal','addStudentModal'].forEach(id=>{
  const el=document.getElementById(id);
  if(el) el.addEventListener('click',function(e){ if(e.target===this) this.classList.remove('open'); });
});
function filterAddStudentList(){
  const q=document.getElementById('addStudentSearch').value.toLowerCase();
  document.querySelectorAll('#addStudentSelect option').forEach(opt=>{
    opt.style.display=opt.dataset.label.includes(q)?'':'none';
  });
}
<?php if (empty($all_sections)): ?>
window.addEventListener('DOMContentLoaded', () => openCreateModal());
<?php endif; ?>
</script>
</main>
</div>
</body>
</html>