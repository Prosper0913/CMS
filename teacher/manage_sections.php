<!--cat > /home/claude/manage_sections.php << 'PHPEOF'-->
<?php
// ============================================================
//  teacher/manage_sections.php
//  Full section management dashboard.
//  Features:
//    - Create / rename / delete sections
//    - View students per section
//    - Add / remove students from a section
//    - Quick-enroll entire section into any subject
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$teacher_id  = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// ── Access helper ─────────────────────────────────────────────
// A teacher can manage a section if they own it, or it's a legacy
// section with no recorded owner (teacher_id IS NULL) — same as
// how every section behaved before this feature existed.
// NOTE: an approved access request does NOT grant access to this
// same section — it clones a brand-new, independently-owned copy
// for the requester instead (see respond_section_request below).
function sectionAccessible($conn, $section_id, $teacher_id) {
    $q = $conn->prepare(
        "SELECT id FROM sections WHERE id = ? AND (teacher_id = ? OR teacher_id IS NULL) LIMIT 1"
    );
    $q->bind_param('ii', $section_id, $teacher_id);
    $q->execute();
    $q->store_result();
    return $q->num_rows > 0;
}

// ── Subjects with per-subject stats ─────────────────────────
$subjects_stmt = $conn->prepare(
    "SELECT s.*,
        (SELECT COUNT(*)
         FROM subject_enrollments
         WHERE subject_id = s.id)                                          AS enrollee_count,
        (SELECT ROUND(AVG(final_grade),1)
         FROM subject_grades
         WHERE subject_id = s.id AND final_grade > 0)                     AS class_avg,
        (SELECT COUNT(*)
         FROM subject_grades
         WHERE subject_id = s.id AND final_grade >= 75)                   AS passing,
        (SELECT COUNT(*)
         FROM subject_grades
         WHERE subject_id = s.id AND final_grade > 0 AND final_grade < 75) AS failing,
        (SELECT COUNT(DISTINCT date)
         FROM attendance
         WHERE subject_id = s.id)                                         AS class_days
     FROM subjects s
     WHERE s.teacher_id = ? AND s.is_active = 1
     ORDER BY s.semester DESC, s.subject_name ASC"
);

$subjects_stmt->bind_param("i", $teacher_id);
$subjects_stmt->execute();
$all_subs = $subjects_stmt->get_result();
// ══════════════════════════════════════════════════════════════
//  POST HANDLERS
// ══════════════════════════════════════════════════════════════

// ── CREATE section ───────────────────────────────────────────
if (isset($_POST['create_section'])) {
    $name = trim($_POST['section_name']);
    $desc = trim($_POST['section_desc'] ?? '');
    if ($name === '') {
        $error_msg = "Section name is required.";
    } else {
        $chk = $conn->prepare("SELECT id FROM sections WHERE section_name = ? AND teacher_id = ? LIMIT 1");
        $chk->bind_param('si', $name, $teacher_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "You already have a section named <strong>" . htmlspecialchars($name) . "</strong>.";
        } else {
            $ins = $conn->prepare("INSERT INTO sections (section_name, description, teacher_id) VALUES (?, ?, ?)");
            $ins->bind_param('ssi', $name, $desc, $teacher_id);
            $ins->execute();
            $new_sec_id = $conn->insert_id;
            header("Location: manage_sections.php?sec={$new_sec_id}&msg=created");
            exit;
        }
    }
}

// ── RENAME section ───────────────────────────────────────────
if (isset($_POST['rename_section'])) {
    $sec_id   = (int)$_POST['sec_id'];
    $new_name = trim($_POST['new_name']);
    $new_desc = trim($_POST['new_desc'] ?? '');
    if ($new_name === '') {
        $error_msg = "Section name cannot be empty.";
    } elseif (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "You can only edit sections you created.";
    } else {
        $upd = $conn->prepare("UPDATE sections SET section_name = ?, description = ? WHERE id = ?");
        $upd->bind_param('ssi', $new_name, $new_desc, $sec_id);
        $upd->execute();
        header("Location: manage_sections.php?sec={$sec_id}&msg=updated");
        exit;
    }
}

// ── DELETE section ───────────────────────────────────────────
if (isset($_POST['delete_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    if (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "You can only delete sections you created.";
    } else {
    // Remove from section_students, then delete section
    $conn->prepare("DELETE FROM section_students WHERE section_id = ?")->bind_param('i', $sec_id) && null;
    $d1 = $conn->prepare("DELETE FROM section_students WHERE section_id = ?");
    $d1->bind_param('i', $sec_id);
    $d1->execute();
    $d1b = $conn->prepare("DELETE FROM section_access_requests WHERE section_id = ?");
    $d1b->bind_param('i', $sec_id);
    $d1b->execute();
    $d2 = $conn->prepare("DELETE FROM sections WHERE id = ?");
    $d2->bind_param('i', $sec_id);
    $d2->execute();
    // Note: subject_enrollments rows that had this section_id are left intact
    // (students stay enrolled in subjects; only the section tag is orphaned)
    header("Location: manage_sections.php?msg=deleted");
    exit;
    }
}

$nav_subs = getTeacherSubjects($conn, $teacher_id);
$type_cfg = [
    'General Education'      => ['color'=>'#6c8dda','label'=>'GE'],
    'Professional Education' => ['color'=>'#ff2407','label'=>'PE'],
    'Major Subject'          => ['color'=>'#00ff1a','label'=>'MAJ'],
];

// ── ADD student to section ───────────────────────────────────
if (isset($_POST['add_to_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $sid    = trim($_POST['student_id']);
    if ($sid === '') {
        $error_msg = "Please select a student.";
    } elseif (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "Only the section's creator can add students to it.";
    } else {
        $chk = $conn->prepare(
            "SELECT id FROM section_students WHERE section_id = ? AND student_id = ? LIMIT 1"
        );
        $chk->bind_param('is', $sec_id, $sid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "Student is already in this section.";
        } else {
            $ins = $conn->prepare(
                "INSERT INTO section_students (section_id, student_id) VALUES (?, ?)"
            );
            $ins->bind_param('is', $sec_id, $sid);
            $ins->execute();
            header("Location: manage_sections.php?sec={$sec_id}&msg=student_added");
            exit;
        }
    }
}

// ── REMOVE student from section ──────────────────────────────
if (isset($_POST['remove_from_section'])) {
    $sec_id = (int)$_POST['sec_id'];
    $sid    = trim($_POST['student_id']);
    if (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "Only the section's creator can remove students from it.";
    } else {
    $del = $conn->prepare(
        "DELETE FROM section_students WHERE section_id = ? AND student_id = ?"
    );
    $del->bind_param('is', $sec_id, $sid);
    $del->execute();
    header("Location: manage_sections.php?sec={$sec_id}&msg=student_removed");
    exit;
    }
}

// ── BULK ENROLL section into subject ─────────────────────────
if (isset($_POST['enroll_into_subject'])) {
    $sec_id     = (int)$_POST['sec_id'];
    $subject_id = (int)$_POST['subject_id'];
    // Verify teacher owns this subject
    $own = $conn->prepare("SELECT id FROM subjects WHERE id = ? AND teacher_id = ? LIMIT 1");
    $own->bind_param('ii', $subject_id, $teacher_id);
    $own->execute();
    $own->store_result();
    if ($own->num_rows === 0) {
        $error_msg = "Subject not found or access denied.";
    } elseif (!sectionAccessible($conn, $sec_id, $teacher_id)) {
        $error_msg = "You don't have access to this section yet. Request access first.";
    } else {
        $sq = $conn->prepare("SELECT student_id FROM section_students WHERE section_id = ?");
        $sq->bind_param('i', $sec_id);
        $sq->execute();
        $sr    = $sq->get_result();
        $added = 0;
        while ($row = $sr->fetch_assoc()) {
            $sid = $row['student_id'];
            $e1  = $conn->prepare(
                "INSERT IGNORE INTO subject_enrollments (subject_id, student_id, section_id) VALUES (?, ?, ?)"
            );
            $e1->bind_param('isi', $subject_id, $sid, $sec_id);
            $e1->execute();
            $e2 = $conn->prepare(
                "INSERT IGNORE INTO subject_grades (subject_id, student_id) VALUES (?, ?)"
            );
            $e2->bind_param('is', $subject_id, $sid);
            $e2->execute();
            $added++;
        }
        $success_msg = "Enrolled <strong>{$added}</strong> student(s) into the selected subject.";
        header("Location: manage_sections.php?sec={$sec_id}&msg=enrolled&count={$added}");
        exit;
    }
}

// ── SEND a request to access a section you don't own ──────────
// The requester must know (and type) the teacher's username and the exact
// section name — sections are no longer browsable/listed across teachers.
if (isset($_POST['send_section_request'])) {
    $target_username = trim($_POST['target_username'] ?? '');
    $section_name_req = trim($_POST['section_name_req'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($target_username === '' || $section_name_req === '') {
        $error_msg = "Please enter both the teacher's username and the section name.";
    } else {
        $tq = $conn->prepare("SELECT id FROM users WHERE username = ? AND role = 'teacher' LIMIT 1");
        $tq->bind_param('s', $target_username);
        $tq->execute();
        $trow = $tq->get_result()->fetch_assoc();

        if (!$trow) {
            $error_msg = "No teacher found with that username.";
        } elseif ((int)$trow['id'] === $teacher_id) {
            $error_msg = "You can't request a section from yourself.";
        } else {
            $owner_teacher_id = (int)$trow['id'];
            $sq = $conn->prepare("SELECT id FROM sections WHERE section_name = ? AND teacher_id = ? LIMIT 1");
            $sq->bind_param('si', $section_name_req, $owner_teacher_id);
            $sq->execute();
            $srow = $sq->get_result()->fetch_assoc();

            if (!$srow) {
                $error_msg = "No section named <strong>" . htmlspecialchars($section_name_req) . "</strong> found for that teacher.";
            } else {
                $sec_id = (int)$srow['id'];
                if (sectionAccessible($conn, $sec_id, $teacher_id)) {
                    $error_msg = "You already have access to this section.";
                } else {
                    // Don't allow a duplicate pending request for the same section
                    $dupe = $conn->prepare(
                        "SELECT id FROM section_access_requests
                         WHERE section_id = ? AND requesting_teacher_id = ? AND status = 'pending'
                         LIMIT 1"
                    );
                    $dupe->bind_param('ii', $sec_id, $teacher_id);
                    $dupe->execute();
                    $dupe->store_result();
                    if ($dupe->num_rows > 0) {
                        $error_msg = "You already have a pending request for this section.";
                    } else {
                        $ins = $conn->prepare(
                            "INSERT INTO section_access_requests
                                (section_id, requesting_teacher_id, message, status)
                             VALUES (?, ?, ?, 'pending')"
                        );
                        $ins->bind_param('iis', $sec_id, $teacher_id, $message);
                        $ins->execute();
                        header("Location: manage_sections.php?msg=request_sent");
                        exit;
                    }
                }
            }
        }
    }
}

// ── APPROVE / DENY an incoming section access request ────────
// Only a teacher who currently owns (or holds legacy access to) the
// requested section can respond. Approving does NOT share the same
// section — it clones a brand-new, independently-owned copy of the
// roster for the requester, so edits on one side never affect the other.
if (isset($_POST['respond_section_request'])) {
    $req_id = (int)$_POST['request_id'];
    $action = $_POST['action'] ?? '';
    $new_status = $action === 'approve' ? 'approved' : ($action === 'deny' ? 'denied' : null);

    if (!$new_status) {
        $error_msg = "Invalid request action.";
    } else {
        $rq = $conn->prepare(
            "SELECT section_id, requesting_teacher_id FROM section_access_requests
             WHERE id = ? AND status = 'pending'"
        );
        $rq->bind_param('i', $req_id);
        $rq->execute();
        $req_row = $rq->get_result()->fetch_assoc();

        if (!$req_row) {
            $error_msg = "Request not found or already handled.";
        } elseif (!sectionAccessible($conn, $req_row['section_id'], $teacher_id)) {
            $error_msg = "You don't own this section, so you can't respond to requests for it.";
        } elseif ($new_status === 'approved') {
            // Clone section metadata
            $orig = $conn->prepare(
                "SELECT s.section_name, s.description, u.username AS owner_name
                 FROM sections s LEFT JOIN users u ON u.id = s.teacher_id
                 WHERE s.id = ?"
            );
            $orig->bind_param('i', $req_row['section_id']);
            $orig->execute();
            $orig_row = $orig->get_result()->fetch_assoc();

            // Avoid colliding with a section the requester already owns
            // under that exact name (per-teacher name uniqueness)
            $clone_name = $orig_row['section_name'];
            $nameChk = $conn->prepare("SELECT id FROM sections WHERE section_name = ? AND teacher_id = ? LIMIT 1");
            $nameChk->bind_param('si', $clone_name, $req_row['requesting_teacher_id']);
            $nameChk->execute();
            $nameChk->store_result();
            if ($nameChk->num_rows > 0) {
                $clone_name = $orig_row['section_name'] . ' (from ' . ($orig_row['owner_name'] ?? 'shared') . ')';
            }

            $ins = $conn->prepare(
                "INSERT INTO sections (section_name, description, teacher_id, cloned_from_section_id)
                 VALUES (?, ?, ?, ?)"
            );
            $ins->bind_param('ssii', $clone_name, $orig_row['description'],
                              $req_row['requesting_teacher_id'], $req_row['section_id']);
            $ins->execute();
            $new_section_id = $conn->insert_id;

            // Copy the current roster as of right now (a snapshot, not a live link)
            $copy = $conn->prepare(
                "INSERT INTO section_students (section_id, student_id)
                 SELECT ?, student_id FROM section_students WHERE section_id = ?"
            );
            $copy->bind_param('ii', $new_section_id, $req_row['section_id']);
            $copy->execute();

            $upd = $conn->prepare(
                "UPDATE section_access_requests
                 SET status = 'approved', approved_by_teacher_id = ?, resulting_section_id = ?, responded_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $upd->bind_param('iii', $teacher_id, $new_section_id, $req_id);
            $upd->execute();
            header("Location: manage_sections.php?msg=request_approved");
            exit;
        } else {
            $upd = $conn->prepare(
                "UPDATE section_access_requests
                 SET status = 'denied', approved_by_teacher_id = ?, responded_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $upd->bind_param('ii', $teacher_id, $req_id);
            $upd->execute();
            header("Location: manage_sections.php?msg=request_denied");
            exit;
        }
    }
}

// ── CANCEL a pending request you sent ─────────────────────────
if (isset($_POST['cancel_section_request'])) {
    $req_id = (int)$_POST['request_id'];
    $del = $conn->prepare(
        "DELETE FROM section_access_requests
         WHERE id = ? AND requesting_teacher_id = ? AND status = 'pending'"
    );
    $del->bind_param('ii', $req_id, $teacher_id);
    $del->execute();
    header("Location: manage_sections.php?msg=request_cancelled");
    exit;
}

// ══════════════════════════════════════════════════════════════
//  FLASH MESSAGES (from PRG redirects)
// ══════════════════════════════════════════════════════════════
switch ($_GET['msg'] ?? '') {
    case 'created':         $success_msg = "Section created successfully."; break;
    case 'updated':         $success_msg = "Section updated."; break;
    case 'deleted':         $success_msg = "Section deleted. Students remain enrolled in any subjects they were in."; break;
    case 'student_added':   $success_msg = "Student added to section."; break;
    case 'student_removed': $success_msg = "Student removed from section."; break;
    case 'enrolled':
        $cnt = (int)($_GET['count'] ?? 0);
        $success_msg = "Enrolled <strong>{$cnt}</strong> student(s) into the selected subject."; break;
    case 'request_sent':      $success_msg = "Access request sent."; break;
    case 'request_approved':  $success_msg = "Request approved."; break;
    case 'request_denied':    $success_msg = "Request denied."; break;
    case 'request_cancelled': $success_msg = "Request withdrawn."; break;
}

// ══════════════════════════════════════════════════════════════
//  DATA
// ══════════════════════════════════════════════════════════════

// Active section panel (from GET)
$active_sec_id = (int)($_GET['sec'] ?? 0);

// All sections with student counts, owner info, and clone lineage
$sections_res = $conn->query(
    "SELECT s.id, s.section_name, s.description, s.teacher_id, s.cloned_from_section_id,
            u.username  AS owner_name,
            ou.username AS original_owner_name,
            COUNT(ss.student_id) AS student_count
     FROM sections s
     LEFT JOIN section_students ss ON ss.section_id = s.id
     LEFT JOIN users u  ON u.id  = s.teacher_id
     LEFT JOIN sections os ON os.id = s.cloned_from_section_id
     LEFT JOIN users ou ON ou.id = os.teacher_id
     GROUP BY s.id, s.section_name, s.description, s.teacher_id, s.cloned_from_section_id,
              u.username, ou.username
     ORDER BY s.section_name ASC"
);
$all_sections = [];
while ($r = $sections_res->fetch_assoc()) $all_sections[] = $r;

// Sections I own (or legacy/unowned ones, manageable by anyone as before).
// Note: approval no longer grants access to the original section — it
// clones a brand-new row owned by the requester, which will show up here
// naturally once that clone exists.
$my_sections = [];
foreach ($all_sections as $sec) {
    $is_mine = $sec['teacher_id'] === null || (int)$sec['teacher_id'] === $teacher_id;
    if ($is_mine) {
        $my_sections[] = $sec;
    }
}

// Active section data (only if I actually own/have access to it)
$active_section = null;
$access_denied  = false;
$section_students_list = [];
$not_in_section = [];
if ($active_sec_id) {
    foreach ($all_sections as $s) {
        if ((int)$s['id'] === $active_sec_id) {
            $is_mine = $s['teacher_id'] === null || (int)$s['teacher_id'] === $teacher_id;
            if ($is_mine) {
                $active_section = $s;
            } else {
                $access_denied = $s; // keep the row so we can show its name + a Request button
            }
            break;
        }
    }
    if ($active_section) {
        // Students already in section
        $ss_res = $conn->prepare(
            "SELECT s.student_id, s.last_name, s.first_name, s.middle_initial
             FROM section_students ss
             JOIN students s ON ss.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
             WHERE ss.section_id = ?
             ORDER BY s.last_name ASC, s.first_name ASC"
        );
        $ss_res->bind_param('i', $active_sec_id);
        $ss_res->execute();
        $ssr = $ss_res->get_result();
        $in_ids = [];
        while ($r = $ssr->fetch_assoc()) {
            $section_students_list[] = $r;
            $in_ids[] = $r['student_id'];
        }

        // Students NOT in section (for add dropdown)
        if ($in_ids) {
            $ph = implode(',', array_fill(0, count($in_ids), '?'));
            $ne = $conn->prepare(
                "SELECT student_id, last_name, first_name FROM students
                 WHERE student_id NOT IN ($ph) ORDER BY last_name ASC"
            );
            $types = str_repeat('s', count($in_ids));
            $ne->bind_param($types, ...$in_ids);
        } else {
            $ne = $conn->prepare(
                "SELECT student_id, last_name, first_name FROM students ORDER BY last_name ASC"
            );
        }
        $ne->execute();
        $ne_res = $ne->get_result();
        while ($r = $ne_res->fetch_assoc()) $not_in_section[] = $r;
    }
}

// Teacher's subjects for bulk-enroll dropdown
$subj_res = $conn->prepare(
    "SELECT id, subject_code, subject_name, section
     FROM subjects WHERE teacher_id = ? AND is_active = 1
     ORDER BY subject_name ASC"
);
$subj_res->bind_param('i', $teacher_id);
$subj_res->execute();
$teacher_subjects_list = $subj_res->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary counts
$total_sections = count($all_sections);
$total_in_sections = $conn->query(
    "SELECT COUNT(DISTINCT student_id) AS c FROM section_students"
)->fetch_assoc()['c'];
$total_students_global = $conn->query(
    "SELECT COUNT(*) AS c FROM students"
)->fetch_assoc()['c'];

// ══════════════════════════════════════════════════════════════
//  SECTION ACCESS REQUESTS
// ══════════════════════════════════════════════════════════════

// IDs of sections I currently have access to (owner, legacy/no-owner,
// or an approved request) — used to find requests I'm allowed to act on
$my_section_ids = array_map(fn($s) => (int)$s['id'], $my_sections);

$incoming_requests = [];
$pending_incoming_count = 0;
if (!empty($my_section_ids)) {
    $ph = implode(',', array_fill(0, count($my_section_ids), '?'));
    $types = str_repeat('i', count($my_section_ids));
    $incoming_stmt = $conn->prepare(
        "SELECT r.id, r.section_id, r.message, r.created_at,
                s.section_name, u.username AS requester_name
         FROM section_access_requests r
         JOIN sections s ON s.id = r.section_id
         JOIN users u    ON u.id = r.requesting_teacher_id
         WHERE r.status = 'pending'
           AND r.requesting_teacher_id != ?
           AND r.section_id IN ($ph)
         ORDER BY r.created_at DESC"
    );
    $incoming_stmt->bind_param('i' . $types, $teacher_id, ...$my_section_ids);
    $incoming_stmt->execute();
    $incoming_requests = $incoming_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pending_incoming_count = count($incoming_requests);
}

// Requests I've sent (any status), most recent first
$outgoing_stmt = $conn->prepare(
    "SELECT r.id, r.section_id, r.status, r.message, r.created_at, r.responded_at,
            r.resulting_section_id,
            s.section_name, ub.username AS approved_by_name
     FROM section_access_requests r
     JOIN sections s ON s.id = r.section_id
     LEFT JOIN users ub ON ub.id = r.approved_by_teacher_id
     WHERE r.requesting_teacher_id = ?
     ORDER BY r.created_at DESC
     LIMIT 20"
);
$outgoing_stmt->bind_param('i', $teacher_id);
$outgoing_stmt->execute();
$outgoing_requests = $outgoing_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Manage Sections — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">

</head>
<body class="page-teacher-manage_sections">

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <a class="brand" href="/classroom/teacher/dashboard.php">
    <img src="/classroom/assets/images/TCM logo (2).png" alt="TCM Logo" width="32" height="32">Classroom Management System
  </a>
  <div class="nav-sep"></div>
  <a href="/classroom/teacher/dashboard.php" class="nav-link">
    <i class="ti ti-layout-dashboard"></i> Dashboard
  </a>
<!-- Subject dropdown (only if subjects exist) -->
  <?php if ($all_subs->num_rows > 0): ?>
  <div class="nav-dropdown">
    <button class="nav-dd-btn" id="ddBtn" onclick="toggleDD()">
      <i class="ti ti-books"></i> My Subjects
      <i class="ti ti-chevron-down"></i>
    </button>
    <div class="nav-dd-menu" id="ddMenu">
      <?php
      $all_subs->data_seek(0);
      while ($ns = $all_subs->fetch_assoc()):
        $dc = $type_cfg[$ns['subject_type']]['color'] ?? '#7aa3ff';
      ?>
      <a href="/classroom/teacher/subject_view.php?id=<?php echo $ns['id']; ?>" class="dd-item">
        <span class="dd-dot" style="background:<?php echo $dc; ?>;"></span>
        <span class="dd-main"><?php echo htmlspecialchars($ns['subject_code'].' — '.$ns['subject_name']); ?></span>
        <span class="dd-sub"><?php echo htmlspecialchars($ns['section']); ?></span>
      </a>
      <?php endwhile; ?>
      <div class="dd-divider"></div>
      <a href="/classroom/teacher/add_subject.php" class="dd-item">
        <i class="ti ti-plus" style="color:var(--accent);font-size:13px;"></i>
        <span class="dd-main" style="color:var(--accent);">Add New Subject</span>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <a href="/classroom/teacher/add_subject.php" class="nav-link">
    <i class="ti ti-book-plus"></i> Add Subject
  </a>
    <a href="/classroom/teacher/manage_sections.php" class="nav-link active">
    <i class="ti ti-building-community"></i> Sections
    <?php if ($pending_incoming_count > 0): ?>
      <span class="nav-badge"><?php echo $pending_incoming_count; ?></span>
    <?php endif; ?>
  </a>
  <a href="/classroom/teacher/students.php" class="nav-link">
    <i class="ti ti-users"></i> Students
  </a>
  <div class="nav-right">
    <span class="nav-role">Teacher</span>
    <span style="font-size:13px;color:var(--text2);"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="/classroom/logout.php" class="btn-logout"><i class="ti ti-logout"></i> Logout</a>
  </div>
</nav>

<div class="page-wrap">

  <!-- ── PAGE HEADER ── -->
  <div class="page-header">
    <div class="page-header-left">
      <h1><i class="ti ti-building-community" style="color:var(--text4);"></i> Manage Sections</h1>
      <p>Create sections, assign students, and bulk-enroll them into subjects in one place.</p>

    </div>
  </div>

  <hr class="thin-line" style="margin-bottom: 20px;">

    <button class="btn btn-primary" style="margin-bottom: 20px;" onclick="openCreateModal()">
      <i class="ti ti-plus"></i> New Section
    </button>

  <!-- ── ALERTS ── -->
  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- ── STAT CHIPS ── -->
  <div class="stat-chips">
    <div class="stat-chip a">
      <div class="stat-chip-val"><?php echo $total_sections; ?></div>
      <div class="stat-chip-lbl">Total Sections</div>
    </div>
    <div class="stat-chip g">
      <div class="stat-chip-val"><?php echo $total_in_sections; ?></div>
      <div class="stat-chip-lbl">Students in Sections</div>
    </div>
    <div class="stat-chip y">
      <div class="stat-chip-val"><?php echo $total_students_global; ?></div>
      <div class="stat-chip-lbl">Total Students</div>
    </div>
  </div>

  <!-- ── SECTION ACCESS REQUESTS ── -->
  <?php if (!empty($incoming_requests) || !empty($outgoing_requests)): ?>
  <div class="requests-grid">

    <!-- Incoming: requests other teachers sent me -->
    <div class="card">
      <p class="card-title">
        <i class="ti ti-inbox" style="color:var(--accent);"></i>
        Incoming Requests
        <?php if ($pending_incoming_count > 0): ?>
          <span class="card-title-right" style="font-family:var(--font-mono);font-size:11px;color:var(--yellow);">
            <?php echo $pending_incoming_count; ?> pending
          </span>
        <?php endif; ?>
      </p>
      <?php if (empty($incoming_requests)): ?>
        <p style="font-size:13px;color:var(--text3);">No pending requests from other teachers.</p>
      <?php else: ?>
        <?php foreach ($incoming_requests as $req): ?>
        <div class="req-item">
          <div class="req-main">
            <div class="req-title">
              <strong><?php echo htmlspecialchars($req['requester_name']); ?></strong>
              wants <?php echo htmlspecialchars($req['section_name']); ?>
            </div>
            <div class="req-sub"><?php echo date('M d, Y g:ia', strtotime($req['created_at'])); ?></div>
            <?php if ($req['message'] !== ''): ?>
            <div class="req-msg">“<?php echo htmlspecialchars($req['message']); ?>”</div>
            <?php endif; ?>
          </div>
          <div class="req-actions">
            <form method="POST" style="display:inline;">
              <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" name="respond_section_request" class="btn btn-green btn-sm" title="Approve">
                <i class="ti ti-check"></i>
              </button>
            </form>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
              <input type="hidden" name="action" value="deny">
              <button type="submit" name="respond_section_request" class="btn btn-danger btn-sm" title="Deny"
                onclick="return confirm('Deny this request?')">
                <i class="ti ti-x"></i>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Outgoing: requests I've sent -->
    <div class="card">
      <p class="card-title">
        <i class="ti ti-send" style="color:var(--accent);"></i>
        My Requests
      </p>
      <?php if (empty($outgoing_requests)): ?>
        <p style="font-size:13px;color:var(--text3);">You haven't requested access to any section yet.</p>
      <?php else: ?>
        <?php foreach ($outgoing_requests as $req): ?>
        <div class="req-item">
          <div class="req-main">
            <div class="req-title">
              <?php echo htmlspecialchars($req['section_name']); ?>
              <?php if ($req['status'] === 'approved' && $req['approved_by_name']): ?>
                <span style="color:var(--text3);font-weight:400;">— approved by <?php echo htmlspecialchars($req['approved_by_name']); ?></span>
              <?php elseif ($req['status'] === 'pending'): ?>
                <span style="color:var(--text3);font-weight:400;">— awaiting approval</span>
              <?php endif; ?>
            </div>
            <div class="req-sub"><?php echo date('M d, Y g:ia', strtotime($req['created_at'])); ?></div>
          </div>
          <span class="req-status <?php echo $req['status']; ?>"><?php echo $req['status']; ?></span>
          <?php if ($req['status'] === 'pending'): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
            <button type="submit" name="cancel_section_request" class="btn btn-outline btn-sm" title="Withdraw"
              onclick="return confirm('Withdraw this request?')">
              <i class="ti ti-trash"></i>
            </button>
          </form>
          <?php elseif ($req['status'] === 'approved' && $req['resulting_section_id']): ?>
          <a href="manage_sections.php?sec=<?php echo $req['resulting_section_id']; ?>" class="btn btn-outline btn-sm" title="View your copy">
            <i class="ti ti-external-link"></i> View
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
  <?php endif; ?>

  <!-- ── MAIN LAYOUT ── -->
  <div class="sections-layout">

    <!-- ── LEFT: SECTION SIDEBAR ── -->
    <div class="section-sidebar">
      <div class="section-list-header">
        <span style="color: var(--text);">My Sections</span>
        <span style="font-family:var(--font-mono);font-size:11px;"><?php echo count($my_sections); ?></span>
      </div>

      <?php if (empty($my_sections)): ?>
        <div style="text-align:center;padding:24px 12px;color:var(--text3);font-size:12px;">
          <i class="ti ti-building-off" style="font-size:26px;display:block;margin-bottom:8px;opacity:.4;"></i>
          No sections yet.<br>Create one below, or request one from another teacher.
        </div>
      <?php else: ?>
        <?php foreach ($my_sections as $sec): ?>
        <a href="manage_sections.php?sec=<?php echo $sec['id']; ?>"
           class="section-list-item <?php echo (int)$sec['id'] === $active_sec_id ? 'active' : ''; ?>">
          <div class="sli-icon"><i class="ti ti-users"></i></div>
          <span class="sli-name"><?php echo htmlspecialchars($sec['section_name']); ?></span>
          <?php if ($sec['cloned_from_section_id'] !== null): ?>
            <i class="ti ti-copy" style="color:var(--accent);font-size:13px;"
               title="Your own copy, originally from <?php echo htmlspecialchars($sec['original_owner_name'] ?? 'another teacher'); ?>"></i>
          <?php endif; ?>
          <span class="sli-count"><?php echo $sec['student_count']; ?></span>
        </a>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Request a section from another teacher (manual entry — sections
           aren't listed/browsable across teachers) -->
      <button type="button" class="btn btn-outline btn-full" style="margin-top:14px;font-size:12px;padding:8px 0;justify-content:center;"
        onclick="openRequestModal()">
        <i class="ti ti-hand-stop"></i> Request a Section
      </button>

      <!-- Quick create form in sidebar -->
      <div class="create-section-form" style="margin-top:14px;">
        <p class="cf-title"><i class="ti ti-plus" style="color:var(--yellow);"></i> Quick Create</p>
        <form method="POST">
          <div class="form-group">
            <input type="text" name="section_name" class="form-control"
              placeholder="Section name e.g. BEED-1A" required
              style="font-size:12px;padding:7px 10px;">
          </div>
          <div class="form-group">
            <input type="text" name="section_desc" class="form-control"
              placeholder="Description (optional)"
              style="font-size:12px;padding:7px 10px;">
          </div>
          <button type="submit" name="create_section" class="btn btn-primary btn-full" style="font-size:12px;padding:7px 0;">
            <i class="ti ti-plus"></i> Create Section
          </button>
        </form>
      </div>
    </div>

    <!-- ── RIGHT: SECTION DETAIL ── -->
    <div class="main-panel">

      <?php if ($access_denied): ?>
      <!-- Section exists, but I don't have access to it — don't reveal
           its name or owner; direct link/ID guessing shouldn't work as
           a discovery method. -->
      <div class="card">
        <div class="no-selection">
          <i class="ti ti-lock"></i>
          <h3>No access</h3>
          <p>You don't have access to this section.<br>
             If you know which teacher created it, you can request your own copy of its roster.</p>
          <button type="button" class="btn btn-primary" style="margin-top:12px;" onclick="openRequestModal()">
            <i class="ti ti-hand-stop"></i> Request a Section
          </button>
        </div>
      </div>

      <?php elseif (!$active_section): ?>
      <!-- No section selected -->
      <div class="card">
        <div class="no-selection">
          <i class="ti ti-building-community"></i>
          <h3>Select a section</h3>
          <p>Choose a section from the left panel to view and manage its students,<br>or create a new section to get started.</p>
        </div>
      </div>

      <?php else: ?>

      <!-- ── SECTION HERO ── -->
      <div class="section-hero">
        <div style="flex:1;">
          <div class="sh-name"><?php echo htmlspecialchars($active_section['section_name']); ?></div>
          <?php if ($active_section['description']): ?>
            <div class="sh-desc"><?php echo htmlspecialchars($active_section['description']); ?></div>
          <?php endif; ?>
          <div class="sh-meta">
            <span class="sh-meta-item">
              <i class="ti ti-users"></i>
              <?php echo count($section_students_list); ?> student<?php echo count($section_students_list) !== 1 ? 's' : ''; ?>
            </span>
            <span class="sh-meta-item">
              <i class="ti ti-id"></i>
              Section ID: <?php echo $active_section['id']; ?>
            </span>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;align-items:center;">
          <?php if ($active_section['cloned_from_section_id'] !== null): ?>
            <span class="req-status approved" title="Your own independent copy, originally from <?php echo htmlspecialchars($active_section['original_owner_name'] ?? 'another teacher'); ?>">
              <i class="ti ti-copy"></i> Your Copy
            </span>
          <?php endif; ?>
          <button class="btn btn-outline btn-sm" onclick="openEditModal()">
            <i class="ti ti-edit"></i> Edit
          </button>
          <button class="btn btn-danger btn-sm"
            onclick="openDeleteModal('<?php echo $active_sec_id; ?>','<?php echo htmlspecialchars(addslashes($active_section['section_name'])); ?>')">
            <i class="ti ti-trash"></i> Delete
          </button>
        </div>
      </div>

      <!-- ── BULK ENROLL INTO SUBJECT ── -->
      <?php if ($teacher_subjects_list): ?>
      <div class="card">
        <p class="card-title">
          <i class="ti ti-rocket"></i> Bulk Enroll into Subject
          <span style="font-size:11px;font-weight:400;color:var(--text3);margin-left:4px;">
            Enroll all <?php echo count($section_students_list); ?> students at once
          </span>
        </p>
        <?php if (empty($section_students_list)): ?>
          <p style="font-size:13px;color:var(--text3);">Add students to this section first before enrolling them into a subject.</p>
        <?php else: ?>
        <form method="POST">
          <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
          <div class="enroll-strip">
            <i class="ti ti-books" style="color:var(--bg5);font-size:16px;flex-shrink:0;"></i>
            <label>Enroll all students into:</label>
            <select name="subject_id" class="form-control" required>
              <option value="">— Choose a subject —</option>
              <?php foreach ($teacher_subjects_list as $subj): ?>
              <option value="<?php echo $subj['id']; ?>">
                <?php echo htmlspecialchars($subj['subject_code'] . ' — ' . $subj['subject_name'] . ' (' . $subj['section'] . ')'); ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" name="enroll_into_subject" class="btn btn-green"
              onclick="return confirm('Enroll all <?php echo count($section_students_list); ?> students from this section into the selected subject?')">
              <i class="ti ti-users-plus"></i> Enroll Section
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- ── STUDENT ROSTER ── -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
          <p class="card-title" style="margin:0;">
            <i class="ti ti-list-details"></i> Student Roster
            <span style="font-size:11px;font-weight:400;color:var(--text3);margin-left:4px;">
              <?php echo count($section_students_list); ?> student<?php echo count($section_students_list) !== 1 ? 's' : ''; ?>
            </span>
          </p>
          <?php if ($not_in_section): ?>
          <button class="btn btn-primary btn-sm" onclick="openAddStudentModal()">
            <i class="ti ti-user-plus"></i> Add Student
          </button>
          <?php endif; ?>
        </div>

        <!-- Search roster -->
        <?php if (count($section_students_list) > 4): ?>
        <div class="search-wrap">
          <i class="ti ti-search"></i>
          <input type="text" id="rosterSearch" class="form-control" placeholder="Search students…" oninput="filterRoster()">
        </div>
        <?php endif; ?>

        <?php if (empty($section_students_list)): ?>
          <div class="empty-state">
            <i class="ti ti-user-off"></i>
            <p>No students in this section yet.<br>Use the Add Student button to add some.</p>
          </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th>
                <th>Student ID</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="rosterTable">
              <?php foreach ($section_students_list as $st):
                $initials = strtoupper(substr($st['last_name'],0,1) . substr($st['first_name'],0,1));
              ?>
              <tr data-name="<?php echo strtolower($st['last_name'] . ' ' . $st['first_name'] . ' ' . $st['student_id']); ?>">
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div class="avatar"><?php echo $initials; ?></div>
                    <div>
                      <div style="font-weight:500;"><?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name']); ?></div>
                      <?php if ($st['middle_initial']): ?>
                        <div style="font-size:11px;color:var(--text3);"><?php echo htmlspecialchars($st['middle_initial']); ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="td-mono"><?php echo htmlspecialchars($st['student_id']); ?></td>
                <td style="text-align:right;">
                  <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($st['first_name'])); ?> from this section?')">
                    <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($st['student_id']); ?>">
                    <button type="submit" name="remove_from_section" class="btn btn-xs btn-danger">
                      <i class="ti ti-user-minus"></i> Remove
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; /* end $active_section */ ?>

    </div><!-- end main-panel -->
  </div><!-- end sections-layout -->
</div><!-- end page-wrap -->

<!-- ══════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════ -->

<!-- Create Section Modal (full, with description) -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <h3><i class="ti ti-plus" style="color:var(--accent);"></i> Create New Section</h3>
    <p class="modal-sub">Sections group students for easy bulk-enrollment into subjects.</p>
    <form method="POST">
      <div class="form-group">
        <label>Section Name <span style="color:var(--red)">*</span></label>
        <input type="text" name="section_name" class="form-control"
          placeholder="e.g. BEED-1A, BSED-2B" required autofocus>
      </div>
      <div class="form-group">
        <label>Description</label>
        <input type="text" name="section_desc" class="form-control"
          placeholder="e.g. Bachelor of Elementary Education Year 1">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="create_section" class="btn btn-primary" style="flex:1;justify-content:center;">
          <i class="ti ti-check"></i> Create Section
        </button>
        <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Section Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <h3><i class="ti ti-edit" style="color:var(--accent);"></i> Edit Section</h3>
    <p class="modal-sub">Update the section name or description.</p>
    <form method="POST">
      <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
      <div class="form-group">
        <label>Section Name <span style="color:var(--red)">*</span></label>
        <input type="text" name="new_name" class="form-control" id="editNameInput"
          value="<?php echo htmlspecialchars($active_section['section_name'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <input type="text" name="new_desc" class="form-control" id="editDescInput"
          value="<?php echo htmlspecialchars($active_section['description'] ?? ''); ?>">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="rename_section" class="btn btn-primary" style="flex:1;justify-content:center;">
          <i class="ti ti-check"></i> Save Changes
        </button>
        <button type="button" class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <h3><i class="ti ti-alert-triangle" style="color:var(--red);"></i> Delete Section</h3>
    <p class="modal-sub" id="deleteModalMsg">Are you sure? Students in this section will not be deleted — they will remain enrolled in any subjects they were added to.</p>
    <form method="POST">
      <input type="hidden" name="sec_id" id="deleteSectionId">
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="delete_section" class="btn btn-danger" style="flex:1;justify-content:center;">
          <i class="ti ti-trash"></i> Yes, Delete Section
        </button>
        <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Request Section Access Modal -->
<div class="modal-overlay" id="requestModal">
  <div class="modal">
    <h3><i class="ti ti-hand-stop" style="color:var(--accent);"></i> Request a Section</h3>
    <p class="modal-sub">
      Enter the teacher's username and the exact section name. If approved, you'll get
      your own independent copy of its current roster — separate from the original, so changes
      on either side won't affect the other.
    </p>
    <form method="POST">
      <div class="form-group">
        <label>Teacher's username</label>
        <input type="text" name="target_username" class="form-control" required
          placeholder="e.g. jadances">
      </div>
      <div class="form-group">
        <label>Section name</label>
        <input type="text" name="section_name_req" class="form-control" required
          placeholder="e.g. BSIT1C">
      </div>
      <div class="form-group">
        <label>Message (optional)</label>
        <input type="text" name="message" class="form-control" maxlength="255"
          placeholder="e.g. I'd like to use this section for my Fil 2 class">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="send_section_request" class="btn btn-primary" style="flex:1;justify-content:center;">
          <i class="ti ti-send"></i> Send Request
        </button>
        <button type="button" class="btn btn-outline" onclick="closeRequestModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="addStudentModal">
  <div class="modal">
    <h3><i class="ti ti-user-plus" style="color:var(--bg5);"></i> Add Student to Section</h3>
    <p class="modal-sub">Select a student to add to <strong><?php echo htmlspecialchars($active_section['section_name'] ?? ''); ?></strong>.</p>
    <?php if (empty($not_in_section)): ?>
      <p style="font-size:13px;color:var(--text2);margin-bottom:14px;">All registered students are already in this section.</p>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="sec_id" value="<?php echo $active_sec_id; ?>">
      <div class="form-group">
        <label>Student</label>
        <!-- Live search filter -->
        <input type="text" id="addStudentSearch" class="form-control"
          placeholder="Type to filter students…"
          oninput="filterAddStudentList()"
          style="margin-bottom:6px;">
        <select name="student_id" id="addStudentSelect" class="form-control" size="6"
          style="height:auto;padding:4px;" required>
          <?php foreach ($not_in_section as $ns): ?>
          <option value="<?php echo htmlspecialchars($ns['student_id']); ?>"
            data-label="<?php echo strtolower($ns['last_name'] . ' ' . $ns['first_name'] . ' ' . $ns['student_id']); ?>">
            <?php echo htmlspecialchars($ns['last_name'] . ', ' . $ns['first_name'] . ' (' . $ns['student_id'] . ')'); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" name="add_to_section" class="btn btn-primary" style="flex:1;justify-content:center;">
          <i class="ti ti-user-plus"></i> Add to Section
        </button>
        <button type="button" class="btn btn-outline" onclick="closeAddStudentModal()">Cancel</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
// ── Modal helpers ────────────────────────────────────
function openCreateModal()  { document.getElementById('createModal').classList.add('open'); }
function closeCreateModal() { document.getElementById('createModal').classList.remove('open'); }
function openEditModal()    { document.getElementById('editModal').classList.add('open'); }
function closeEditModal()   { document.getElementById('editModal').classList.remove('open'); }
function openAddStudentModal()    { document.getElementById('addStudentModal').classList.add('open'); document.getElementById('addStudentSearch')?.focus(); }
function closeAddStudentModal()   { document.getElementById('addStudentModal').classList.remove('open'); }
function openDeleteModal(id, name) {
  document.getElementById('deleteSectionId').value = id;
  document.getElementById('deleteModalMsg').innerHTML =
    'Delete section <strong>' + name + '</strong>? Students will not be deleted — they remain enrolled in any subjects they were added to.';
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
function openRequestModal() {
  document.getElementById('requestModal').classList.add('open');
}
function closeRequestModal() { document.getElementById('requestModal').classList.remove('open'); }

// Close modals on backdrop click
['createModal','editModal','deleteModal','addStudentModal','requestModal'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

// ── Roster live search ───────────────────────────────
function filterRoster() {
  const q = document.getElementById('rosterSearch').value.toLowerCase();
  document.querySelectorAll('#rosterTable tr').forEach(row => {
    row.style.display = row.dataset.name?.includes(q) ? '' : 'none';
  });
}

// ── Add-student modal filter ─────────────────────────
function filterAddStudentList() {
  const q = document.getElementById('addStudentSearch').value.toLowerCase();
  document.querySelectorAll('#addStudentSelect option').forEach(opt => {
    opt.style.display = opt.dataset.label.includes(q) ? '' : 'none';
  });
}

// ── Auto-open create modal if no sections exist ──────
<?php if (empty($all_sections)): ?>
window.addEventListener('DOMContentLoaded', () => openCreateModal());
<?php endif; ?>
</script>
<script>

function toggleDD(){
  document.getElementById('ddMenu').classList.toggle('open');
  document.getElementById('ddBtn').classList.toggle('open');
}
document.addEventListener('click',e=>{
  const dd=document.querySelector('.nav-dropdown');
  if(dd&&!dd.contains(e.target)){
    document.getElementById('ddMenu')?.classList.remove('open');
    document.getElementById('ddBtn')?.classList.remove('open');
  }
});
</script>
</body>
</html>
<!--PHPEOF
echo "Done — $(wc -l < /home/claude/manage_sections.php) lines"-->
