<?php
// ============================================================
//  teacher/subject_view.php
//  The main subject management hub. Tabs:
//    overview    — enrollees list + quick stats
//    written     — Written Works (quizzes, assignments, etc.)
//    exams       — Major Exams
//    performance — Performance Tasks + Attendance
//    grades      — Computed grade summary per student
//    settings    — Subject details, weights, enrollee management
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

$teacher_id = $_SESSION['user_id'];

// ── Load subject (must belong to this teacher) ──────────────
$subject_id = (int)($_GET['id'] ?? 0);
if (!$subject_id) { header("Location: /classroom/teacher/dashboard.php"); exit; }

$sub_stmt = $conn->prepare(
    "SELECT * FROM subjects WHERE id = ? AND teacher_id = ? AND is_active = 1"
);
$sub_stmt->bind_param("ii", $subject_id, $teacher_id);
$sub_stmt->execute();
$subject = $sub_stmt->get_result()->fetch_assoc();
if (!$subject) { header("Location: /classroom/teacher/dashboard.php"); exit; }

$active_tab = $_GET['tab'] ?? 'overview';
$valid_tabs = ['overview','written','exams','performance','attendance','biometric','grades','settings'];
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'overview';

$success_msg = '';
$error_msg   = '';


//----------------------------
/*$subject_id = (int)($_GET['id'] ?? 0); // if not already set
include 'subject_view_biometric.php';*/

// ── Shared: fetch enrolled students ─────────────────────────
function getEnrollees($conn, $subject_id) {
    $s = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name, s.middle_initial
         FROM subject_enrollments e
         JOIN students s USING(student_id)
         WHERE e.subject_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $s->bind_param("i", $subject_id);
    $s->execute();
    return $s->get_result();
}

// ══════════════════════════════════════════════════════════════
//  POST HANDLERS
// ══════════════════════════════════════════════════════════════

// ── CSV export (must run before any HTML output) ─────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && ($active_tab === 'grades' || ($_GET['tab'] ?? '') === 'grades')) {
    $csv = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name, ses
                COALESCE(g.exam_avg,0)         AS ea,
                COALESCE(g.written_avg,0)       AS wa,
                COALESCE(g.performance_avg,0)   AS pa,
                COALESCE(g.attendance_rate,0)   AS ar,
                COALESCE(g.final_grade,0)       AS fg,
                COALESCE(g.letter_grade,'N/A')  AS lg
         FROM subject_enrollments e
         JOIN students s USING(student_id)
         LEFT JOIN subject_grades g ON g.subject_id = ? AND g.student_id = s.student_id
         WHERE e.subject_id = ?
         ORDER BY g.final_grade DESC, s.last_name ASC"
    );
    $csv->bind_param("ii", $subject_id, $subject_id);
    $csv->execute();
    $csvr = $csv->get_result();
    $filename = addslashes($subject['subject_code'] . '_' . $subject['section']) . '_grades_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID','Last Name','First Name','Exam Avg','Written Avg','Perf Avg','Attendance %','Final Grade','Letter','Status']);
    while ($r = $csvr->fetch_assoc()) {
        fputcsv($out, [
            $r['student_id'], $r['last_name'], $r['first_name'],
            round($r['ea'],2), round($r['wa'],2), round($r['pa'],2), round($r['ar'],2),
            round($r['fg'],2), $r['lg'], $r['fg'] >= 75 ? 'Passed' : 'Failed'
        ]);
    }
    fclose($out);
    exit;
}

// ── AJAX: biometric live roster (must be before HTML output) ─
if (isset($_GET['bio_ajax']) && $_GET['bio_ajax'] === 'live') {
    header('Content-Type: application/json');
    $ajax_date = $_GET['date'] ?? date('Y-m-d');
    $aq = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name,
                a.status AS att_status, a.time_in, a.source,
                (ft.id IS NOT NULL) AS has_template
         FROM subject_enrollments se
         JOIN students s USING(student_id)
         LEFT JOIN attendance a
               ON  a.subject_id = se.subject_id
               AND a.student_id = se.student_id
               AND a.date = ?
         LEFT JOIN fingerprint_templates ft ON ft.student_id = s.student_id
         WHERE se.subject_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $aq->bind_param('si', $ajax_date, $subject_id);
    $aq->execute();
    $ajax_rows = $aq->get_result()->fetch_all(MYSQLI_ASSOC);

    $asq = $conn->prepare(
        "SELECT bs.id, bs.late_threshold, bs.auto_expire_at, bs.started_at,
                bd.label AS device_label
         FROM bio_sessions bs
         JOIN bio_devices bd ON bd.id = bs.device_id
         WHERE bs.subject_id = ? AND bs.status = 'active'
         ORDER BY bs.started_at DESC LIMIT 1"
    );
    $asq->bind_param('i', $subject_id);
    $asq->execute();
    $ajax_session = $asq->get_result()->fetch_assoc();

    $p = count(array_filter($ajax_rows, fn($r) => $r['att_status'] === 'Present'));
    $l = count(array_filter($ajax_rows, fn($r) => $r['att_status'] === 'Late'));
    $t = count($ajax_rows);
    echo json_encode([
        'session'  => $ajax_session,
        'students' => $ajax_rows,
        'counts'   => ['present'=>$p,'late'=>$l,'absent'=>$t-$p-$l,'total'=>$t],
        'time'     => date('H:i:s'),
    ]);
    exit;
}

// ── ADD score entry (written / exam / performance) ───────────
if (isset($_POST['add_score'])) {
     $component  = $_POST['component'];
     $entry_name = trim($_POST['entry_name']);
     $date_given = $_POST['date_given'];
     $scores     = $_POST['scores']    ?? [];
     $total_all  = round((float)($_POST['total_all'] ?? 0));

     $valid_components = ['Major Exam', 'Written Work', 'Performance Task'];

     if (!in_array($component, $valid_components)) {
         $error_msg = "Invalid component.";
     } elseif ($entry_name === '') {
         $error_msg = "Entry name is required.";
     } elseif ($total_all <= 0) {
         $error_msg = "Total items must be greater than 0.";
     } else {
         $saved = 0;
         foreach ($scores as $sid => $score) {
             // Skip students the teacher left blank rather than saving a bogus 0
             if ($score === '' || $score === null) continue;

             $score = round((float)$score);

             // Clamp to the valid 0..total_all range instead of dropping the row
             if ($score < 0) $score = 0;
             $score = min($score, $total_all);

             // Note: removed $conn->real_escape_string($sid) because prepared 
             // statements handle SQL injection protection automatically.
             $ins = $conn->prepare(
                 "INSERT INTO score_entries 
                  (subject_id, student_id, component, entry_name, score, total_items, date_given) 
                  VALUES (?, ?, ?, ?, ?, ?, ?)"
             );
             $ins->bind_param("isssdds", 
                 $subject_id, $sid, $component, $entry_name, 
                 $score, $total_all, $date_given
             );
             $ins->execute();
             
             recalcSubjectGrade($conn, $subject_id, $sid);
             $saved++;
         }
         $success_msg = "{$saved} score(s) saved under <strong>{$component} — {$entry_name}</strong>.";
     }
}

// ── DELETE score entry ───────────────────────────────────────
if (isset($_GET['del_score'])) {
    $score_id = (int)$_GET['del_score'];
    $del_stmt = $conn->prepare(
        "SELECT student_id FROM score_entries WHERE id = ? AND subject_id = ?"
    );
    $del_stmt->bind_param("ii", $score_id, $subject_id);
    $del_stmt->execute();
    $row = $del_stmt->get_result()->fetch_assoc();

    if ($row) {
        $d = $conn->prepare("DELETE FROM score_entries WHERE id = ? AND subject_id = ?");
        $d->bind_param("ii", $score_id, $subject_id);
        $d->execute();
        recalcSubjectGrade($conn, $subject_id, $row['student_id']);
    }
    header("Location: subject_view.php?id={$subject_id}&tab={$active_tab}&deleted=1");
    exit;
}
if (isset($_GET['deleted'])) $success_msg = "Entry deleted and grades updated.";

// ── EDIT score entry (written / exam / performance) ──────────
// Note: the per-row "Edit" button opens this activity's bulk-edit form
// (see bulk_edit_scores below) with just that row focused, so a single
// edit and a multi-row edit both go through the same save path.

// ── BULK EDIT scores (whole quiz/activity, tab-through-students) ──
if (isset($_POST['bulk_edit_scores'])) {
    $bulk_component  = $_POST['bulk_component']  ?? '';
    $bulk_entry_name = trim($_POST['bulk_entry_name'] ?? '');
    $bulk_scores     = $_POST['bulk_scores'] ?? []; // [score_id => new value]
    $valid_components = ['Major Exam', 'Written Work', 'Performance Task'];

    if (!in_array($bulk_component, $valid_components) || $bulk_entry_name === '') {
        $error_msg = "Invalid activity for bulk edit.";
    } else {
        $updated_count     = 0;
        $affected_students = [];

        foreach ($bulk_scores as $score_id => $new_val) {
            $score_id = (int)$score_id;
            if ($new_val === '') continue; // left blank → leave unchanged

            // Scope the lookup to this exact quiz/activity so a tampered
            // score_id from another activity can never be touched here
            $r_stmt = $conn->prepare(
                "SELECT student_id, total_items FROM score_entries
                 WHERE id = ? AND subject_id = ? AND component = ? AND entry_name = ?"
            );
            $r_stmt->bind_param("iiss", $score_id, $subject_id, $bulk_component, $bulk_entry_name);
            $r_stmt->execute();
            $row = $r_stmt->get_result()->fetch_assoc();
            if (!$row) continue;

            $new_score = round((float)$new_val);
            if ($new_score < 0) $new_score = 0;
            $new_score = min($new_score, (float)$row['total_items']);

            $u = $conn->prepare("UPDATE score_entries SET score = ? WHERE id = ? AND subject_id = ?");
            $u->bind_param("dii", $new_score, $score_id, $subject_id);
            $u->execute();

            $affected_students[$row['student_id']] = true;
            $updated_count++;
        }

        foreach (array_keys($affected_students) as $sid) {
            recalcSubjectGrade($conn, $subject_id, $sid);
        }

        $success_msg = "{$updated_count} score(s) updated for <strong>" . htmlspecialchars($bulk_entry_name) . "</strong>.";
    }
}

// ── BULK DELETE a whole quiz/activity ────────────────────────
if (isset($_POST['delete_quiz'])) {
    $del_component  = $_POST['del_component']  ?? '';
    $del_entry_name = trim($_POST['del_entry_name'] ?? '');
    $valid_components = ['Major Exam', 'Written Work', 'Performance Task'];

    if (!in_array($del_component, $valid_components) || $del_entry_name === '') {
        $error_msg = "Invalid activity to delete.";
    } else {
        $find = $conn->prepare(
            "SELECT DISTINCT student_id FROM score_entries
             WHERE subject_id = ? AND component = ? AND entry_name = ?"
        );
        $find->bind_param("iss", $subject_id, $del_component, $del_entry_name);
        $find->execute();
        $affected = $find->get_result()->fetch_all(MYSQLI_ASSOC);

        $del = $conn->prepare(
            "DELETE FROM score_entries WHERE subject_id = ? AND component = ? AND entry_name = ?"
        );
        $del->bind_param("iss", $subject_id, $del_component, $del_entry_name);
        $del->execute();
        $deleted_count = $del->affected_rows;

        foreach ($affected as $a) {
            recalcSubjectGrade($conn, $subject_id, $a['student_id']);
        }

        $success_msg = "Deleted <strong>{$deleted_count}</strong> score(s) for <strong>"
                     . htmlspecialchars($del_entry_name) . "</strong> and recalculated grades.";
    }
}

// ── SAVE bulk attendance ─────────────────────────────────────
if (isset($_POST['save_attendance'])) {
    $att_date = $_POST['att_date'];
    $statuses = $_POST['att_status'] ?? [];
    foreach ($statuses as $sid => $status) {
        $sid     = $conn->real_escape_string($sid);
        $time_in = ($status === 'Absent') ? null : ($_POST['time_in'][$sid] ?? null);
        $remarks = $conn->real_escape_string($_POST['att_remarks'][$sid] ?? '');
        $src     = 'Manual';
        $a = $conn->prepare(
            "INSERT INTO attendance (subject_id, student_id, date, time_in, status, remarks, source)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               status   = VALUES(status),
               time_in  = VALUES(time_in),
               remarks  = VALUES(remarks)"
        );
        $a->bind_param("issssss", $subject_id, $sid, $att_date, $time_in, $status, $remarks, $src);
        $a->execute();
        recalcSubjectGrade($conn, $subject_id, $sid);
    }
    $success_msg = "Attendance saved for <strong>{$att_date}</strong>.";
}

// ── EDIT a single attendance record ──────────────────────────
// Note: the per-row "Edit" button opens that day's bulk-edit form
// (see bulk_edit_attendance below) with just that row focused, so a
// single edit and a multi-row edit both go through the same save path.

// ── BULK EDIT attendance for one day (tab-through-students) ──
if (isset($_POST['bulk_edit_attendance'])) {
    $bea_date     = $_POST['bea_date'] ?? '';
    $bea_statuses = $_POST['bea_status']  ?? [];
    $bea_times    = $_POST['bea_time_in'] ?? [];
    $valid_statuses = ['Present', 'Late', 'Absent'];

    if ($bea_date === '') {
        $error_msg = "Invalid day for bulk attendance edit.";
    } else {
        $updated_count = 0;
        foreach ($bea_statuses as $sid => $status) {
            if (!in_array($status, $valid_statuses)) continue;
            $time_in = ($status === 'Absent') ? null : (($bea_times[$sid] ?? '') !== '' ? $bea_times[$sid] : null);

            $u = $conn->prepare(
                "UPDATE attendance SET status = ?, time_in = ?, source = 'Manual'
                 WHERE subject_id = ? AND student_id = ? AND date = ?"
            );
            $u->bind_param("ssiss", $status, $time_in, $subject_id, $sid, $bea_date);
            $u->execute();
            if ($u->affected_rows > 0) {
                recalcSubjectGrade($conn, $subject_id, $sid);
                $updated_count++;
            }
        }
        $success_msg = "{$updated_count} attendance record(s) updated for <strong>" . htmlspecialchars($bea_date) . "</strong>.";
    }
}

// ── DELETE a single attendance record ────────────────────────
if (isset($_GET['del_attendance'])) {
    $da_sid  = $_GET['del_attendance'];
    $da_date = $_GET['del_att_date'] ?? '';

    if ($da_sid !== '' && $da_date !== '') {
        $d = $conn->prepare(
            "DELETE FROM attendance WHERE subject_id = ? AND student_id = ? AND date = ?"
        );
        $d->bind_param("iss", $subject_id, $da_sid, $da_date);
        $d->execute();
        recalcSubjectGrade($conn, $subject_id, $da_sid);
    }
    header("Location: subject_view.php?id={$subject_id}&tab=attendance&att_date={$da_date}&att_deleted=1");
    exit;
}
if (isset($_GET['att_deleted'])) $success_msg = "Attendance record deleted and grade updated.";

// ── BULK DELETE a whole day's attendance ─────────────────────
if (isset($_POST['delete_attendance_day'])) {
    $dad_date = $_POST['dad_date'] ?? '';
    if ($dad_date === '') {
        $error_msg = "Invalid day to delete.";
    } else {
        $find = $conn->prepare(
            "SELECT DISTINCT student_id FROM attendance WHERE subject_id = ? AND date = ?"
        );
        $find->bind_param("is", $subject_id, $dad_date);
        $find->execute();
        $affected = $find->get_result()->fetch_all(MYSQLI_ASSOC);

        $del = $conn->prepare("DELETE FROM attendance WHERE subject_id = ? AND date = ?");
        $del->bind_param("is", $subject_id, $dad_date);
        $del->execute();
        $deleted_count = $del->affected_rows;

        foreach ($affected as $a) {
            recalcSubjectGrade($conn, $subject_id, $a['student_id']);
        }

        $success_msg = "Deleted <strong>{$deleted_count}</strong> attendance record(s) for <strong>"
                     . htmlspecialchars(date('M d, Y', strtotime($dad_date))) . "</strong>.";
    }
}

// ── START biometric session ───────────────────────────────────
if (isset($_POST['start_bio_session'])) {
    $dev_id = (int)$_POST['bio_device_id'];
    $hours  = (int)($_POST['bio_expire_hours'] ?? 2);
    $late_t = preg_match('/^\d{2}:\d{2}:\d{2}$/', $_POST['late_threshold'] ?? '')
              ? $_POST['late_threshold'] : '08:15:00';
    if ($dev_id <= 0) {
        $error_msg = "Please select a device.";
    } else {
        // End any active session on this device first
        $es = $conn->prepare(
            "UPDATE bio_sessions SET status='ended', ended_at=NOW()
             WHERE device_id=? AND status='active'"
        );
        $es->bind_param('i', $dev_id); $es->execute();

        $expire = $hours > 0 ? date('Y-m-d H:i:s', strtotime("+{$hours} hours")) : null;
        $ins = $conn->prepare(
            "INSERT INTO bio_sessions
             (device_id, subject_id, started_by, auto_expire_at, late_threshold, status)
             VALUES (?,?,?,?,?,'active')"
        );
        $ins->bind_param('iiiss', $dev_id, $subject_id, $teacher_id, $expire, $late_t);
        $ins->execute();
        $success_msg = "Biometric session started. Device is now live for this subject.";
    }
}

// ── STOP biometric session ────────────────────────────────────
if (isset($_POST['stop_bio_session'])) {
    $sess_id = (int)$_POST['session_id'];
    $st = $conn->prepare(
        "UPDATE bio_sessions SET status='ended', ended_at=NOW()
         WHERE id=? AND subject_id=?"
    );
    $st->bind_param('ii', $sess_id, $subject_id); $st->execute();
    $success_msg = "Biometric session ended.";
}
if (isset($_POST['recompute'])) {
    recalcAllStudentsInSubject($conn, $subject_id);
    $success_msg = "All grades recomputed.";
}

// ── UPDATE subject weights ───────────────────────────────────
if (isset($_POST['update_weights'])) {
    $ep = (float)$_POST['exam_pct'];
    $wp = (float)$_POST['written_pct'];
    $pp = (float)$_POST['performance_pct'];
    $ap = (float)$_POST['attendance_pct'];
    if (round($ep + $wp + $pp, 2) !== 100.00) {
        $error_msg = "Weights must total 100%.";
    } elseif ($ap >= $pp) {
        $error_msg = "Attendance % must be less than Performance %.";
    } else {
        $upd = $conn->prepare(
            "UPDATE subjects SET exam_pct=?, written_pct=?, performance_pct=?, attendance_pct=? WHERE id=?"
        );
        $upd->bind_param("ddddi", $ep, $wp, $pp, $ap, $subject_id);
        $upd->execute();
        // Reload subject
        $sub_stmt->execute();
        $subject = $sub_stmt->get_result()->fetch_assoc();
        recalcAllStudentsInSubject($conn, $subject_id);
        $success_msg = "Weights updated and all grades recomputed.";
    }
}

// ── UPDATE subject metadata ──────────────────────────────────
if (isset($_POST['update_subject_meta'])) {
    $sname = trim($_POST['subject_name']);
    $scode = trim($_POST['subject_code']);
    $ssect = trim($_POST['section']);
    $syear = trim($_POST['school_year']);
    $ssem  = $_POST['semester'];
    $stype = $_POST['subject_type'];
    $valid_types = ['General Education', 'Professional Education', 'Major Subject'];
    $valid_sems  = ['1st', '2nd', 'Summer'];
    if ($sname === '' || $scode === '') {
        $error_msg = "Subject name and code are required.";
    } elseif (!in_array($stype, $valid_types) || !in_array($ssem, $valid_sems)) {
        $error_msg = "Invalid subject type or semester.";
    } else {
        $upd = $conn->prepare(
            "UPDATE subjects
             SET subject_name=?, subject_code=?, section=?,
                 school_year=?, semester=?, subject_type=?
             WHERE id=? AND teacher_id=?"
        );
        $upd->bind_param('ssssssii', $sname, $scode, $ssect, $syear, $ssem, $stype, $subject_id, $teacher_id);
        $upd->execute();
        $sub_stmt->execute();
        $subject = $sub_stmt->get_result()->fetch_assoc();
        $success_msg = "Subject details updated.";
    }
}

// ── ENROLL entire section ────────────────────────────────────
if (isset($_POST['enroll_section'])) {
    $sec_id = (int)$_POST['enroll_section_id'];
    $sq = $conn->prepare("SELECT student_id FROM section_students WHERE section_id = ?");
    $sq->bind_param('i', $sec_id);
    $sq->execute();
    $sec_students = $sq->get_result();
    $added = 0;
    while ($ss_row = $sec_students->fetch_assoc()) {
        $sid = $ss_row['student_id'];
        $e1 = $conn->prepare(
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
    $success_msg = "Enrolled <strong>{$added}</strong> student(s) from section.";
}

// ── ENROLL single student ────────────────────────────────────
if (isset($_POST['enroll_single'])) {
    $sid = trim($_POST['single_student_id']);
    if ($sid === '') {
        $error_msg = "Please select a student.";
    } else {
        $chk = $conn->prepare(
            "SELECT id FROM subject_enrollments WHERE subject_id=? AND student_id=? LIMIT 1"
        );
        $chk->bind_param('is', $subject_id, $sid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error_msg = "Student is already enrolled.";
        } else {
            $e1 = $conn->prepare(
                "INSERT IGNORE INTO subject_enrollments (subject_id, student_id) VALUES (?, ?)"
            );
            $e1->bind_param('is', $subject_id, $sid);
            $e1->execute();
            $e2 = $conn->prepare(
                "INSERT IGNORE INTO subject_grades (subject_id, student_id) VALUES (?, ?)"
            );
            $e2->bind_param('is', $subject_id, $sid);
            $e2->execute();
            $success_msg = "Student enrolled.";
        }
    }
}

// ── UNENROLL student ─────────────────────────────────────────
if (isset($_POST['unenroll_student'])) {
    $sid = trim($_POST['unenroll_sid']);
    $del = $conn->prepare(
        "DELETE FROM subject_enrollments WHERE subject_id=? AND student_id=?"
    );
    $del->bind_param('is', $subject_id, $sid);
    $del->execute();
    $success_msg = "Student removed from subject. Their scores and grades are preserved.";
}

// ══════════════════════════════════════════════════════════════
//  DATA FOR CURRENT TAB
// ══════════════════════════════════════════════════════════════
$enrollees      = getEnrollees($conn, $subject_id);
$enrollee_count = $enrollees->num_rows;

// Quick stats
$stats = $conn->prepare(
    "SELECT
        ROUND(AVG(final_grade),1)                         AS avg,
        ROUND(MAX(final_grade),1)                         AS highest,
        SUM(final_grade >= 75)                            AS passing,
        SUM(final_grade > 0 AND final_grade < 75)         AS failing,
        COUNT(*)                                          AS total
     FROM subject_grades WHERE subject_id = ?"
);
$stats->bind_param("i", $subject_id);
$stats->execute();
$st = $stats->get_result()->fetch_assoc();

// Score entries for written / exams / performance tabs
$comp_map = [
    'written'     => 'Written Work',
    'exams'       => 'Major Exam',
    'performance' => 'Performance Task',
];
$current_component = $comp_map[$active_tab] ?? null;

// Distinct entry names for the current component
$entry_names = [];
if ($current_component) {
    $en = $conn->prepare(
        "SELECT DISTINCT entry_name, total_items, date_given
         FROM score_entries
         WHERE subject_id=? AND component=?
         ORDER BY date_given ASC"
    );
    $en->bind_param("is", $subject_id, $current_component);
    $en->execute();
    $entry_names_res = $en->get_result();
    while ($r = $entry_names_res->fetch_assoc()) $entry_names[] = $r;
}

// Attendance dates for performance/attendance tabs
$att_dates = [];
if ($active_tab === 'performance' || $active_tab === 'attendance') {
    $ad = $conn->prepare(
        "SELECT DISTINCT date FROM attendance WHERE subject_id=? ORDER BY date DESC LIMIT 30"
    );
    $ad->bind_param("i", $subject_id);
    $ad->execute();
    $adr = $ad->get_result();
    while ($r = $adr->fetch_assoc()) $att_dates[] = $r['date'];
}

$view_att_date = $_GET['att_date'] ?? (count($att_dates) > 0 ? $att_dates[0] : date('Y-m-d'));

// Settings tab data
$enrolled_list     = null;
$not_enrolled_list = [];
$sections_list     = [];
if ($active_tab === 'settings') {
    $enrolled_q = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name, s.middle_initial,
                sec.section_name, se.section_id
         FROM subject_enrollments se
         JOIN students s USING(student_id)
         LEFT JOIN sections sec ON sec.id = se.section_id
         WHERE se.subject_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $enrolled_q->bind_param('i', $subject_id);
    $enrolled_q->execute();
    $enrolled_list = $enrolled_q->get_result();

    $enrolled_ids = [];
    while ($er = $enrolled_list->fetch_assoc()) $enrolled_ids[] = $er['student_id'];
    $enrolled_list->data_seek(0);

    if ($enrolled_ids) {
        $ph    = implode(',', array_fill(0, count($enrolled_ids), '?'));
        $ne    = $conn->prepare(
            "SELECT student_id, last_name, first_name FROM students
             WHERE student_id NOT IN ($ph) ORDER BY last_name ASC"
        );
        $types = str_repeat('s', count($enrolled_ids));
        $ne->bind_param($types, ...$enrolled_ids);
    } else {
        $ne = $conn->prepare(
            "SELECT student_id, last_name, first_name FROM students ORDER BY last_name ASC"
        );
    }
    $ne->execute();
    $ne_result = $ne->get_result();
    while ($r = $ne_result->fetch_assoc()) $not_enrolled_list[] = $r;

    $sec_res = $conn->query("SELECT id, section_name FROM sections ORDER BY section_name ASC");
    while ($sr = $sec_res->fetch_assoc()) $sections_list[] = $sr;
}

// Type colors
$type_color = match($subject['subject_type']) {
    'General Education'      => '#7aa3ff',
    'Professional Education' => '#4ade80',
    'Major Subject'          => '#fbbf24',
    default                  => '#7aa3ff',
};

$page_title        = $subject['subject_code'] . ' — ' . $subject['section'];
$active_nav        = 'subject_view';
$active_subject_id = $subject_id;

// ── Biometric: devices + active session ───────────────────────
$bio_devices_res = $conn->query("SELECT id, label, last_seen FROM bio_devices ORDER BY label ASC");
$bio_devices = $bio_devices_res ? $bio_devices_res->fetch_all(MYSQLI_ASSOC) : [];

// Auto-expire overdue sessions
$conn->query(
    "UPDATE bio_sessions SET status='ended', ended_at=NOW()
     WHERE status='active' AND auto_expire_at IS NOT NULL AND auto_expire_at < NOW()"
);

$bsq = $conn->prepare(
    "SELECT bs.id, bs.device_id, bs.started_at, bs.auto_expire_at, bs.late_threshold,
            d.label AS device_label
     FROM bio_sessions bs
     JOIN bio_devices d ON d.id = bs.device_id
     WHERE bs.subject_id=? AND bs.status='active'
     ORDER BY bs.started_at DESC LIMIT 1"
);
$bsq->bind_param('i', $subject_id);
$bsq->execute();
$active_bio_session = $bsq->get_result()->fetch_assoc();

// Today's biometric scans for this subject
$bscans_q = $conn->prepare(
    "SELECT bl.student_id, bl.scanned_at, bl.status,
            s.first_name, s.last_name
     FROM biometric_log bl
     LEFT JOIN students s
       ON s.student_id COLLATE utf8mb4_unicode_ci = bl.student_id COLLATE utf8mb4_unicode_ci
     WHERE bl.subject_id=? AND DATE(bl.scanned_at)=CURDATE()
       AND bl.status IN ('present','late','dup')
     ORDER BY bl.scanned_at DESC LIMIT 20"
);
$bscans_q->bind_param('i', $subject_id);
$bscans_q->execute();
$bio_scans_today = $bscans_q->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?php echo htmlspecialchars($subject['subject_code'] . ' ' . $subject['subject_name']); ?> — CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="/classroom/assets/style.css">
  <style>body.page-teacher-subject_view{--subject-color:<?php echo htmlspecialchars($type_color, ENT_QUOTES, 'UTF-8'); ?>;}</style>

</head>
<body class="page-teacher-subject_view">

<?php $teacher_subjects = getTeacherSubjects($conn, $teacher_id); ?>
<nav class="navbar">
  <a class="brand" href="/classroom/teacher/dashboard.php">
    <img src="/classroom/assets/images/TCM logo (2).png" alt="TCM logo" width="32" height="32">
    Classroom Management System
  </a>
  <div class="nav-sep"></div>
  <a href="/classroom/teacher/dashboard.php" class="nav-link">
    <i class="ti ti-layout-dashboard"></i> Dashboard
  </a>

  <div class="nav-dropdown">
    <button class="nav-dd-btn" id="ddBtn" onclick="toggleDD()">
      <i class="ti ti-books"></i>
      <?php echo htmlspecialchars($subject['subject_code'] . ' — ' . $subject['section']); ?>
      <i class="ti ti-chevron-down"></i>
    </button>
    <div class="nav-dd-menu" id="ddMenu">
      <?php
      $teacher_subjects->data_seek(0);
      while ($ns = $teacher_subjects->fetch_assoc()):
        $dot_color = match($ns['subject_type']) {
            'General Education'      => '#6c8dda',
            'Professional Education' => '#ff2407',
            'Major Subject'          => '#00ff1a',
            default                  => '#7aa3ff',
        };
      ?>
      <a href="/classroom/teacher/subject_view.php?id=<?php echo $ns['id']; ?>"
         class="dd-item <?php echo $ns['id'] == $subject_id ? 'active' : ''; ?>">
        <span class="dd-dot" style="background:<?php echo $dot_color; ?>"></span>
        <span class="dd-main"><?php echo htmlspecialchars($ns['subject_code'] . ' ' . $ns['subject_name']); ?></span>
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

  <a href="/classroom/teacher/add_subject.php" class="nav-link">
    <i class="ti ti-book-plus"></i> Add Subject
  </a>
  <a href="/classroom/teacher/manage_sections.php" class="nav-link">
    <i class="ti ti-building-community"></i> Sections
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

<!-- ── SUBJECT HERO ── -->
<div class="subject-hero">
  <div class="hero-top">
    <div class="hero-left">
      <div class="hero-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
      <div class="hero-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
      <div class="hero-meta">
        <span class="hero-meta-item">
          <i class="ti ti-users"></i>
          <?php echo $enrollee_count; ?> students
        </span>
        <span class="hero-meta-item">
          <i class="ti ti-school"></i>
          <?php echo htmlspecialchars($subject['section']); ?>
        </span>
        <span class="hero-meta-item">
          <i class="ti ti-calendar"></i>
          <?php echo htmlspecialchars($subject['school_year']); ?> — <?php echo $subject['semester']; ?> Sem
        </span>
        <span class="type-badge" style="color:var(--subject-color);border-color:var(--subject-color);background:rgba(91,141,238,.08);">
          <?php echo htmlspecialchars($subject['subject_type']); ?>
        </span>
      </div>
    </div>
    <div class="hero-weights">
      <div class="w-chip exam">
        <i class="ti ti-file-certificate" style="font-size:11px;"></i>
        Major Exams <?php echo (int)$subject['exam_pct']; ?>%
      </div>
      <div class="w-chip written">
        <i class="ti ti-pencil" style="font-size:11px;"></i>
        Written <?php echo (int)$subject['written_pct']; ?>%
      </div>
      <div class="w-chip perf">
        <i class="ti ti-star" style="font-size:11px;"></i>
        Performance <?php echo (int)$subject['performance_pct']; ?>%
      </div>
      <div class="w-chip att">
        <i class="ti ti-calendar-check" style="font-size:11px;"></i>
        Attendance <?php echo (int)$subject['attendance_pct']; ?>%
      </div>
    </div>
  </div>

  <div class="hero-stats">
    <div class="hstat">
      <div class="hstat-val"><?php echo $enrollee_count; ?></div>
      <div class="hstat-lbl">Enrolled</div>
    </div>
    <div class="hstat">
      <div class="hstat-val" style="color:var(--subject-color)">
        <?php echo $st['avg'] ?? '—'; ?><?php echo $st['avg'] ? '%' : ''; ?>
      </div>
      <div class="hstat-lbl">Class Avg</div>
    </div>
    <div class="hstat">
      <div class="hstat-val" style="color:var(--green)"><?php echo $st['passing'] ?? 0; ?></div>
      <div class="hstat-lbl">Passing</div>
    </div>
    <div class="hstat">
      <div class="hstat-val" style="color:var(--red)"><?php echo $st['failing'] ?? 0; ?></div>
      <div class="hstat-lbl">Failing</div>
    </div>
    <div class="hstat">
      <div class="hstat-val"><?php echo $st['highest'] ?? '—'; ?><?php echo $st['highest'] ? '%' : ''; ?></div>
      <div class="hstat-lbl">Highest</div>
    </div>
  </div>
</div>

<!-- ── TAB STRIP ── -->
<div class="tab-strip">
  <?php
  $tabs = [
    ['overview',    'ti-users',             'Overview',       $enrollee_count],
    ['written',     'ti-pencil',            'Written Works',  null],
    ['exams',       'ti-file-certificate',  'Major Exams',    null],
    ['performance', 'ti-star',              'Performance',    null],
    ['attendance',  'ti-calendar-check',    'Attendance',     null],
    ['biometric',   'ti-fingerprint',       'Biometric',      null],
    ['grades',      'ti-chart-bar',         'Grades',         null],
    ['settings',    'ti-settings',          'Settings',       null],
  ];
  foreach ($tabs as [$tab_id, $icon, $label, $count]):
  ?>
  <a href="subject_view.php?id=<?php echo $subject_id; ?>&tab=<?php echo $tab_id; ?>"
     class="tab-btn <?php echo $active_tab === $tab_id ? 'active' : ''; ?>">
    <i class="ti <?php echo $icon; ?>"></i>
    <?php echo $label; ?>
    <?php if ($count !== null): ?>
      <span class="tab-count"><?php echo $count; ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── PAGE BODY ── -->
<div class="page-body">

<?php if ($success_msg): ?>
<div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════
//  TAB: OVERVIEW
// ══════════════════════════════════════════════════════
if ($active_tab === 'overview'):
    $grade_map = [];
    $gres = $conn->prepare(
        "SELECT student_id, final_grade, letter_grade, exam_avg, written_avg, attendance_rate
         FROM subject_grades WHERE subject_id=?"
    );
    $gres->bind_param("i", $subject_id);
    $gres->execute();
    $gr = $gres->get_result();
    while ($g = $gr->fetch_assoc()) $grade_map[$g['student_id']] = $g;

    // Chart data
    $chart_data = $conn->prepare(
        "SELECT student_id, final_grade FROM subject_grades
         WHERE subject_id=? AND final_grade>0 ORDER BY final_grade DESC"
    );
    $chart_data->bind_param("i", $subject_id);
    $chart_data->execute();
    $cd = $chart_data->get_result();
    $chart_labels = []; $chart_values = []; $chart_colors = [];
    $enr_map = [];
    $enrollees->data_seek(0);
    while ($e = $enrollees->fetch_assoc()) $enr_map[$e['student_id']] = $e['last_name'];
    while ($r = $cd->fetch_assoc()) {
        $chart_labels[] = $enr_map[$r['student_id']] ?? $r['student_id'];
        $chart_values[] = round((float)$r['final_grade'], 1);
        $chart_colors[] = (float)$r['final_grade'] >= 75 ? 'rgba(52,211,153,.7)' : 'rgba(248,113,113,.7)';
    }
?>
<div class="two-col">
  <div class="card">
    <p class="card-title"><i class="ti ti-users"></i> Enrollees</p>
    <?php if ($enrollee_count === 0): ?>
      <div class="empty-state">
        <i class="ti ti-users-off"></i>
        <p>No students enrolled.<br>
          <a href="/classroom/teacher/subject_view.php?id=<?php echo $subject_id; ?>&tab=settings" style="color:var(--subject-color)">Add students to this subject →</a>
        </p>
      </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:2px;">
      <?php
      $enrollees->data_seek(0);
      while ($s = $enrollees->fetch_assoc()):
        $g        = $grade_map[$s['student_id']] ?? null;
        $initials = strtoupper(substr($s['last_name'],0,1) . substr($s['first_name'],0,1));
        $fg       = $g ? (float)$g['final_grade'] : 0;
        $pass     = $fg >= 75;
      ?>
      <div class="enrollee-row">
        <div class="enrollee-avatar"><?php echo $initials; ?></div>
        <div style="flex:1;">
          <div class="enrollee-name"><?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?></div>
          <div class="enrollee-id"><?php echo htmlspecialchars($s['student_id']); ?></div>
        </div>
        <?php if ($g && $fg > 0): ?>
          <span style="font-family:var(--font-head);font-size:15px;font-weight:700;color:<?php echo $pass ? 'var(--green)' : 'var(--red)'; ?>">
            <?php echo number_format($fg, 1); ?>%
          </span>
          <span class="badge <?php echo $pass ? 'badge-green' : 'badge-red'; ?>" style="font-size:10px;">
            <?php echo $g['letter_grade']; ?>
          </span>
        <?php else: ?>
          <span style="font-size:11px;color:var(--text3);">No data</span>
        <?php endif; ?>
      </div>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card" style="margin-bottom:20px;">
      <p class="card-title"><i class="ti ti-chart-bar"></i> Grade Distribution</p>
      <?php if (empty($chart_labels)): ?>
        <div class="empty-state"><i class="ti ti-chart-off"></i><p>No grades computed yet.</p></div>
      <?php else: ?>
        <canvas id="gradeChart" height="160"></canvas>
      <?php endif; ?>
    </div>

    <div class="card">
      <p class="card-title"><i class="ti ti-percentage"></i> Grade Composition</p>
      <div class="grade-breakdown">
        <div class="grade-comp-card">
          <div class="gc-pct" style="color:#7aa3ff;"><?php echo (int)$subject['exam_pct']; ?>%</div>
          <div class="gc-label">Major Exams</div>
          <div class="gc-weight">Class avg: <?php echo number_format($st['avg'] ?? 0, 1); ?>%</div>
        </div>
        <div class="grade-comp-card">
          <div class="gc-pct" style="color:#34d399;"><?php echo (int)$subject['written_pct']; ?>%</div>
          <div class="gc-label">Written Works</div>
        </div>
        <div class="grade-comp-card">
          <div class="gc-pct" style="color:#fbbf24;"><?php echo (int)$subject['performance_pct']; ?>%</div>
          <div class="gc-label">Performance</div>
          <div class="gc-weight">Incl. <?php echo (int)$subject['attendance_pct']; ?>% attendance</div>
        </div>
      </div>
      <div class="weight-bar">
        <div class="weight-bar-seg" style="width:<?php echo $subject['exam_pct']; ?>%;background:#7aa3ff;"></div>
        <div class="weight-bar-seg" style="width:<?php echo $subject['written_pct']; ?>%;background:#34d399;"></div>
        <div class="weight-bar-seg" style="width:<?php echo $subject['performance_pct']; ?>%;background:#fbbf24;"></div>
      </div>
    </div>
  </div>
</div>

<?php
// ══════════════════════════════════════════════════════
//  TAB: WRITTEN WORKS / MAJOR EXAMS / PERFORMANCE TASKS
// ══════════════════════════════════════════════════════
elseif (in_array($active_tab, ['written','exams','performance'])):
    $comp_label = $current_component;
    $comp_color = match($current_component) {
        'Major Exam'       => '#7aa3ff',
        'Written Work'     => '#34d399',
        'Performance Task' => '#fbbf24',
        default            => 'var(--subject-color)',
    };
    $comp_icon = match($current_component) {
        'Major Exam'       => 'ti-file-certificate',
        'Written Work'     => 'ti-pencil',
        'Performance Task' => 'ti-star',
        default            => 'ti-clipboard',
    };

    $all_scores = $conn->prepare(
        "SELECT se.*, s.last_name, s.first_name
         FROM score_entries se
         JOIN students s USING(student_id)
         WHERE se.subject_id=? AND se.component=?
         ORDER BY se.date_given ASC, se.entry_name ASC, s.last_name ASC"
    );
    $all_scores->bind_param("is", $subject_id, $current_component);
    $all_scores->execute();
    $scores_result = $all_scores->get_result();

    $grouped = [];
    while ($r = $scores_result->fetch_assoc()) {
        $grouped[$r['entry_name']][] = $r;
    }
?>
<div class="two-col">

  <!-- ADD SCORE FORM -->
  <div>
    <div class="card">
      <p class="card-title">
        <i class="ti <?php echo $comp_icon; ?>" style="color:<?php echo $comp_color; ?>"></i>
        Add <?php echo $comp_label; ?>
      </p>
      <form method="POST">
        <input type="hidden" name="component" value="<?php echo $current_component; ?>">

        <div class="form-group">
          <label><?php echo $comp_label; ?> Name</label>
          <input type="text" name="entry_name" class="form-control"
            placeholder="<?php echo $current_component === 'Major Exam' ? 'e.g. Midterm Exam' : ($current_component === 'Written Work' ? 'e.g. Quiz 1, Assignment 2' : 'e.g. Lab Exercise 3, Performance 1'); ?>"
            required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Total Items / Max Score</label>
            <input type="number" name="total_all" id="total_all" class="form-control"
              placeholder="e.g. 100" min="1" step="1" required oninput="updateMaxScores()">
          </div>
          <div class="form-group">
            <label>Date Given</label>
            <input type="date" name="date_given" class="form-control"
              value="<?php echo date('Y-m-d'); ?>" required>
          </div>
        </div>

        <div class="divider"></div>

        <p class="card-title" style="margin-bottom:10px;">
          <i class="ti ti-list"></i> Enter Scores Per Student
        </p>

        <?php if ($enrollee_count === 0): ?>
          <p style="font-size:13px;color:var(--text2);">No students enrolled.</p>
        <?php else: ?>
        <div class="score-entry-grid">
          <div class="score-row score-row-header">
            <span>Student</span>
            <span style="text-align:right;">Score /</span>
            <span style="text-align:right;">&emsp;&nbsp;Total</span>
            <span style="text-align:right;">%</span>
          </div>
          <?php
          $enrollees->data_seek(0);
          while ($s = $enrollees->fetch_assoc()):
            $initials = strtoupper(substr($s['last_name'],0,1) . substr($s['first_name'],0,1));
          ?>
          <div class="score-row">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="enrollee-avatar" style="width:28px;height:28px;font-size:11px;flex-shrink:0;">
                <?php echo $initials; ?>
              </div>
              <span style="font-size:12px;font-weight:500;">
                <?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?>
              </span>
            </div>
            <input type="number" name="scores[<?php echo htmlspecialchars($s['student_id']); ?>]"
              class="form-control score-input" style="text-align:right;padding:5px 8px;"
              placeholder="0" min="0" step="1"
              oninput="calcPct(this, '<?php echo htmlspecialchars($s['student_id']); ?>')"
              onblur="calcPct(this, '<?php echo htmlspecialchars($s['student_id']); ?>')">
            <span style="font-size:12px;color:var(--text2);text-align:right;padding-right:4px;"
              id="tot_<?php echo htmlspecialchars($s['student_id']); ?>">/ —</span>
            <span style="font-size:12px;font-weight:600;text-align:right;color:var(--text2);"
              id="pct_<?php echo htmlspecialchars($s['student_id']); ?>">—</span>
          </div>
          <?php endwhile; ?>
        </div>
        <?php endif; ?>

        <button type="submit" name="add_score" class="btn btn-primary" style="margin-top:16px;background:<?php echo $comp_color; ?>;">
          <i class="ti ti-device-floppy"></i> Save <?php echo $comp_label; ?>
        </button>
      </form>
    </div>

  </div>

  <!-- SCORES TABLE -->
  <div>
    <?php if (empty($grouped)): ?>
    <div class="card">
      <div class="empty-state">
        <i class="ti <?php echo $comp_icon; ?>" style="color:<?php echo $comp_color; ?>"></i>
        <p style="color:var(--text2);">No <?php echo $comp_label; ?> entries yet.</p>
        <p style="font-size:12px;margin-top:6px;">Use the form on the left to add scores.</p>
      </div>
    </div>
    <?php else: ?>
    <?php foreach ($grouped as $ename => $rows):
        $total   = $rows[0]['total_items'];
        $avg     = array_sum(array_column($rows, 'score')) / count($rows);
        $avg_pct = $total > 0 ? $avg / $total * 100 : 0;
        $date    = date('M d, Y', strtotime($rows[0]['date_given']));
        $gkey    = md5($active_tab . '|' . $ename);
    ?>
    <div class="card" style="padding-bottom:10px;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;min-width:200px;"
             onclick="toggleGroup('<?php echo $gkey; ?>')">
          <i class="ti ti-chevron-down" id="gp_chev_<?php echo $gkey; ?>"
             style="transition:transform .2s;color:var(--text3);"></i>
          <div>
            <p class="card-title" style="margin-bottom:4px;">
              <i class="ti <?php echo $comp_icon; ?>" style="color:<?php echo $comp_color; ?>"></i>
              <?php echo htmlspecialchars($ename); ?>
            </p>
            <div style="display:flex;gap:10px;font-size:12px;color:var(--text2);flex-wrap:wrap;">
              <span><i class="ti ti-calendar" style="font-size:12px;"></i> <?php echo $date; ?></span>
              <span>Total items: <strong style="color:var(--text)"><?php echo $total; ?></strong></span>
              <span>Students: <strong style="color:var(--text)"><?php echo count($rows); ?></strong></span>
              <span>Class avg:
                <strong style="color:<?php echo $avg_pct >= 75 ? 'var(--green)' : 'var(--red)'; ?>">
                  <?php echo number_format($avg_pct, 1); ?>%
                </strong>
              </span>
            </div>
          </div>
        </div>

        <!-- Default actions: Edit All / Delete Quiz -->
        <div id="gp_actions_default_<?php echo $gkey; ?>" style="display:flex;gap:6px;">
          <button type="button" class="btn btn-sm btn-outline" title="Edit all scores in this activity"
            onclick="startGroupEdit('<?php echo $gkey; ?>')">
            <i class="ti ti-edit"></i> Edit All
          </button>
          <button type="button" class="btn btn-sm btn-outline" style="color:var(--red);border-color:rgba(248,113,113,.3);"
            title="Delete this entire activity for all students"
            onclick="confirmDeleteQuiz('<?php echo $gkey; ?>', '<?php echo htmlspecialchars(addslashes($ename)); ?>')">
            <i class="ti ti-trash"></i> Delete Quiz
          </button>
        </div>

        <!-- Edit-mode actions: Save All / Cancel -->
        <div id="gp_actions_edit_<?php echo $gkey; ?>" style="display:none;gap:6px;">
          <button type="submit" form="group_form_<?php echo $gkey; ?>" name="bulk_edit_scores"
            class="btn btn-sm btn-primary" style="background:var(--green);" title="Save all changes">
            <i class="ti ti-device-floppy"></i> Save All
          </button>
          <button type="button" class="btn btn-sm btn-outline" title="Cancel"
            onclick="cancelGroupEdit('<?php echo $gkey; ?>')">
            <i class="ti ti-x"></i> Cancel
          </button>
        </div>
      </div>

      <!-- Hidden form used solely to bulk-delete this activity -->
      <form method="POST" id="delq_form_<?php echo $gkey; ?>" style="display:none;">
        <input type="hidden" name="del_component"  value="<?php echo htmlspecialchars($current_component); ?>">
        <input type="hidden" name="del_entry_name" value="<?php echo htmlspecialchars($ename); ?>">
        <input type="hidden" name="delete_quiz" value="1">
      </form>

      <div id="gp_body_<?php echo $gkey; ?>" class="group-body" style="display:none;margin-top:14px;">
        <form method="POST" id="group_form_<?php echo $gkey; ?>">
          <input type="hidden" name="bulk_component"  value="<?php echo htmlspecialchars($current_component); ?>">
          <input type="hidden" name="bulk_entry_name" value="<?php echo htmlspecialchars($ename); ?>">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Score</th>
                  <th>Percentage</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r):
                    $pct = $r['total_items'] > 0 ? $r['score'] / $r['total_items'] * 100 : 0;
                    $pc  = $pct >= 75 ? 'var(--green)' : ($pct >= 60 ? 'var(--yellow)' : 'var(--red)');
                ?>
                <tr>
                  <td>
                    <div style="font-weight:500;"><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?></div>
                    <div class="td-mono"><?php echo htmlspecialchars($r['student_id']); ?></div>
                  </td>
                  <td>
                    <span class="score-view" id="sv_<?php echo $r['id']; ?>"
                      style="font-family:var(--font-mono);font-size:13px;">
                      <?php echo $r['score'] . '/' . $r['total_items']; ?>
                    </span>
                    <input type="number" name="bulk_scores[<?php echo $r['id']; ?>]"
                      id="bulk_input_<?php echo $r['id']; ?>"
                      class="form-control score-edit-input"
                      style="display:none;width:64px;padding:3px 6px;font-size:12px;"
                      value="<?php echo $r['score']; ?>" data-original="<?php echo $r['score']; ?>"
                      min="0" max="<?php echo $r['total_items']; ?>" step="1"
                      oninput="clampEditInput(this)" onblur="clampEditInput(this)">
                    <span style="font-size:12px;color:var(--text2);">/ <?php echo $r['total_items']; ?></span>
                  </td>
                  <td>
                    <div class="score-bar-wrap">
                      <div class="score-bar-track">
                        <div class="score-bar-fill" style="width:<?php echo min($pct,100); ?>%;background:<?php echo $pc; ?>;"></div>
                      </div>
                      <span style="font-size:12px;font-weight:600;color:<?php echo $pc; ?>;min-width:42px;text-align:right;">
                        <?php echo number_format($pct, 1); ?>%
                      </span>
                    </div>
                  </td>
                  <td class="row-actions-default">
                    <button type="button" class="btn btn-sm btn-ghost" title="Edit"
                      onclick="focusRowEdit('<?php echo $gkey; ?>', '<?php echo $r['id']; ?>')">
                      <i class="ti ti-edit"></i>
                    </button>
                    <a href="subject_view.php?id=<?php echo $subject_id; ?>&tab=<?php echo $active_tab; ?>&del_score=<?php echo $r['id']; ?>"
                       class="btn btn-sm btn-ghost" title="Delete"
                       onclick="return confirm('Delete this score? The grade will be recalculated.')">
                      <i class="ti ti-trash"></i>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php
// ══════════════════════════════════════════════════════
//  TAB: ATTENDANCE
// ══════════════════════════════════════════════════════
elseif ($active_tab === 'attendance'):
    // Fetch existing attendance for the selected date
    $att_existing = [];
    $ae = $conn->prepare(
        "SELECT student_id, status, time_in
         FROM attendance WHERE subject_id=? AND date=?"
    );
    $ae->bind_param("is", $subject_id, $view_att_date);
    $ae->execute();
    $aer = $ae->get_result();
    while ($r = $aer->fetch_assoc()) $att_existing[$r['student_id']] = $r;

    // Attendance summary counts for the selected date
    $att_summary = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
    foreach ($att_existing as $row) {
        if (isset($att_summary[$row['status']])) $att_summary[$row['status']]++;
    }
    $att_not_yet = $enrollee_count - array_sum($att_summary);

    // Fetch attendance for ALL recent dates in one query, grouped by date,
    // so each day can be rendered as its own collapsible container below
    $att_grouped = [];
    if (!empty($att_dates)) {
        $placeholders = implode(',', array_fill(0, count($att_dates), '?'));
        $types  = 'i' . str_repeat('s', count($att_dates));
        $params = array_merge([$subject_id], $att_dates);
        $att_all_q = $conn->prepare(
            "SELECT a.date, s.student_id, s.last_name, s.first_name, a.status, a.time_in, a.source
             FROM attendance a
             JOIN students s ON s.student_id = a.student_id
             WHERE a.subject_id = ? AND a.date IN ($placeholders)
             ORDER BY s.last_name ASC, s.first_name ASC"
        );
        $att_all_q->bind_param($types, ...$params);
        $att_all_q->execute();
        $att_all_res = $att_all_q->get_result();
        while ($ar = $att_all_res->fetch_assoc()) {
            $att_grouped[$ar['date']][] = $ar;
        }
    }
?>

<!-- Stat chips -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
  <?php foreach ([
    ['Present', $att_summary['Present'],    'var(--green)',  'var(--green-dim)',  'rgba(52,211,153,.25)'],
    ['Late',    $att_summary['Late'],        'var(--yellow)', 'var(--yellow-dim)','rgba(251,191,36,.25)'],
    ['Absent',  $att_summary['Absent'],      'var(--red)',    'var(--red-dim)',   'rgba(248,113,113,.25)'],
    ['Total',   $enrollee_count,             'var(--accent)', 'var(--accent-dim)','var(--accent-glow)'],
  ] as [$lbl, $val, $col, $bg, $bdr]): ?>
  <div style="background:<?php echo $bg; ?>;border:1px solid <?php echo $bdr; ?>;
              border-radius:var(--radius-lg);padding:16px;text-align:center;">
    <div style="font-family:var(--font-head);font-size:28px;font-weight:700;color:<?php echo $col; ?>;">
      <?php echo $val; ?>
    </div>
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                letter-spacing:.08em;color:var(--text3);margin-top:3px;"><?php echo $lbl; ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="two-col">

  <!-- LEFT: Date picker + quick mark + form -->
  <div>
    <div class="card">
      <p class="card-title">
        <i class="ti ti-calendar-check" style="color:var(--purple);"></i>
        Manual Attendance
        <span style="font-size:11px;font-weight:400;color:var(--text2);margin-left:4px;">
          (<?php echo (int)$subject['attendance_pct']; ?>% of final grade)
        </span>
      </p>

      <!-- Date picker -->
      <form method="GET" style="display:flex;gap:8px;margin-bottom:14px;">
        <input type="hidden" name="id"  value="<?php echo $subject_id; ?>">
        <input type="hidden" name="tab" value="attendance">
        <input type="date" name="att_date" class="form-control" style="flex:1;"
          value="<?php echo htmlspecialchars($view_att_date); ?>"
          max="<?php echo date('Y-m-d'); ?>">
        <button type="submit" class="btn btn-outline btn-sm">Load</button>
      </form>

      <!-- Quick mark all -->
      <div style="display:flex;gap:6px;margin-bottom:14px;">
        <button type="button" class="btn btn-sm btn-outline" onclick="markAllAtt('Present')">
          <i class="ti ti-check"></i> All Present
        </button>
        <button type="button" class="btn btn-sm btn-outline" onclick="markAllAtt('Late')">
          <i class="ti ti-clock"></i> All Late
        </button>
        <button type="button" class="btn btn-sm btn-outline" onclick="markAllAtt('Absent')">
          <i class="ti ti-x"></i> All Absent
        </button>
      </div>

      <!-- Per-student form -->
      <?php if ($enrollee_count === 0): ?>
        <p style="font-size:13px;color:var(--text2);">No students enrolled.</p>
      <?php else: ?>
      <form method="POST">
        <input type="hidden" name="att_date" value="<?php echo htmlspecialchars($view_att_date); ?>">
        <div style="display:flex;flex-direction:column;gap:6px;">
          <?php
          $enrollees->data_seek(0);
          while ($s = $enrollees->fetch_assoc()):
            $sid      = $s['student_id'];
            $ex       = $att_existing[$sid] ?? null;
            $status   = $ex['status']  ?? 'Present';
            $timein   = $ex['time_in'] ?? '';
            $initials = strtoupper(substr($s['last_name'],0,1) . substr($s['first_name'],0,1));
          ?>
          <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;
                      background:var(--bg3);border-radius:var(--radius);">
            <div class="enrollee-avatar"
              style="width:30px;height:30px;font-size:11px;flex-shrink:0;"><?php echo $initials; ?></div>
            <span style="font-size:13px;font-weight:500;flex:1;">
              <?php echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name']); ?>
            </span>
            <div class="att-radio-group">
              <?php foreach (['Present','Late','Absent'] as $opt): ?>
              <input type="radio"
                name="att_status[<?php echo htmlspecialchars($sid); ?>]"
                id="att_<?php echo htmlspecialchars($sid) . '_' . $opt; ?>"
                value="<?php echo $opt; ?>"
                class="att-radio-<?php echo htmlspecialchars($sid); ?>"
                data-sid="<?php echo htmlspecialchars($sid); ?>"
                <?php echo $status === $opt ? 'checked' : ''; ?>>
              <label for="att_<?php echo htmlspecialchars($sid) . '_' . $opt; ?>"><?php echo $opt; ?></label>
              <?php endforeach; ?>
            </div>
            <input type="time"
              name="time_in[<?php echo htmlspecialchars($sid); ?>]"
              id="timein_<?php echo htmlspecialchars($sid); ?>"
              class="form-control" style="width:110px;padding:5px 8px;font-size:12px;"
              value="<?php echo htmlspecialchars($timein); ?>"
              <?php echo $status === 'Absent' ? 'disabled' : ''; ?>>
          </div>
          <?php endwhile; ?>
        </div>
        <button type="submit" name="save_attendance"
          class="btn btn-primary" style="margin-top:14px;width:100%;justify-content:center;background:var(--purple);">
          <i class="ti ti-device-floppy"></i>
          Save Attendance for <?php echo date('M d, Y', strtotime($view_att_date)); ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: Recent dates history -->
  <div>
    <div class="card">
      <p class="card-title"><i class="ti ti-history"></i> Attendance History</p>

      <?php if (empty($att_dates)): ?>
      <div class="empty-state">
        <i class="ti ti-calendar-off"></i>
        <p>No attendance records yet.</p>
      </div>
      <?php else: ?>

      <?php foreach ($att_dates as $d):
          $day_rows = $att_grouped[$d] ?? [];
          $day_counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
          foreach ($day_rows as $dr) {
              if (isset($day_counts[$dr['status']])) $day_counts[$dr['status']]++;
          }
          $dkey = md5('att|' . $d);
          $is_today = $d === date('Y-m-d');
      ?>
      <div class="card" style="background:var(--bg3);padding-bottom:10px;margin-bottom:10px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
          <div style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;min-width:180px;"
               onclick="toggleAttDay('<?php echo $dkey; ?>')">
            <i class="ti ti-chevron-down" id="att_chev_<?php echo $dkey; ?>"
               style="transition:transform .2s;color:var(--text3);"></i>
            <div>
              <p style="font-weight:600;font-size:13px;">
                <?php echo date('M d, Y', strtotime($d)); ?>
                <?php if ($is_today): ?>
                <span style="font-size:9px;font-weight:700;color:var(--purple);"> TODAY</span>
                <?php endif; ?>
              </p>
              <div style="display:flex;gap:8px;font-size:11px;color:var(--text2);flex-wrap:wrap;">
                <span style="color:var(--green);"><?php echo $day_counts['Present']; ?> Present</span>
                <span style="color:var(--yellow);"><?php echo $day_counts['Late']; ?> Late</span>
                <span style="color:var(--red);"><?php echo $day_counts['Absent']; ?> Absent</span>
              </div>
            </div>
          </div>

          <div id="att_actions_default_<?php echo $dkey; ?>" style="display:flex;gap:6px;">
            <a href="subject_view.php?id=<?php echo $subject_id; ?>&tab=attendance&att_date=<?php echo $d; ?>"
               class="btn btn-sm btn-ghost" title="Load this date into the Manual Attendance form">
              <i class="ti ti-edit-circle"></i>
            </a>
            <button type="button" class="btn btn-sm btn-outline" title="Edit all records for this day"
              onclick="startAttDayEdit('<?php echo $dkey; ?>')">
              <i class="ti ti-edit"></i> Edit All
            </button>
            <button type="button" class="btn btn-sm btn-outline" style="color:var(--red);border-color:rgba(248,113,113,.3);"
              title="Delete this entire day's attendance"
              onclick="confirmDeleteAttDay('<?php echo $dkey; ?>', '<?php echo date('M d, Y', strtotime($d)); ?>')">
              <i class="ti ti-trash"></i> Delete Day
            </button>
          </div>

          <div id="att_actions_edit_<?php echo $dkey; ?>" style="display:none;gap:6px;">
            <button type="submit" form="att_form_<?php echo $dkey; ?>" name="bulk_edit_attendance"
              class="btn btn-sm btn-primary" style="background:var(--green);" title="Save all changes for this day">
              <i class="ti ti-device-floppy"></i> Save All
            </button>
            <button type="button" class="btn btn-sm btn-outline" title="Cancel"
              onclick="cancelAttDayEdit('<?php echo $dkey; ?>')">
              <i class="ti ti-x"></i> Cancel
            </button>
          </div>
        </div>

        <!-- Hidden form used solely to bulk-delete this day -->
        <form method="POST" id="deld_form_<?php echo $dkey; ?>" style="display:none;">
          <input type="hidden" name="dad_date" value="<?php echo htmlspecialchars($d); ?>">
          <input type="hidden" name="delete_attendance_day" value="1">
        </form>

        <div id="att_body_<?php echo $dkey; ?>" class="group-body"
             style="display:<?php echo ($d === $view_att_date) ? 'block' : 'none'; ?>;margin-top:12px;">
          <form method="POST" id="att_form_<?php echo $dkey; ?>">
            <input type="hidden" name="bea_date" value="<?php echo htmlspecialchars($d); ?>">
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Student</th>
                    <th>Status</th>
                    <th>Time In</th>
                    <th>Source</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($day_rows as $ar):
                      $st_color = match($ar['status'] ?? '') {
                        'Present' => 'var(--green)',
                        'Late'    => 'var(--yellow)',
                        'Absent'  => 'var(--red)',
                        default   => 'var(--text3)',
                      };
                      $st_bg = match($ar['status'] ?? '') {
                        'Present' => 'var(--green-dim)',
                        'Late'    => 'var(--yellow-dim)',
                        'Absent'  => 'var(--red-dim)',
                        default   => 'var(--bg3)',
                      };
                      $rk = $dkey . '_' . htmlspecialchars($ar['student_id']);
                  ?>
                  <tr>
                    <td>
                      <div style="font-weight:500;">
                        <?php echo htmlspecialchars($ar['last_name'] . ', ' . $ar['first_name']); ?>
                      </div>
                      <div class="td-mono"><?php echo htmlspecialchars($ar['student_id']); ?></div>
                    </td>
                    <td>
                      <span class="att-view" id="att_view_<?php echo $rk; ?>">
                        <span style="font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;
                                     background:<?php echo $st_bg; ?>;color:<?php echo $st_color; ?>;">
                          <?php echo htmlspecialchars($ar['status']); ?>
                        </span>
                      </span>
                      <span class="att-edit-input" style="display:none;" id="att_radios_<?php echo $rk; ?>">
                        <?php foreach (['Present','Late','Absent'] as $opt): ?>
                        <label style="font-size:11px;margin-right:6px;">
                          <input type="radio"
                            name="bea_status[<?php echo htmlspecialchars($ar['student_id']); ?>]"
                            value="<?php echo $opt; ?>"
                            class="att-bulk-radio-<?php echo $rk; ?>"
                            onchange="attRowStatusChanged('<?php echo $rk; ?>')"
                            <?php echo $ar['status'] === $opt ? 'checked' : ''; ?>>
                          <?php echo $opt; ?>
                        </label>
                        <?php endforeach; ?>
                      </span>
                    </td>
                    <td style="font-family:var(--font-mono);font-size:11px;color:var(--text2);">
                      <span class="att-view" id="att_time_view_<?php echo $rk; ?>">
                        <?php echo $ar['time_in'] ? substr($ar['time_in'], 0, 5) : '—'; ?>
                      </span>
                      <input type="time" class="form-control att-edit-input"
                        id="att_time_<?php echo $rk; ?>"
                        name="bea_time_in[<?php echo htmlspecialchars($ar['student_id']); ?>]"
                        style="display:none;width:100px;padding:4px 6px;font-size:11px;"
                        value="<?php echo htmlspecialchars(substr($ar['time_in'] ?? '', 0, 5)); ?>"
                        <?php echo $ar['status'] === 'Absent' ? 'disabled' : ''; ?>>
                    </td>
                    <td style="font-size:11px;color:var(--text2);">
                      <?php if ($ar['source'] === 'Biometric'): ?>
                        <span style="color:var(--accent);"><i class="ti ti-fingerprint" style="font-size:12px;"></i> Bio</span>
                      <?php elseif ($ar['source']): ?>
                        <?php echo htmlspecialchars($ar['source']); ?>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td class="row-actions-default">
                      <button type="button" class="btn btn-sm btn-ghost" title="Edit"
                        onclick="focusAttRowEdit('<?php echo $dkey; ?>', '<?php echo $rk; ?>')">
                        <i class="ti ti-edit"></i>
                      </button>
                      <a href="subject_view.php?id=<?php echo $subject_id; ?>&tab=attendance&att_date=<?php echo $d; ?>&del_attendance=<?php echo urlencode($ar['student_id']); ?>&del_att_date=<?php echo $d; ?>"
                         class="btn btn-sm btn-ghost" title="Delete"
                         onclick="return confirm('Delete this attendance record? The grade will be recalculated.')">
                        <i class="ti ti-trash"></i>
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if (empty($day_rows)): ?>
                  <tr><td colspan="5" style="text-align:center;color:var(--text2);font-size:12px;">No records for this day.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php
// ══════════════════════════════════════════════════════
//  TAB: BIOMETRIC
// ══════════════════════════════════════════════════════
elseif ($active_tab === 'biometric'):
    // Seed initial roster for JS (PHP-rendered = zero flicker)
    $bio_today  = date('Y-m-d');
    $bio_init_q = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name,
                a.status AS att_status, a.time_in, a.source,
                (ft.id IS NOT NULL) AS has_template
         FROM subject_enrollments se
         JOIN students s USING(student_id)
         LEFT JOIN attendance a
               ON  a.subject_id = se.subject_id
               AND a.student_id = se.student_id
               AND a.date = ?
         LEFT JOIN fingerprint_templates ft ON ft.student_id = s.student_id
         WHERE se.subject_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $bio_init_q->bind_param('si', $bio_today, $subject_id);
    $bio_init_q->execute();
    $bio_init_students = $bio_init_q->get_result()->fetch_all(MYSQLI_ASSOC);

    $bio_present = count(array_filter($bio_init_students, fn($r) => $r['att_status'] === 'Present'));
    $bio_late    = count(array_filter($bio_init_students, fn($r) => $r['att_status'] === 'Late'));
    $bio_total   = count($bio_init_students);
    $bio_absent  = $bio_total - $bio_present - $bio_late;
?>

<!-- Bio stat chips -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
  <?php foreach ([
    ['Present', $bio_present, 'var(--green)',  'var(--green-dim)',  'rgba(52,211,153,.25)',  'bioStatPresent'],
    ['Late',    $bio_late,    'var(--yellow)', 'var(--yellow-dim)', 'rgba(251,191,36,.25)',  'bioStatLate'],
    ['Not Yet', $bio_absent,  'var(--red)',    'var(--red-dim)',    'rgba(248,113,113,.25)', 'bioStatAbsent'],
    ['Total',   $bio_total,   'var(--accent)', 'var(--accent-dim)','var(--accent-glow)',    'bioStatTotal'],
  ] as [$lbl, $val, $col, $bg, $bdr, $elid]): ?>
  <div style="background:<?php echo $bg; ?>;border:1px solid <?php echo $bdr; ?>;
              border-radius:var(--radius-lg);padding:16px;text-align:center;">
    <div id="<?php echo $elid; ?>"
      style="font-family:var(--font-head);font-size:28px;font-weight:700;color:<?php echo $col; ?>;">
      <?php echo $val; ?>
    </div>
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;
                letter-spacing:.08em;color:var(--text3);margin-top:3px;"><?php echo $lbl; ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;">

  <!-- LEFT: Session control + device status -->
  <div>
    <div class="card" style="<?php echo $active_bio_session
        ? 'border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.03);' : ''; ?>">
      <p class="card-title">
        <i class="ti ti-player-play"
          style="color:<?php echo $active_bio_session ? 'var(--green)' : 'var(--text2)'; ?>;"></i>
        Session Control
        <?php if ($active_bio_session): ?>
        <span style="display:inline-flex;align-items:center;gap:5px;margin-left:6px;">
          <span style="width:7px;height:7px;border-radius:50%;background:var(--green);
                       animation:pulse 1.5s infinite;display:inline-block;"></span>
          <span style="font-size:10px;font-weight:400;color:var(--green);">LIVE</span>
        </span>
        <?php endif; ?>
      </p>

      <?php if ($active_bio_session): ?>
      <div style="background:rgba(52,211,153,.08);border:1px solid rgba(52,211,153,.2);
                  border-radius:var(--radius);padding:14px;margin-bottom:14px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px;">
          <i class="ti ti-cpu" style="color:var(--green);"></i>
          <?php echo htmlspecialchars($active_bio_session['device_label']); ?>
        </div>
        <div style="font-size:12px;color:var(--text2);display:flex;flex-direction:column;gap:4px;">
          <div style="display:flex;justify-content:space-between;">
            <span>Started</span>
            <span style="font-family:var(--font-mono);"><?php echo date('g:i A', strtotime($active_bio_session['started_at'])); ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;">
            <span>Late after</span>
            <span style="font-family:var(--font-mono);"><?php echo date('g:i A', strtotime($active_bio_session['late_threshold'])); ?></span>
          </div>
          <?php if ($active_bio_session['auto_expire_at']): ?>
          <div style="display:flex;justify-content:space-between;">
            <span>Expires</span>
            <span style="font-family:var(--font-mono);"><?php echo date('g:i A', strtotime($active_bio_session['auto_expire_at'])); ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="session_id" value="<?php echo $active_bio_session['id']; ?>">
        <button type="submit" name="stop_bio_session"
          class="btn btn-danger btn-sm" style="width:100%;justify-content:center;"
          onclick="return confirm('End this biometric session?')">
          <i class="ti ti-player-stop"></i> End Session
        </button>
      </form>

      <?php elseif (empty($bio_devices)): ?>
      <div class="empty-state" style="padding:20px;">
        <i class="ti ti-cpu-off"></i>
        <p>No devices assigned to this subject.<br>
          <a href="/classroom/teacher/biometric.php" style="color:var(--accent);">
            Biometric Setup →
          </a>
        </p>
      </div>

      <?php else: ?>
      <div style="background:var(--bg3);border:1px solid var(--border2);
                  border-radius:var(--radius);padding:14px;">
        <p style="font-size:12px;color:var(--text2);margin-bottom:12px;
                  display:flex;align-items:center;gap:5px;">
          <i class="ti ti-clock"></i> No active session
        </p>
        <form method="POST">
          <div class="form-group">
            <label>Device</label>
            <select name="bio_device_id" class="form-control" required>
              <option value="">— Choose device —</option>
              <?php foreach ($bio_devices as $bd):
                $online = $bd['last_seen'] && (time() - strtotime($bd['last_seen']) < 120);
              ?>
              <option value="<?php echo $bd['id']; ?>">
                <?php echo ($online ? '🟢 ' : '⚫ ') . htmlspecialchars($bd['label']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="form-group">
              <label>Late After</label>
              <input type="time" name="late_threshold" class="form-control"
                value="08:15" step="60" required>
            </div>
            <div class="form-group">
              <label>Auto-Stop</label>
              <select name="bio_expire_hours" class="form-control">
                <option value="1">1 hour</option>
                <option value="2" selected>2 hours</option>
                <option value="3">3 hours</option>
                <option value="0">Never</option>
              </select>
            </div>
          </div>
          <button type="submit" name="start_bio_session"
            class="btn btn-primary btn-sm"
            style="width:100%;justify-content:center;background:var(--green);margin-top:4px;">
            <i class="ti ti-player-play"></i> Start Session
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($bio_devices)): ?>
    <div class="card">
      <p class="card-title"><i class="ti ti-cpu"></i> Devices</p>
      <?php foreach ($bio_devices as $bd):
        $online = $bd['last_seen'] && (time() - strtotime($bd['last_seen']) < 120);
        $ls     = !$bd['last_seen'] ? 'Never'
                  : (time() - strtotime($bd['last_seen']) < 60 ? 'Just now'
                     : round((time() - strtotime($bd['last_seen'])) / 60) . 'm ago');
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:9px 0;
                  border-bottom:1px solid var(--border);">
        <div style="width:7px;height:7px;border-radius:50%;flex-shrink:0;
          background:<?php echo $online ? 'var(--green)' : 'var(--text3)'; ?>;
          <?php echo $online ? 'animation:pulse 1.5s infinite;' : ''; ?>"></div>
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:500;"><?php echo htmlspecialchars($bd['label']); ?></div>
        </div>
        <span style="font-size:11px;color:<?php echo $online ? 'var(--green)' : 'var(--text3)'; ?>;">
          <?php echo $online ? 'Online' : $ls; ?>
        </span>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:12px;text-align:center;">
        <a href="/classroom/teacher/biometric.php" class="btn btn-outline btn-sm" style="font-size:11px;">
          <i class="ti ti-settings"></i> Manage Devices &amp; Enrollment
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT: Live roster -->
  <div class="card">
    <p class="card-title">
      <i class="ti ti-users"></i> Live Attendance Roster
      <?php if ($active_bio_session): ?>
      <span style="display:inline-flex;align-items:center;gap:5px;margin-left:6px;">
        <span style="width:6px;height:6px;border-radius:50%;background:var(--green);
                     animation:pulse 1.5s infinite;display:inline-block;"></span>
        <span style="font-size:9px;font-weight:400;color:var(--green);">Updates every 5s</span>
      </span>
      <?php endif; ?>
      <span id="bioLastUpdated"
        style="margin-left:auto;font-size:9px;font-weight:400;color:var(--text3);"></span>
    </p>

    <!-- Filter tabs -->
    <div style="display:flex;gap:6px;margin-bottom:12px;">
      <button class="btn btn-sm" id="bioFilterAll"
        style="background:rgba(52,211,153,.12);color:var(--green);border:1px solid rgba(52,211,153,.2);"
        onclick="setBioFilter('all')">All</button>
      <button class="btn btn-sm" id="bioFilterPresent"
        style="background:transparent;color:var(--text2);border:1px solid var(--border2);"
        onclick="setBioFilter('present')">Present</button>
      <button class="btn btn-sm" id="bioFilterLate"
        style="background:transparent;color:var(--text2);border:1px solid var(--border2);"
        onclick="setBioFilter('late')">Late</button>
      <button class="btn btn-sm" id="bioFilterAbsent"
        style="background:transparent;color:var(--text2);border:1px solid var(--border2);"
        onclick="setBioFilter('not yet')">Not Yet</button>
    </div>

    <!-- Search -->
    <div style="position:relative;margin-bottom:12px;">
      <i class="ti ti-search" style="position:absolute;left:10px;top:50%;
         transform:translateY(-50%);color:var(--text3);font-size:13px;pointer-events:none;"></i>
      <input type="text" id="bioRosterSearch" class="form-control"
        style="padding-left:32px;" placeholder="Search student…"
        oninput="filterBioRoster()">
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Student</th><th>Fingerprint</th>
            <th>Status</th><th>Time In</th><th>Source</th>
          </tr>
        </thead>
        <tbody id="bioRosterBody"></tbody>
      </table>
    </div>
  </div>

</div><!-- end biometric grid -->

<script>
(function () {
  const SUBJECT_ID = <?php echo $subject_id; ?>;
  const IS_ACTIVE  = <?php echo $active_bio_session ? 'true' : 'false'; ?>;
  const POLL_MS    = IS_ACTIVE ? 5000 : 30000;
  const TODAY      = '<?php echo $bio_today; ?>';
  let   bioFilter  = 'all';
  let   bioSearch  = '';
  let   lastData   = null;

  const initStudents = <?php echo json_encode($bio_init_students); ?>;
  renderRoster(initStudents);

  function badge(text, col, bg) {
    return `<span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:99px;
      background:${bg};color:${col};">${text}</span>`;
  }
  function statusBadge(s) {
    if (s === 'Present') return badge('Present','#34d399','rgba(52,211,153,.12)');
    if (s === 'Late')    return badge('Late','#fbbf24','rgba(251,191,36,.12)');
    if (s === 'Absent')  return badge('Absent','#f87171','rgba(248,113,113,.12)');
    return badge('—','#3d4560','#1a1e2b');
  }
  function sourceBadge(src) {
    if (!src) return '';
    return src === 'Biometric'
      ? badge('<i class="ti ti-fingerprint" style="font-size:9px;"></i> Bio','#5b8dee','rgba(91,141,238,.12)')
      : badge('Manual','#3d4560','#1a1e2b');
  }
  function fpBadge(has) {
    return has
      ? badge('✓ Enrolled','#34d399','rgba(52,211,153,.12)')
      : badge('✗ None','#3d4560','#1a1e2b');
  }

  function renderRoster(students) {
    const tbody = document.getElementById('bioRosterBody');
    if (!tbody) return;
    const q = bioSearch.toLowerCase();
    tbody.innerHTML = students.map((s, i) => {
      const name   = (s.last_name + ', ' + s.first_name).toLowerCase();
      const dispSt = (s.att_status || '').toLowerCase() || 'not yet';
      const show   = (bioFilter === 'all' || bioFilter === dispSt)
                  && (q === '' || name.includes(q));
      if (!show) return `<tr style="display:none;" data-status="${dispSt}" data-name="${name}"></tr>`;
      return `<tr data-status="${dispSt}" data-name="${name}">
        <td style="color:var(--text3);font-size:11px;">${i+1}</td>
        <td>
          <div style="font-weight:500;">${s.last_name}, ${s.first_name}</div>
          <div style="font-family:var(--font-mono);font-size:10px;color:var(--text3);">${s.student_id}</div>
        </td>
        <td>${fpBadge(s.has_template)}</td>
        <td>${statusBadge(s.att_status)}</td>
        <td style="font-family:var(--font-mono);font-size:11px;color:var(--text2);">
          ${s.time_in ? s.time_in.substring(0,5) : '—'}
        </td>
        <td>${sourceBadge(s.source)}</td>
      </tr>`;
    }).join('');
  }

  function updateChips(c) {
    const m = {bioStatPresent:'present',bioStatLate:'late',bioStatAbsent:'absent',bioStatTotal:'total'};
    for (const [id, key] of Object.entries(m)) {
      const el = document.getElementById(id);
      if (el) el.textContent = c[key] ?? 0;
    }
  }

  function pollLive() {
    fetch(`?id=${SUBJECT_ID}&tab=biometric&bio_ajax=live&date=${TODAY}`)
      .then(r => r.json())
      .then(d => {
        lastData = d;
        renderRoster(d.students);
        updateChips(d.counts);
        const el = document.getElementById('bioLastUpdated');
        if (el) el.textContent = 'Updated ' + d.time;
      })
      .catch(() => {});
  }

  setInterval(pollLive, POLL_MS);

  window.filterBioRoster = function () {
    bioSearch = document.getElementById('bioRosterSearch').value;
    renderRoster(lastData ? lastData.students : initStudents);
  };

  window.setBioFilter = function (f) {
    bioFilter = f;
    const map = {
      bioFilterAll:'all', bioFilterPresent:'present',
      bioFilterLate:'late', bioFilterAbsent:'not yet'
    };
    Object.entries(map).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (!el) return;
      const on = val === f;
      el.style.background  = on ? 'rgba(52,211,153,.12)' : 'transparent';
      el.style.color       = on ? '#34d399'              : 'var(--text2)';
      el.style.borderColor = on ? 'rgba(52,211,153,.2)'  : 'var(--border2)';
    });
    renderRoster(lastData ? lastData.students : initStudents);
  };
})();
</script>

<?php
// ══════════════════════════════════════════════════════
//  TAB: GRADES
// ══════════════════════════════════════════════════════
elseif ($active_tab === 'grades'):
    $all_grades = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name,
                COALESCE(g.exam_avg,0)             AS exam_avg,
                COALESCE(g.written_avg,0)           AS written_avg,
                COALESCE(g.performance_avg,0)       AS performance_avg,
                COALESCE(g.attendance_rate,0)       AS attendance_rate,
                COALESCE(g.performance_component,0) AS perf_component,
                COALESCE(g.final_grade,0)           AS final_grade,
                COALESCE(g.letter_grade,'N/A')      AS letter_grade
         FROM subject_enrollments e
         JOIN students s USING(student_id)
         LEFT JOIN subject_grades g ON g.subject_id=? AND g.student_id=s.student_id
         WHERE e.subject_id=?
         ORDER BY g.final_grade DESC, s.last_name ASC"
    );
    $all_grades->bind_param("ii", $subject_id, $subject_id);
    $all_grades->execute();
    $grades_res = $all_grades->get_result();
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
  <div>
    <h2 style="font-family:var(--font-head);font-size:18px;font-weight:700;color:var(--text);">Grade Summary</h2>
    <p style="font-size:12px;color:var(--text2);">
      Auto-computed from scores and attendance. Formula: Exam×<?php echo (int)$subject['exam_pct']; ?>% + Written×<?php echo (int)$subject['written_pct']; ?>% + Performance×<?php echo (int)$subject['performance_pct']; ?>%
    </p>
  </div>
  <div style="display:flex;gap:8px;">
    <form method="POST" style="display:inline;">
      <button type="submit" name="recompute" class="btn btn-outline btn-sm"
        onclick="return confirm('Recompute all grades?')">
        <i class="ti ti-refresh"></i> Recompute All
      </button>
    </form>
    <a href="subject_view.php?id=<?php echo $subject_id; ?>&tab=grades&export=csv" class="btn btn-outline btn-sm">
      <i class="ti ti-file-spreadsheet"></i> Export CSV
    </a>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th style="color:#7aa3ff;">Exam Avg <span style="font-weight:400;color:var(--text3);">(<?php echo (int)$subject['exam_pct']; ?>%)</span></th>
          <th style="color:#34d399;">Written Avg <span style="font-weight:400;color:var(--text3);">(<?php echo (int)$subject['written_pct']; ?>%)</span></th>
          <th style="color:#fbbf24;">Perf. Task</th>
          <th style="color:#a78bfa;">Attendance</th>
          <th>Final Grade</th>
          <th>Letter</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($grades_res->num_rows === 0): ?>
        <tr><td colspan="8">
          <div class="empty-state"><i class="ti ti-chart-off"></i><p>No grade data yet.</p></div>
        </td></tr>
        <?php endif; ?>
        <?php $rank = 1; while ($r = $grades_res->fetch_assoc()):
            $fg   = (float)$r['final_grade'];
            $pass = $fg >= 75;
            $letter_color = match(true) {
                $fg >= 85 => 'badge-green',
                $fg >= 75 => 'badge-blue',
                $fg >= 70 => 'badge-yellow',
                $fg > 0   => 'badge-red',
                default   => '',
            };
        ?>
        <tr>
          <td style="color:var(--text3);font-size:12px;"><?php echo $rank++; ?></td>
          <td>
            <div style="font-weight:500;font-size:13px;"><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?></div>
            <div class="td-mono"><?php echo htmlspecialchars($r['student_id']); ?></div>
          </td>
          <?php foreach ([
            [$r['exam_avg'],        '#7aa3ff'],
            [$r['written_avg'],     '#34d399'],
            [$r['performance_avg'], '#fbbf24'],
            [$r['attendance_rate'], '#a78bfa'],
          ] as [$val, $color]):
              $v = (float)$val;
          ?>
          <td>
            <div class="score-bar-wrap">
              <div class="score-bar-track">
                <div class="score-bar-fill" style="width:<?php echo min($v,100); ?>%;background:<?php echo $color; ?>;opacity:.7;"></div>
              </div>
              <span style="font-size:12px;min-width:40px;text-align:right;color:<?php echo $v >= 75 ? 'var(--green)' : ($v > 0 ? 'var(--red)' : 'var(--text3)'); ?>;">
                <?php echo $v > 0 ? number_format($v,1) . '%' : '—'; ?>
              </span>
            </div>
          </td>
          <?php endforeach; ?>
          <td>
            <span style="font-family:var(--font-head);font-size:18px;font-weight:700;color:<?php echo $fg >= 75 ? 'var(--green)' : ($fg > 0 ? 'var(--red)' : 'var(--text3)'); ?>;">
              <?php echo $fg > 0 ? number_format($fg,2) . '%' : '—'; ?>
            </span>
          </td>
          <td>
            <?php if ($fg > 0): ?>
              <span class="badge <?php echo $letter_color; ?>" style="font-size:12px;padding:3px 10px;">
                <?php echo $r['letter_grade']; ?>
              </span>
              <span style="font-size:11px;color:<?php echo $pass ? 'var(--green)' : 'var(--red)'; ?>;display:block;margin-top:3px;">
                <?php echo $pass ? 'Passed' : 'Failed'; ?>
              </span>
            <?php else: ?>
              <span style="color:var(--text3);font-size:12px;">No data</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <p style="font-size:11px;color:var(--text3);margin-top:12px;padding-top:10px;border-top:1px solid var(--border);">
    Performance component = (Perf. Task Avg × <?php echo (int)($subject['performance_pct'] - $subject['attendance_pct']); ?>% + Attendance × <?php echo (int)$subject['attendance_pct']; ?>%) / <?php echo (int)$subject['performance_pct']; ?>%
    &nbsp;·&nbsp; Last computed: <?php echo date('M d, Y g:i A'); ?>
  </p>
</div>

<?php
// ══════════════════════════════════════════════════════
//  TAB: SETTINGS
// ══════════════════════════════════════════════════════
elseif ($active_tab === 'settings'):
?>
<div class="settings-wrap">

  <!-- 1. Subject Details -->
  <div class="card" style="max-width:700px;margin-bottom:20px; transform: translate(-150px, 0px);">
    <p class="card-title"><i class="ti ti-pencil"></i> Edit Subject Details</p>
    <form method="POST">
      <input type="hidden" name="update_subject_meta">
      <div class="form-row">
        <div class="form-group">
          <label>Subject Code <span style="color:var(--red)">*</span></label>
          <input type="text" name="subject_code" class="form-control"
            value="<?php echo htmlspecialchars($subject['subject_code']); ?>" required>
        </div>
        <div class="form-group">
          <label>Section</label>
          <input type="text" name="section" class="form-control"
            value="<?php echo htmlspecialchars($subject['section']); ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Subject Name <span style="color:var(--red)">*</span></label>
        <input type="text" name="subject_name" class="form-control"
          value="<?php echo htmlspecialchars($subject['subject_name']); ?>" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>School Year</label>
          <input type="text" name="school_year" class="form-control"
            value="<?php echo htmlspecialchars($subject['school_year']); ?>">
        </div>
        <div class="form-group">
          <label>Semester</label>
          <select name="semester" class="form-control">
            <?php foreach (['1st','2nd','Summer'] as $sem): ?>
            <option value="<?php echo $sem; ?>" <?php echo ($subject['semester'] === $sem) ? 'selected' : ''; ?>>
              <?php echo $sem; ?> Semester
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Subject Type</label>
        <select name="subject_type" class="form-control">
          <?php foreach (['General Education','Professional Education','Major Subject'] as $t): ?>
          <option value="<?php echo $t; ?>" <?php echo ($subject['subject_type'] === $t) ? 'selected' : ''; ?>>
            <?php echo $t; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="ti ti-device-floppy"></i> Save Subject Details
      </button>
    </form>
  </div>

  <!-- 2. Grade Weights -->
  <div class="card" style="max-width:700px;margin-bottom:20px; transform: translate(600px, -430px);">
    <p class="card-title"><i class="ti ti-percentage"></i> Edit Grade Weights</p>
    <p style="font-size:12px;color:var(--text2);margin-bottom:16px;">
      Changing weights will immediately recompute all student grades for this subject.
    </p>
    <form method="POST">
      <div class="weight-row">
        <div class="form-group">
          <label style="color:#7aa3ff;">Major Exams %</label>
          <input type="number" name="exam_pct" id="s_exam" class="form-control"
            value="<?php echo (int)$subject['exam_pct']; ?>"
            min="0" max="100" step="1" oninput="sUpdateTotal()" required>
        </div>
        <div class="form-group">
          <label style="color:#34d399;">Written Works %</label>
          <input type="number" name="written_pct" id="s_written" class="form-control"
            value="<?php echo (int)$subject['written_pct']; ?>"
            min="0" max="100" step="1" oninput="sUpdateTotal()" required>
        </div>
        <div class="form-group">
          <label style="color:#fbbf24;">Performance %</label>
          <input type="number" name="performance_pct" id="s_perf" class="form-control"
            value="<?php echo (int)$subject['performance_pct']; ?>"
            min="0" max="100" step="1" oninput="sUpdateTotal()" required>
        </div>
        <div class="form-group">
          <label style="color:#a78bfa;">Attendance % <span style="font-weight:400;font-size:10px;">(inside Perf)</span></label>
          <input type="number" name="attendance_pct" id="s_att" class="form-control"
            value="<?php echo (int)$subject['attendance_pct']; ?>"
            min="0" max="50" step="1" required>
        </div>
      </div>
      <div id="s_total" style="font-size:12px;color:var(--green);margin-bottom:14px;">Total: 100% ✓</div>
      <div class="weight-bar" style="margin-bottom:16px;">
        <div id="sb_exam"    class="weight-bar-seg" style="width:<?php echo $subject['exam_pct']; ?>%;background:#7aa3ff;"></div>
        <div id="sb_written" class="weight-bar-seg" style="width:<?php echo $subject['written_pct']; ?>%;background:#34d399;"></div>
        <div id="sb_perf"    class="weight-bar-seg" style="width:<?php echo $subject['performance_pct']; ?>%;background:#fbbf24;"></div>
      </div>
      <button type="submit" name="update_weights" class="btn btn-primary"
        onclick="return confirm('This will recompute all student grades. Continue?')">
        <i class="ti ti-check"></i> Save & Recompute All Grades
      </button>
    </form>
  </div>

  <!-- 3. Enrollee Management -->
  <div class="card" style="margin-bottom:20px; transform: translate(0px, -300px);">
    <p class="card-title"><i class="ti ti-users"></i>
      Enrollee Management
      <span style="font-weight:400;font-size:11px;color:var(--text3);margin-left:4px;">
        (<?php echo $enrolled_list ? $enrolled_list->num_rows : 0; ?> enrolled)
      </span>
    </p>

    <?php if ($sections_list): ?>
    <div class="enroll-block">
      <p style="font-size:12px;font-weight:600;color:var(--text2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em;">
        <i class="ti ti-building-community" style="color:var(--accent);"></i> Enroll Entire Section
      </p>
      <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="enroll_section">
        <select name="enroll_section_id" class="form-control" style="flex:1;min-width:180px;" required>
          <option value="">— Choose a section —</option>
          <?php foreach ($sections_list as $sec): ?>
          <option value="<?php echo $sec['id']; ?>"><?php echo htmlspecialchars($sec['section_name']); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">
          <i class="ti ti-users-plus"></i> Enroll Section
        </button>
        <a href="manage_sections.php" class="btn btn-outline" style="font-size:12px;">
          <i class="ti ti-settings"></i> Manage Sections
        </a>
      </form>
    </div>
    <div class="divider" style="margin:16px 0;"></div>
    <?php endif; ?>

    <?php if ($not_enrolled_list): ?>
    <div class="enroll-block" style="margin-bottom:16px;">
      <p style="font-size:12px;font-weight:600;color:var(--text2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em;">
        <i class="ti ti-user-plus" style="color:var(--accent);"></i> Add Individual Student
      </p>
      <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="enroll_single">
        <select name="single_student_id" class="form-control" style="flex:1;min-width:200px;" required>
          <option value="">— Select student —</option>
          <?php foreach ($not_enrolled_list as $ns): ?>
          <option value="<?php echo htmlspecialchars($ns['student_id']); ?>">
            <?php echo htmlspecialchars($ns['last_name'] . ', ' . $ns['first_name'] . ' (' . $ns['student_id'] . ')'); ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">
          <i class="ti ti-user-plus"></i> Enroll
        </button>
      </form>
    </div>
    <?php else: ?>
    <p style="font-size:12px;color:var(--text3);margin-bottom:14px;">All registered students are already enrolled.</p>
    <?php endif; ?>

    <?php if (!$enrolled_list || $enrolled_list->num_rows === 0): ?>
    <div class="empty-state" style="padding:24px;text-align:center;color:var(--text3);">
      <i class="ti ti-users-off" style="font-size:26px;display:block;margin-bottom:8px;"></i>
      No students enrolled yet. Use the options above to add students.
    </div>
    <?php else: ?>
    <div style="position:relative;margin-bottom:10px;">
      <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px;pointer-events:none;"></i>
      <input type="text" id="enrolleeSearch" placeholder="Search enrolled students…"
        oninput="filterEnrollees()"
        style="width:100%;padding:7px 10px 7px 32px;background:var(--bg3);border:1px solid var(--border2);border-radius:8px;color:var(--text);font-family:var(--font-body);font-size:12px;outline:none;">
    </div>

    <div id="enrolleeList" style="max-height:380px;overflow-y:auto;">
    <?php while ($en = $enrolled_list->fetch_assoc()):
        $initials = strtoupper(substr($en['last_name'],0,1) . substr($en['first_name'],0,1));
    ?>
    <div class="enrollee-row"
      data-name="<?php echo strtolower($en['last_name'] . ' ' . $en['first_name'] . ' ' . $en['student_id']); ?>"
      style="border:1px solid var(--border);background:var(--bg3);margin-bottom:6px;">
      <div class="enrollee-avatar" style="border-radius:8px;width:32px;height:32px;font-size:11px;"><?php echo $initials; ?></div>
      <div style="flex:1;">
        <div class="enrollee-name">
          <?php echo htmlspecialchars($en['last_name'] . ', ' . $en['first_name']); ?>
          <?php if ($en['middle_initial']): ?>
          <span style="color:var(--text3);"><?php echo htmlspecialchars($en['middle_initial']); ?></span>
          <?php endif; ?>
        </div>
        <div class="enrollee-id">
          <?php echo htmlspecialchars($en['student_id']); ?>
          <?php if ($en['section_name']): ?>
          <span style="margin-left:6px;padding:1px 6px;border-radius:99px;font-size:10px;background:rgba(91,141,238,.1);color:var(--accent);border:1px solid rgba(91,141,238,.2);">
            <?php echo htmlspecialchars($en['section_name']); ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <form method="POST" style="margin:0;"
        onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($en['first_name'])); ?> from this subject? Their scores will be kept.')">
        <input type="hidden" name="unenroll_sid" value="<?php echo htmlspecialchars($en['student_id']); ?>">
        <button type="submit" name="unenroll_student" class="btn btn-sm"
          style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);color:var(--red);padding:4px 10px;font-size:11px;border-radius:6px;">
          <i class="ti ti-user-minus"></i> Remove
        </button>
      </form>
    </div>
    <?php endwhile; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php endif; ?>

</div><!-- end page-body -->

<script>
// ── Navbar dropdown ──────────────────────────────────
function toggleDD() {
  document.getElementById('ddMenu').classList.toggle('open');
  document.getElementById('ddBtn').classList.toggle('open');
}
document.addEventListener('click', e => {
  const dd = document.querySelector('.nav-dropdown');
  if (dd && !dd.contains(e.target)) {
    document.getElementById('ddMenu')?.classList.remove('open');
    document.getElementById('ddBtn')?.classList.remove('open');
  }
});

// ── Score entry live % calc ──────────────────────────
function updateMaxScores() {
  const total = parseFloat(document.getElementById('total_all').value) || 0;
  document.querySelectorAll('.score-input').forEach(inp => {
    const sid = inp.name.match(/\[(.+)\]/)?.[1];
    if (!sid) return;
    inp.max = total > 0 ? total : '';
    document.getElementById('tot_' + sid).textContent = '/ ' + total;
    calcPct(inp, sid);
  });
}
function calcPct(inp, sid) {
  const total = parseFloat(document.getElementById('total_all').value) || 0;

  // Clamp the entered score to whole numbers between 0 and the total
  if (inp.value !== '') {
    let score = Math.round(parseFloat(inp.value));
    if (isNaN(score) || score < 0) score = 0;
    if (total > 0 && score > total) score = total;
    if (String(score) !== inp.value) inp.value = score;
  }

  const score = parseFloat(inp.value) || 0;
  const el = document.getElementById('pct_' + sid);
  if (!el) return;
  if (total > 0) {
    const pct = score / total * 100;
    el.textContent = pct.toFixed(1) + '%';
    el.style.color = pct >= 75 ? 'var(--green)' : 'var(--red)';
  }
}

// ── Score group accordion (collapsible container) ────
function toggleGroup(key) {
  const body = document.getElementById('gp_body_' + key);
  const chev = document.getElementById('gp_chev_' + key);
  if (!body) return;
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : 'block';
  if (chev) chev.style.transform = isOpen ? '' : 'rotate(180deg)';
}
function openGroup(key) {
  const body = document.getElementById('gp_body_' + key);
  const chev = document.getElementById('gp_chev_' + key);
  if (body) body.style.display = 'block';
  if (chev) chev.style.transform = 'rotate(180deg)';
}

// ── Bulk edit all scores in a group (tab moves student to student) ──
function toggleGroupEdit(key, on) {
  const body = document.getElementById('gp_body_' + key);
  if (!body) return;
  body.querySelectorAll('.score-view').forEach(el => el.style.display = on ? 'none' : '');
  body.querySelectorAll('.score-edit-input').forEach(el => el.style.display = on ? 'inline-block' : 'none');
  body.querySelectorAll('.row-actions-default').forEach(el => el.style.display = on ? 'none' : '');
  const defActions  = document.getElementById('gp_actions_default_' + key);
  const editActions = document.getElementById('gp_actions_edit_' + key);
  if (defActions)  defActions.style.display  = on ? 'none' : 'flex';
  if (editActions) editActions.style.display = on ? 'flex' : 'none';
}
function startGroupEdit(key) {
  openGroup(key);
  toggleGroupEdit(key, true);
  const body = document.getElementById('gp_body_' + key);
  const first = body?.querySelector('.score-edit-input');
  if (first) { first.focus(); first.select(); }
}
function focusRowEdit(key, scoreId) {
  openGroup(key);
  toggleGroupEdit(key, true);
  const inp = document.getElementById('bulk_input_' + scoreId);
  if (inp) { inp.focus(); inp.select(); }
}
function cancelGroupEdit(key) {
  const body = document.getElementById('gp_body_' + key);
  if (body) {
    body.querySelectorAll('.score-edit-input').forEach(el => {
      el.value = el.getAttribute('data-original');
    });
  }
  toggleGroupEdit(key, false);
}
function confirmDeleteQuiz(key, name) {
  if (confirm('Delete "' + name + '" for ALL students? This cannot be undone.')) {
    document.getElementById('delq_form_' + key).submit();
  }
}
function clampEditInput(inp) {
  if (inp.value === '') return;
  const max = parseFloat(inp.max);
  let val = Math.round(parseFloat(inp.value));
  if (isNaN(val) || val < 0) val = 0;
  if (!isNaN(max) && val > max) val = max;
  inp.value = val;
}

// ── Attendance mark all ──────────────────────────────
function markAllAtt(status) {
  document.querySelectorAll('[class^="att-radio-"]').forEach(radio => {
    if (radio.value === status) radio.checked = true;
  });
  document.querySelectorAll('[id^="timein_"]').forEach(ti => {
    const sid = ti.id.replace('timein_', '');
    const absent = document.querySelector(`.att-radio-${sid}[value="Absent"]:checked`);
    ti.disabled = !!absent;
    if (absent) ti.value = '';
  });
}
document.addEventListener('change', e => {
  if (!e.target.name?.startsWith('att_status')) return;
  const match = e.target.name.match(/\[(.+?)\]/);
  if (!match) return;
  const sid = match[1];
  const ti = document.getElementById('timein_' + sid);
  if (!ti) return;
  ti.disabled = e.target.value === 'Absent';
  if (ti.disabled) ti.value = '';
});

// ── Attendance history accordion (collapsible per-day container) ──
function toggleAttDay(key) {
  const body = document.getElementById('att_body_' + key);
  const chev = document.getElementById('att_chev_' + key);
  if (!body) return;
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : 'block';
  if (chev) chev.style.transform = isOpen ? '' : 'rotate(180deg)';
}
function openAttDay(key) {
  const body = document.getElementById('att_body_' + key);
  const chev = document.getElementById('att_chev_' + key);
  if (body) body.style.display = 'block';
  if (chev) chev.style.transform = 'rotate(180deg)';
}

// ── Bulk edit all attendance in a day (tab moves student to student) ──
function toggleAttDayEdit(key, on) {
  const body = document.getElementById('att_body_' + key);
  if (!body) return;
  body.querySelectorAll('.att-view').forEach(el => el.style.display = on ? 'none' : '');
  body.querySelectorAll('.att-edit-input').forEach(el => el.style.display = on ? 'inline-block' : 'none');
  body.querySelectorAll('.row-actions-default').forEach(el => el.style.display = on ? 'none' : '');
  const defActions  = document.getElementById('att_actions_default_' + key);
  const editActions = document.getElementById('att_actions_edit_' + key);
  if (defActions)  defActions.style.display  = on ? 'none' : 'flex';
  if (editActions) editActions.style.display = on ? 'flex' : 'none';
}
function startAttDayEdit(key) {
  openAttDay(key);
  toggleAttDayEdit(key, true);
  const body = document.getElementById('att_body_' + key);
  const first = body?.querySelector('.att-edit-input');
  if (first) first.focus();
}
function focusAttRowEdit(key, rowKey) {
  openAttDay(key);
  toggleAttDayEdit(key, true);
  const radios = document.getElementsByClassName('att-bulk-radio-' + rowKey);
  if (radios.length) radios[0].focus();
}
function cancelAttDayEdit(key) {
  toggleAttDayEdit(key, false);
}
function confirmDeleteAttDay(key, label) {
  if (confirm('Delete ALL attendance records for ' + label + '? This cannot be undone.')) {
    document.getElementById('deld_form_' + key).submit();
  }
}
// Disable/clear the time-in field for a row when its status is set to Absent
function attRowStatusChanged(rowKey) {
  const absentChecked = document.querySelector('.att-bulk-radio-' + rowKey + '[value="Absent"]:checked');
  const ti = document.getElementById('att_time_' + rowKey);
  if (!ti) return;
  ti.disabled = !!absentChecked;
  if (absentChecked) ti.value = '';
}

// ── Settings weight total ────────────────────────────
function sUpdateTotal() {
  const e = parseFloat(document.getElementById('s_exam')?.value) || 0;
  const w = parseFloat(document.getElementById('s_written')?.value) || 0;
  const p = parseFloat(document.getElementById('s_perf')?.value) || 0;
  const t = e + w + p;
  const el = document.getElementById('s_total');
  if (el) {
    el.textContent = `Total: ${t}%${t === 100 ? ' ✓' : ' ✗ (must equal 100%)'}`;
    el.style.color = t === 100 ? 'var(--green)' : 'var(--red)';
  }
  const sb_exam    = document.getElementById('sb_exam');
  const sb_written = document.getElementById('sb_written');
  const sb_perf    = document.getElementById('sb_perf');
  if (sb_exam)    sb_exam.style.width    = e + '%';
  if (sb_written) sb_written.style.width = w + '%';
  if (sb_perf)    sb_perf.style.width    = p + '%';
}

// ── Enrollee search ──────────────────────────────────
function filterEnrollees() {
  const q = document.getElementById('enrolleeSearch').value.toLowerCase();
  document.querySelectorAll('#enrolleeList .enrollee-row').forEach(row => {
    row.style.display = row.dataset.name.includes(q) ? '' : 'none';
  });
}

// ── Grade distribution chart ─────────────────────────
<?php if ($active_tab === 'overview' && !empty($chart_labels)): ?>
Chart.defaults.color = '#7d8aaa';
Chart.defaults.borderColor = '#252a3d';
Chart.defaults.font.family = 'DM Sans';
new Chart(document.getElementById('gradeChart'), {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($chart_labels); ?>,
    datasets: [{
      data:            <?php echo json_encode($chart_values); ?>,
      backgroundColor: <?php echo json_encode($chart_colors); ?>,
      borderRadius: 5,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, max: 100, grid: { color: '#1a1e2b' } },
      x: { grid: { display: false }, ticks: { font: { size: 11 } } }
    }
  }
});
<?php endif; ?>
</script>    

</body>
</html>
