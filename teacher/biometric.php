<?php
// ============================================================
//  teacher/biometric.php  —  Host-Driven Biometric Dashboard
//  Rebuilt for the new architecture:
//    - Register / manage ESP32 devices
//    - Assign devices to subjects
//    - Trigger fingerprint enrollment for students
//    - View live scan log
// ============================================================
session_start();   // Required for bulk_enroll_queue session handoff to bio_enroll_done.php
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$teacher_id  = $_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// ══════════════════════════════════════════════════════════════
//  POST HANDLERS
// ══════════════════════════════════════════════════════════════

// ── Register a new device ─────────────────────────────────────
if (isset($_POST['register_device'])) {
    $dk    = trim($_POST['device_key']);
    $label = trim($_POST['device_label']);
    $subj  = (int)$_POST['device_subject'];
    if ($dk === '') {
        $error_msg = "Device key is required.";
    } elseif ($label === '') {
        $error_msg = "Device label is required.";
    } else {
        // Verify teacher owns this subject
        if ($subj > 0) {
            $ov = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=? LIMIT 1");
            $ov->bind_param('ii', $subj, $teacher_id);
            $ov->execute(); $ov->store_result();
            if ($ov->num_rows === 0) { $subj = 0; }
        }
        $ins = $conn->prepare(
            "INSERT INTO bio_devices (device_key, label, subject_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE label=VALUES(label), subject_id=VALUES(subject_id)"
        );
        $ins->bind_param('ssi', $dk, $label, $subj);
        $ins->execute();
        $success_msg = "Device <strong>" . htmlspecialchars($label) . "</strong> registered.";
    }
}

// ── Update device subject assignment ─────────────────────────
if (isset($_POST['update_device'])) {
    $dev_id = (int)$_POST['dev_id'];
    $subj   = (int)$_POST['device_subject'];
    $label  = trim($_POST['device_label']);
    if ($subj > 0) {
        $ov = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=? LIMIT 1");
        $ov->bind_param('ii', $subj, $teacher_id);
        $ov->execute(); $ov->store_result();
        if ($ov->num_rows === 0) {
            $error_msg = "You don't own that subject.";
            goto after_handlers;
        }
    }
    $upd = $conn->prepare("UPDATE bio_devices SET subject_id=?, label=? WHERE id=?");
    $upd->bind_param('isi', $subj, $label, $dev_id);
    $upd->execute();
    $success_msg = "Device updated.";
}

// ── Delete device ─────────────────────────────────────────────
if (isset($_POST['delete_device'])) {
    $dev_id = (int)$_POST['dev_id'];
    $del = $conn->prepare("DELETE FROM bio_devices WHERE id=?");
    $del->bind_param('i', $dev_id);
    $del->execute();
    $success_msg = "Device removed.";
}

// ── Queue enrollment for a student ───────────────────────────
// Sets a pending_enroll row that bio_enroll_poll.php serves to
// the ESP32 when it polls. The device then triggers enrollmentMode().
if (isset($_POST['queue_enroll'])) {
    $student_id = trim($_POST['student_id']);
    $dev_id     = (int)$_POST['dev_id'];
    if ($student_id === '' || $dev_id === 0) {
        $error_msg = "Select both a device and a student.";
    } else {
        // Clear any existing done/failed rows for this device so the
        // UNIQUE(device_id) constraint doesn't block the new insert.
        $clr = $conn->prepare(
            "DELETE FROM bio_enroll_queue WHERE device_id=? AND status IN ('done','failed')"
        );
        $clr->bind_param('i', $dev_id);
        $clr->execute();

        // Upsert: if a 'pending' or 'enrolling' row already exists for
        // this device, overwrite it with the new student (teacher changed mind).
        $ins = $conn->prepare(
            "INSERT INTO bio_enroll_queue (device_id, student_id, queued_at, status)
             VALUES (?, ?, NOW(), 'pending')
             ON DUPLICATE KEY UPDATE
               student_id = VALUES(student_id),
               queued_at  = NOW(),
               status     = 'pending'"
        );
        $ins->bind_param('is', $dev_id, $student_id);
        $ins->execute();
        $success_msg = "Enrollment queued. Ask the student to place their finger on the device.";
    }
}

// ── Queue section bulk enrollment ─────────────────────────────
// Queues the FIRST student only; bio_enroll_done.php chains the rest
if (isset($_POST['queue_section_enroll'])) {
    $dev_id     = (int)$_POST['dev_id'];
    $section_id = (int)$_POST['section_id'];
    if ($dev_id === 0 || $section_id === 0) {
        $error_msg = "Select both a device and a section.";
    } else {
        // Get all students in section that still need a template, ordered by last_name
        $sq = $conn->prepare(
            "SELECT ss.student_id, s.last_name, s.first_name
             FROM section_students ss
             JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
             LEFT JOIN fingerprint_templates ft ON ft.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
             WHERE ss.section_id = ?
             ORDER BY s.last_name ASC, s.first_name ASC"
        );
        $sq->bind_param('i', $section_id); $sq->execute();
        $sec_students = $sq->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($sec_students)) {
            $error_msg = "No students found in that section.";
        } else {
            // Store the full queue in session so bio_enroll_done can chain them
            $_SESSION['bulk_enroll_queue'] = [
                'device_id' => $dev_id,
                'students'  => array_column($sec_students, 'student_id'),
                'index'     => 1,   // 0 will be queued now; start chaining from 1
            ];
            // Clear stale rows for this device before queuing first student
            $clr = $conn->prepare(
                "DELETE FROM bio_enroll_queue WHERE device_id=? AND status IN ('done','failed')"
            );
            $clr->bind_param('i', $dev_id);
            $clr->execute();
            // Queue first student
            $first = $sec_students[0]['student_id'];
            $ins = $conn->prepare(
                "INSERT INTO bio_enroll_queue (device_id, student_id, queued_at, status)
                 VALUES (?, ?, NOW(), 'pending')
                 ON DUPLICATE KEY UPDATE student_id=VALUES(student_id), queued_at=NOW(), status='pending'"
            );
            $ins->bind_param('is', $dev_id, $first);
            $ins->execute();
            $total = count($sec_students);
            $success_msg = "Section enrollment started. {$total} student(s) queued. "
                         . "First up: <strong>" . htmlspecialchars($sec_students[0]['last_name'].', '.$sec_students[0]['first_name']) . "</strong>. "
                         . "After each scan, the next student is automatically queued.";
        }
    }
}

// ── AJAX: return students in a section ────────────────────────
if (isset($_GET['ajax_section_students'])) {
    header('Content-Type: application/json');
    $sec_id = (int)$_GET['ajax_section_students'];
    $sq = $conn->prepare(
        "SELECT ss.student_id, s.last_name, s.first_name,
                (ft.id IS NOT NULL) AS has_template
         FROM section_students ss
         JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
         LEFT JOIN fingerprint_templates ft ON ft.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
         WHERE ss.section_id = ?
         ORDER BY s.last_name ASC"
    );
    $sq->bind_param('i', $sec_id); $sq->execute();
    $rows = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($rows);
    exit;
}

// ── Start a session ───────────────────────────────────────────
if (isset($_POST['start_session'])) {
    $dev_id         = (int)$_POST['dev_id'];
    $subj_id        = (int)$_POST['session_subject'];
    $late_threshold = trim($_POST['late_threshold'] ?? '08:15:00');
    $duration_min   = (int)($_POST['duration_min'] ?? 90);
    if ($dev_id === 0 || $subj_id === 0) {
        $error_msg = "Select both a device and a subject.";
    } else {
        $ov = $conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=? LIMIT 1");
        $ov->bind_param('ii', $subj_id, $teacher_id);
        $ov->execute(); $ov->store_result();
        if ($ov->num_rows === 0) {
            $error_msg = "You don't own that subject.";
        } else {
            // End any currently active session for this device first
            $end = $conn->prepare("UPDATE bio_sessions SET status='ended', ended_at=NOW() WHERE device_id=? AND status='active'");
            $end->bind_param('i', $dev_id); $end->execute();
            // Insert new session
            $ins = $conn->prepare(
                "INSERT INTO bio_sessions (device_id, subject_id, started_by, status, late_threshold, auto_expire_at, started_at)
                 VALUES (?, ?, ?, 'active', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())"
            );
            $ins->bind_param('iiisi', $dev_id, $subj_id, $teacher_id, $late_threshold, $duration_min);
            $ins->execute();
            $success_msg = "Session started. Device will pick it up within seconds.";
        }
    }
}

// ── Stop a session ─────────────────────────────────────────────
if (isset($_POST['stop_session'])) {
    $dev_id = (int)$_POST['dev_id'];
    $end = $conn->prepare("UPDATE bio_sessions SET status='ended', ended_at=NOW() WHERE device_id=? AND status='active'");
    $end->bind_param('i', $dev_id); $end->execute();
    $success_msg = "Session ended.";
}

// ── Clear enrolled template ───────────────────────────────────
if (isset($_POST['clear_template'])) {
    $student_id = trim($_POST['student_id']);
    $del = $conn->prepare("DELETE FROM fingerprint_templates WHERE student_id=?");
    $del->bind_param('s', $student_id);
    $del->execute();
    $success_msg = "Fingerprint template cleared for <strong>" . htmlspecialchars($student_id) . "</strong>.";
}

after_handlers:

// ══════════════════════════════════════════════════════════════
//  DATA
// ══════════════════════════════════════════════════════════════

// Teacher's subjects
$subj_res = $conn->prepare(
    "SELECT id, subject_code, subject_name, section
     FROM subjects WHERE teacher_id=? AND is_active=1 ORDER BY subject_name ASC"
);
$subj_res->bind_param('i', $teacher_id);
$subj_res->execute();
$my_subjects = $subj_res->get_result()->fetch_all(MYSQLI_ASSOC);
$my_subj_ids = array_column($my_subjects, 'id');

// Registered devices (only those bound to this teacher's subjects)
$devices = [];
if ($my_subj_ids) {
    $ph  = implode(',', array_fill(0, count($my_subj_ids), '?'));
    $dq  = $conn->prepare(
        "SELECT d.*, s.subject_code, s.subject_name, s.section
         FROM bio_devices d
         LEFT JOIN subjects s ON s.id = d.subject_id
         WHERE d.subject_id IN ($ph) OR d.subject_id IS NULL
         ORDER BY d.label ASC"
    );
    $types = str_repeat('i', count($my_subj_ids));
    $dq->bind_param($types, ...$my_subj_ids);
    $dq->execute();
    $devices = $dq->get_result()->fetch_all(MYSQLI_ASSOC);
}
// Also devices with no subject assigned
$dq2 = $conn->query("SELECT d.* FROM bio_devices d WHERE d.subject_id IS NULL OR d.subject_id=0");
while ($r = $dq2->fetch_assoc()) {
    $already = false;
    foreach ($devices as $d) { if ($d['id'] === $r['id']) { $already = true; break; } }
    if (!$already) $devices[] = $r;
}

// Students enrolled in any of this teacher's subjects + template status
$students = [];
if ($my_subj_ids) {
    $ph  = implode(',', array_fill(0, count($my_subj_ids), '?'));
    $sq  = $conn->prepare(
        "SELECT DISTINCT s.student_id, s.last_name, s.first_name,
                ft.id AS has_template,
                ft.updated_at AS enrolled_at,
                sub.subject_code, sub.section
         FROM subject_enrollments se
         JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = se.student_id COLLATE utf8mb4_unicode_ci
         JOIN subjects sub ON sub.id = se.subject_id
         LEFT JOIN fingerprint_templates ft ON ft.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
         WHERE se.subject_id IN ($ph)
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $types = str_repeat('i', count($my_subj_ids));
    $sq->bind_param($types, ...$my_subj_ids);
    $sq->execute();
    $students = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
    // Deduplicate by student_id (keep row with template if available)
    $seen = [];
    $deduped = [];
    foreach ($students as $st) {
        if (!isset($seen[$st['student_id']]) || $st['has_template']) {
            $seen[$st['student_id']] = true;
            $deduped[$st['student_id']] = $st;
        }
    }
    $students = array_values($deduped);
}

$enrolled_count  = count($students);
$template_count  = count(array_filter($students, fn($s) => $s['has_template']));

// Pending enrollment queue
$queue_res = $conn->query(
    "SELECT eq.*, s.first_name, s.last_name, d.label AS device_label
     FROM bio_enroll_queue eq
     JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = eq.student_id COLLATE utf8mb4_unicode_ci
     JOIN bio_devices d ON d.id = eq.device_id
     WHERE eq.status IN ('pending','enrolling')
     ORDER BY eq.queued_at DESC"
);
$queue_rows = $queue_res ? $queue_res->fetch_all(MYSQLI_ASSOC) : [];

// Active sessions per device
$active_sessions = [];
if (!empty($devices)) {
    foreach ($devices as $dev) {
        $sq = $conn->prepare(
            "SELECT bs.id, bs.subject_id, bs.late_threshold, bs.auto_expire_at, bs.started_at,
                    s.subject_code, s.subject_name, s.section
             FROM bio_sessions bs
             JOIN subjects s ON s.id = bs.subject_id
             WHERE bs.device_id=? AND bs.status='active'
             ORDER BY bs.started_at DESC LIMIT 1"
        );
        $sq->bind_param('i', $dev['id']); $sq->execute();
        $row = $sq->get_result()->fetch_assoc();
        $active_sessions[$dev['id']] = $row ?: null;
    }
}

// Recent scan log (last 60)
// Guard: device_id column may not exist if running old biometric_log schema.
// The ALTER in biometric_patch.sql adds it. Until then, skip the device JOIN.
$_has_device_col = false;
$_col_check = $conn->query("SHOW COLUMNS FROM biometric_log LIKE 'device_id'");
if ($_col_check && $_col_check->num_rows > 0) $_has_device_col = true;

if ($_has_device_col) {
    $log_res = $conn->query(
        "SELECT bl.*, s.last_name, s.first_name,
                COALESCE(d.label, CONCAT('Slot #', bl.fp_id)) AS device_label,
                sub.subject_code
         FROM biometric_log bl
         LEFT JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = bl.student_id COLLATE utf8mb4_unicode_ci
         LEFT JOIN bio_devices d ON d.id = bl.device_id
         LEFT JOIN subjects sub ON sub.id = bl.subject_id
         ORDER BY bl.scanned_at DESC LIMIT 60"
    );
} else {
    $log_res = $conn->query(
        "SELECT bl.*, s.last_name, s.first_name,
                CONCAT('Slot #', bl.fp_id) AS device_label,
                sub.subject_code
         FROM biometric_log bl
         LEFT JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = bl.student_id COLLATE utf8mb4_unicode_ci
         LEFT JOIN subjects sub ON sub.id = bl.subject_id
         ORDER BY bl.scanned_at DESC LIMIT 60"
    );
}
$log_rows = $log_res ? $log_res->fetch_all(MYSQLI_ASSOC) : [];

// Nav subjects
$all_subs  = getTeacherSubjects($conn, $teacher_id);
$type_cfg  = [
    'General Education'      => ['color' => '#7aa3ff'],
    'Professional Education' => ['color' => '#34d399'],
    'Major Subject'          => ['color' => '#fbbf24'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Biometric Setup — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">

</head>
<body class="page-teacher-biometric">

<!-- NAVBAR -->
<nav class="navbar">
  <a class="brand" href="/classroom/teacher/dashboard.php">
    <span class="brand-dot"></span>Classroom CMS
  </a>
  <div class="nav-sep"></div>
  <a href="/classroom/teacher/dashboard.php" class="nav-link"><i class="ti ti-layout-dashboard"></i> Dashboard</a>

  <?php if ($all_subs->num_rows > 0): ?>
  <div class="nav-dropdown">
    <button class="nav-dd-btn" id="ddBtn" onclick="toggleDD()">
      <i class="ti ti-books"></i> My Subjects <i class="ti ti-chevron-down" style="font-size:11px;"></i>
    </button>
    <div class="nav-dd-menu" id="ddMenu">
      <?php $all_subs->data_seek(0); while ($ns = $all_subs->fetch_assoc()):
        $dc = $type_cfg[$ns['subject_type']]['color'] ?? '#00ff1a'; ?>
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

  <a href="/classroom/teacher/add_subject.php" class="nav-link"><i class="ti ti-book-plus"></i> Add Subject</a>
  <a href="/classroom/teacher/manage_sections.php" class="nav-link"><i class="ti ti-building-community"></i> Sections</a>
  <a href="/classroom/teacher/biometric.php" class="nav-link active"><i class="ti ti-fingerprint"></i> Biometric</a>
  <a href="/classroom/teacher/students.php" class="nav-link"><i class="ti ti-users"></i> Students</a>
  <div class="nav-right">
    <span class="nav-role">Teacher</span>
    <span style="font-size:13px;color:var(--text2);"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="/classroom/logout.php" class="btn-logout"><i class="ti ti-logout"></i> Logout</a>
  </div>
</nav>

<div class="page-wrap">

  <!-- PAGE HEADER -->
  <div class="page-header">
    <div>
      <h1><i class="ti ti-fingerprint" style="color:var(--accent);"></i> Biometric Attendance</h1>
      <p>Register devices, enroll student fingerprints, and monitor live attendance.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('registerModal')">
      <i class="ti ti-plus"></i> Register Device
    </button>
  </div>

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- STAT CHIPS -->
  <div class="stat-chips">
    <div class="stat-chip a">
      <div class="stat-chip-val"><?php echo count($devices); ?></div>
      <div class="stat-chip-lbl">Devices</div>
    </div>
    <div class="stat-chip g">
      <div class="stat-chip-val"><?php echo count(array_filter($active_sessions)); ?></div>
      <div class="stat-chip-lbl">Active Sessions</div>
    </div>
    <div class="stat-chip g">
      <div class="stat-chip-val"><?php echo $template_count; ?></div>
      <div class="stat-chip-lbl">Enrolled Fingers</div>
    </div>
    <div class="stat-chip y">
      <div class="stat-chip-val"><?php echo $enrolled_count - $template_count; ?></div>
      <div class="stat-chip-lbl">Pending Enrollment</div>
    </div>
    <div class="stat-chip p">
      <div class="stat-chip-val"><?php echo count($queue_rows); ?></div>
      <div class="stat-chip-lbl">In Queue</div>
    </div>
  </div>

  <!-- ── STEP-BY-STEP GUIDE ── -->
  <div class="card" style="margin-bottom:24px;background:linear-gradient(135deg,rgba(91,141,238,.05) 0%,transparent 100%);border-color:rgba(91,141,238,.2);">
    <p class="card-title"><i class="ti ti-route"></i> Setup Guide</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
      <?php
      $steps = [
        ['1','Register Device','Click "Register Device" above. Enter the DEVICE_KEY you flashed into the Arduino sketch and assign it to a subject.','ti-cpu'],
        ['2','Verify Connection','The device must connect to your WiFi and call bio_config.php on boot. The LCD shows the subject code if config loaded OK.','ti-wifi'],
        ['3','Enroll Students','Use the "Enroll Fingerprint" panel below. Select a device and student → click Queue. The student then places their finger on the device.','ti-fingerprint'],
        ['4','Attendance is Automatic','Students place their finger when entering class. The device matches locally and calls bio_record.php. Grades update instantly.','ti-checks'],
      ];
      foreach ($steps as [$n,$title,$desc,$icon]): ?>
      <div style="display:flex;gap:12px;align-items:flex-start;">
        <div style="width:28px;height:28px;border-radius:8px;background:var(--accent-dim);border:1px solid var(--accent-glow);display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-size:12px;font-weight:700;color:var(--accent);flex-shrink:0;"><?php echo $n; ?></div>
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:3px;display:flex;align-items:center;gap:5px;">
            <i class="ti <?php echo $icon; ?>" style="font-size:13px;color:var(--accent);"></i>
            <?php echo $title; ?>
          </div>
          <div style="font-size:12px;color:var(--text2);"><?php echo $desc; ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="two-col">

    <!-- LEFT COLUMN -->
    <div>

      <!-- ── REGISTERED DEVICES ── -->
      <div class="card">
        <p class="card-title"><i class="ti ti-cpu"></i> Registered Devices</p>
        <?php if (empty($devices)): ?>
        <div class="empty-state" style="padding:24px;">
          <i class="ti ti-cpu-off"></i>
          <p>No devices registered yet.<br>Click "Register Device" to add one.</p>
        </div>
        <?php else: ?>
        <?php foreach ($devices as $dev):
          $last_seen = $dev['last_seen'] ?? null;
          $is_online = $last_seen && (time() - strtotime($last_seen) < 120);
          $last_seen_str = $last_seen
              ? (time() - strtotime($last_seen) < 60 ? 'Just now' : human_time_diff(strtotime($last_seen)) . ' ago')
              : 'Never';
        ?>
        <div class="device-card <?php echo $is_online ? 'online' : 'offline'; ?>">
          <div class="device-header">
            <div class="device-icon"><i class="ti ti-cpu"></i></div>
            <div style="flex:1;">
              <div class="device-name"><?php echo htmlspecialchars($dev['label']); ?></div>
              <div class="device-key"><?php echo htmlspecialchars($dev['device_key']); ?></div>
            </div>
            <div style="display:flex;gap:5px;">
              <button class="btn btn-xs btn-outline"
                onclick="openEditDevice(<?php echo $dev['id']; ?>,'<?php echo addslashes($dev['label']); ?>',<?php echo (int)($dev['subject_id'] ?? 0); ?>)">
                <i class="ti ti-edit"></i>
              </button>
              <form method="POST" style="margin:0;"
                onsubmit="return confirm('Delete device <?php echo addslashes($dev['label']); ?>?')">
                <input type="hidden" name="dev_id" value="<?php echo $dev['id']; ?>">
                <button type="submit" name="delete_device" class="btn btn-xs btn-danger">
                  <i class="ti ti-trash"></i>
                </button>
              </form>
            </div>
          </div>
          <div class="device-meta">
            <span class="device-pill">
              <span class="last-seen-dot" style="background:<?php echo $is_online ? 'var(--green)' : 'var(--text3)'; ?>;"></span>
              <?php echo $is_online ? 'Online' : $last_seen_str; ?>
            </span>
            <?php if ($dev['subject_code'] ?? ''): ?>
            <span class="device-pill">
              <i class="ti ti-books" style="font-size:11px;"></i>
              <?php echo htmlspecialchars($dev['subject_code'].' '.$dev['section']); ?>
            </span>
            <?php else: ?>
            <span class="device-pill" style="color:var(--red);">
              <i class="ti ti-alert-triangle" style="font-size:11px;"></i>
              No subject assigned
            </span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- ── ENROLL FINGERPRINT ── -->
      <div class="card">
        <p class="card-title"><i class="ti ti-fingerprint" style="color:var(--green);"></i> Enroll Fingerprint</p>

        <?php if (empty($devices)): ?>
        <div class="alert alert-info" style="margin-bottom:0;">
          <i class="ti ti-info-circle"></i> Register a device first before enrolling fingerprints.
        </div>
        <?php elseif (empty($students)): ?>
        <div class="alert alert-info" style="margin-bottom:0;">
          <i class="ti ti-info-circle"></i> No students enrolled in your subjects yet.
        </div>
        <?php else: ?>

        <!-- Enroll mode tabs -->
        <div style="display:flex;gap:6px;margin-bottom:14px;">
          <button type="button" class="btn btn-sm btn-primary" id="tabSingle" onclick="switchEnrollTab('single')">
            <i class="ti ti-user"></i> Single Student
          </button>
          <button type="button" class="btn btn-sm btn-outline" id="tabSection" onclick="switchEnrollTab('section')">
            <i class="ti ti-users-group"></i> Whole Section
          </button>
        </div>

        <!-- ── Single student enroll ── -->
        <div id="enrollSinglePanel">
          <form method="POST">
            <div class="form-group">
              <label>Device <span style="color:var(--red);">*</span></label>
              <select name="dev_id" class="form-control" required>
                <option value="">— Choose device —</option>
                <?php foreach ($devices as $dev): ?>
                <option value="<?php echo $dev['id']; ?>">
                  <?php echo htmlspecialchars($dev['label']); ?>
                  <?php if ($dev['subject_code'] ?? ''): ?>(<?php echo htmlspecialchars($dev['subject_code']); ?>)<?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Student <span style="color:var(--red);">*</span></label>
              <input type="text" id="enrollSearch" class="form-control"
                placeholder="Type to filter…" oninput="filterEnrollList()" style="margin-bottom:6px;">
              <select name="student_id" id="enrollSelect" class="form-control" size="5"
                style="height:auto;padding:4px;" required>
                <?php foreach ($students as $st): ?>
                <option value="<?php echo htmlspecialchars($st['student_id']); ?>"
                  data-label="<?php echo strtolower($st['last_name'].' '.$st['first_name'].' '.$st['student_id']); ?>">
                  <?php echo htmlspecialchars($st['last_name'].', '.$st['first_name'].' ('.$st['student_id'].')'); ?>
                  <?php echo $st['has_template'] ? ' ✓' : ''; ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small>✓ = already has a template (will be overwritten)</small>
            </div>
            <button type="submit" name="queue_enroll" class="btn btn-primary btn-full">
              <i class="ti ti-fingerprint"></i> Queue Enrollment
            </button>
          </form>
        </div>

        <!-- ── Section bulk enroll ── -->
        <div id="enrollSectionPanel" style="display:none;">
          <?php
          // Load sections that have students in this teacher's subjects
          $sec_res = $conn->query(
            "SELECT DISTINCT sec.id, sec.section_name, sec.course, sec.year_level,
                    COUNT(ss.student_id) AS student_count
             FROM sections sec
             JOIN section_students ss ON ss.section_id = sec.id
             JOIN subject_enrollments se ON se.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
             JOIN subjects sub ON sub.id = se.subject_id AND sub.teacher_id = {$teacher_id}
             GROUP BY sec.id
             ORDER BY sec.section_name ASC"
          );
          $sections_list = $sec_res ? $sec_res->fetch_all(MYSQLI_ASSOC) : [];
          ?>
          <?php if (empty($sections_list)): ?>
          <div class="alert alert-info" style="margin-bottom:0;">
            <i class="ti ti-info-circle"></i> No sections found. Assign students to sections in the Sections panel.
          </div>
          <?php else: ?>
          <form method="POST">
            <div class="form-group">
              <label>Device <span style="color:var(--red);">*</span></label>
              <select name="dev_id" class="form-control" required>
                <option value="">— Choose device —</option>
                <?php foreach ($devices as $dev): ?>
                <option value="<?php echo $dev['id']; ?>">
                  <?php echo htmlspecialchars($dev['label']); ?>
                  <?php if ($dev['subject_code'] ?? ''): ?>(<?php echo htmlspecialchars($dev['subject_code']); ?>)<?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Section <span style="color:var(--red);">*</span></label>
              <select name="section_id" id="sectionSelect" class="form-control" required onchange="loadSectionStudents(this.value)">
                <option value="">— Choose section —</option>
                <?php foreach ($sections_list as $sec): ?>
                <option value="<?php echo $sec['id']; ?>">
                  <?php echo htmlspecialchars($sec['section_name']); ?>
                  <?php if ($sec['course']): ?>(<?php echo htmlspecialchars($sec['course']); ?>)<?php endif; ?>
                  — <?php echo $sec['student_count']; ?> students
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Section student preview -->
            <div id="sectionPreview" style="display:none;margin-bottom:10px;">
              <p class="section-label">Students in this section</p>
              <div id="sectionStudentList" style="background:var(--bg3);border-radius:var(--radius);padding:8px;max-height:160px;overflow-y:auto;font-size:12px;"></div>
              <small id="sectionEnrollNote" style="margin-top:4px;display:block;"></small>
            </div>
            <div class="alert alert-info" style="padding:8px 12px;font-size:12px;margin-bottom:10px;">
              <i class="ti ti-info-circle"></i>
              Students are queued one at a time. After each enrollment, the next student is automatically queued. The device stays in enrollment mode until all are done.
            </div>
            <button type="submit" name="queue_section_enroll" class="btn btn-primary btn-full">
              <i class="ti ti-users-group"></i> Queue Section Enrollment
            </button>
          </form>
          <?php endif; ?>
        </div>

        <?php if (!empty($queue_rows)): ?>
        <div class="divider"></div>
        <p class="section-label"><i class="ti ti-clock"></i> Pending Queue</p>
        <?php foreach ($queue_rows as $qr): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--bg3);border-radius:var(--radius);margin-bottom:6px;">
          <i class="ti ti-loader" style="color:var(--yellow);font-size:16px;animation:spin 1.5s linear infinite;"></i>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:500;">
              <?php echo htmlspecialchars($qr['last_name'].', '.$qr['first_name']); ?>
            </div>
            <div style="font-size:11px;color:var(--text3);">
              Device: <?php echo htmlspecialchars($qr['device_label']); ?>
              &nbsp;·&nbsp; Queued: <?php echo date('H:i:s', strtotime($qr['queued_at'])); ?>
            </div>
          </div>
          <span class="badge badge-yellow"><?php echo strtoupper($qr['status']); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php endif; ?>
      </div>

      <!-- ── SESSION CONTROL ── -->
      <div class="card">
        <p class="card-title"><i class="ti ti-player-play" style="color:var(--green);"></i> Session Control</p>
        <?php if (empty($devices)): ?>
        <div class="alert alert-info" style="margin-bottom:0;">
          <i class="ti ti-info-circle"></i> Register a device first.
        </div>
        <?php else: ?>
        <?php foreach ($devices as $dev):
          $sess = $active_sessions[$dev['id']] ?? null;
          $is_online = $dev['last_seen'] && (time() - strtotime($dev['last_seen']) < 120);
        ?>
        <div style="background:var(--bg3);border:1px solid <?php echo $sess ? 'rgba(52,211,153,.25)' : 'var(--border2)'; ?>;border-radius:var(--radius);padding:14px;margin-bottom:12px;">
          <!-- Device header -->
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $is_online ? 'var(--green)' : 'var(--text3)'; ?>;flex-shrink:0;<?php echo $is_online ? 'animation:pulse 1.5s infinite;' : ''; ?>"></div>
            <div style="font-weight:600;font-size:13px;flex:1;"><?php echo htmlspecialchars($dev['label']); ?></div>
            <?php if ($sess): ?>
            <span class="badge badge-green">ACTIVE</span>
            <?php else: ?>
            <span class="badge badge-gray">IDLE</span>
            <?php endif; ?>
          </div>

          <?php if ($sess): ?>
          <!-- Active session info -->
          <div style="background:var(--bg4);border-radius:6px;padding:10px;margin-bottom:10px;font-size:12px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span style="color:var(--text2);">Subject</span>
              <span style="font-weight:600;"><?php echo htmlspecialchars($sess['subject_code'].' — '.$sess['section']); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span style="color:var(--text2);">Late after</span>
              <span style="font-family:var(--font-mono);"><?php echo $sess['late_threshold']; ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span style="color:var(--text2);">Started</span>
              <span style="font-family:var(--font-mono);"><?php echo date('H:i', strtotime($sess['started_at'])); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;">
              <span style="color:var(--text2);">Expires</span>
              <span style="font-family:var(--font-mono);"><?php echo $sess['auto_expire_at'] ? date('H:i', strtotime($sess['auto_expire_at'])) : 'Manual'; ?></span>
            </div>
          </div>
          <form method="POST">
            <input type="hidden" name="dev_id" value="<?php echo $dev['id']; ?>">
            <button type="submit" name="stop_session" class="btn btn-danger btn-sm btn-full"
              onclick="return confirm('Stop the active session for <?php echo addslashes($dev['label']); ?>?')">
              <i class="ti ti-player-stop"></i> Stop Session
            </button>
          </form>

          <?php else: ?>
          <!-- Start session form -->
          <form method="POST">
            <input type="hidden" name="dev_id" value="<?php echo $dev['id']; ?>">
            <div class="form-group" style="margin-bottom:8px;">
              <label>Subject</label>
              <select name="session_subject" class="form-control" required>
                <option value="">— Choose —</option>
                <?php foreach ($my_subjects as $s): ?>
                <option value="<?php echo $s['id']; ?>"
                  <?php echo ($dev['subject_id'] ?? 0) == $s['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($s['subject_code'].' — '.$s['section']); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-row" style="margin-bottom:8px;">
              <div class="form-group" style="margin-bottom:0;">
                <label>Late After</label>
                <input type="time" name="late_threshold" class="form-control" value="08:15" step="60" required>
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label>Duration (min)</label>
                <input type="number" name="duration_min" class="form-control" value="90" min="10" max="480" required>
              </div>
            </div>
            <button type="submit" name="start_session" class="btn btn-green btn-sm btn-full">
              <i class="ti ti-player-play"></i> Start Session
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div><!-- end left column -->

    <!-- RIGHT COLUMN -->
    <div>

      <!-- ── FINGERPRINT ROSTER ── -->
      <div class="card">
        <p class="card-title">
          <i class="ti ti-users"></i> Student Fingerprint Roster
          <span class="card-title-right" style="font-size:11px;font-weight:400;color:var(--text3);">
            <?php echo $template_count; ?> / <?php echo $enrolled_count; ?> enrolled
          </span>
        </p>

        <?php if (empty($students)): ?>
        <div class="empty-state">
          <i class="ti ti-users-off"></i>
          <p>No students in your subjects yet.</p>
        </div>
        <?php else: ?>
        <div class="search-wrap">
          <i class="ti ti-search"></i>
          <input type="text" id="rosterSearch" class="form-control"
            placeholder="Search students…" oninput="filterRoster()">
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th>
                <th>Subject</th>
                <th>Fingerprint</th>
                <th>Enrolled</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="rosterTable">
              <?php foreach ($students as $st): ?>
              <tr data-name="<?php echo strtolower($st['last_name'].' '.$st['first_name'].' '.$st['student_id']); ?>">
                <td>
                  <div style="font-weight:500;"><?php echo htmlspecialchars($st['last_name'].', '.$st['first_name']); ?></div>
                  <div class="td-mono"><?php echo htmlspecialchars($st['student_id']); ?></div>
                </td>
                <td style="font-size:12px;color:var(--text2);">
                  <?php echo htmlspecialchars($st['subject_code'].' '.$st['section']); ?>
                </td>
                <td>
                  <?php if ($st['has_template']): ?>
                  <span class="badge badge-green"><i class="ti ti-check"></i> Enrolled</span>
                  <?php else: ?>
                  <span class="badge badge-gray">Not enrolled</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:11px;color:var(--text3);">
                  <?php echo $st['enrolled_at'] ? date('M d, Y', strtotime($st['enrolled_at'])) : '—'; ?>
                </td>
                <td style="text-align:right;">
                  <?php if ($st['has_template']): ?>
                  <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Delete fingerprint for <?php echo addslashes($st['first_name']); ?>? They must re-enroll.')">
                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($st['student_id']); ?>">
                    <button type="submit" name="clear_template" class="btn btn-xs btn-danger">
                      <i class="ti ti-trash"></i> Clear
                    </button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- end right column -->
  </div><!-- end two-col -->

  <!-- ── SCAN LOG ── -->
  <div class="card">
    <p class="card-title">
      <i class="ti ti-activity"></i> Live Scan Log
      <span style="display:flex;align-items:center;gap:5px;margin-left:6px;">
        <span class="live-dot"></span>
        <span style="font-size:10px;font-weight:400;color:var(--green);">Auto-refresh 15s</span>
      </span>
      <a href="biometric.php" class="btn btn-sm btn-outline card-title-right" style="font-size:11px;">
        <i class="ti ti-refresh"></i> Refresh Now
      </a>
    </p>

    <?php if (empty($log_rows)): ?>
    <div class="empty-state">
      <i class="ti ti-fingerprint"></i>
      <p>No scans yet. The log populates as students scan in.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Time</th>
            <th>Device</th>
            <th>Student</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($log_rows as $lg):
            $sc = 'st-' . $lg['status'];
            $student_name = $lg['last_name']
                ? htmlspecialchars($lg['last_name'].', '.$lg['first_name'])
                : '<span style="color:var(--text3);">Unknown</span>';
          ?>
          <tr>
            <td class="td-mono"><?php echo date('M d H:i:s', strtotime($lg['scanned_at'])); ?></td>
            <td style="font-size:12px;color:var(--text2);"><?php echo htmlspecialchars($lg['device_label'] ?? '—'); ?></td>
            <td><?php echo $student_name; ?></td>
            <td style="font-size:12px;color:var(--text2);"><?php echo htmlspecialchars($lg['subject_code'] ?? '—'); ?></td>
            <td>
              <span class="<?php echo $sc; ?>" style="font-size:11px;font-weight:700;text-transform:uppercase;">
                <?php echo $lg['status']; ?>
              </span>
            </td>
            <td style="font-size:12px;color:var(--text2);"><?php echo htmlspecialchars($lg['message'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- end page-wrap -->

<!-- ══ MODALS ══ -->

<!-- Register Device -->
<div class="modal-overlay" id="registerModal">
  <div class="modal">
    <h3><i class="ti ti-cpu" style="color:var(--accent);"></i> Register Device</h3>
    <p class="modal-sub">Enter the DEVICE_KEY you flashed into the Arduino sketch.</p>
    <form method="POST">
      <div class="form-group">
        <label>Device Key <span style="color:var(--red);">*</span></label>
        <input type="text" name="device_key" class="form-control"
          placeholder="e.g. rm201-scanner-a3f9" required autofocus>
        <small>Must match DEVICE_KEY in the .ino sketch exactly.</small>
      </div>
      <div class="form-group">
        <label>Label <span style="color:var(--red);">*</span></label>
        <input type="text" name="device_label" class="form-control"
          placeholder="e.g. Room 201 Scanner" required>
      </div>
      <div class="form-group">
        <label>Assign to Subject</label>
        <select name="device_subject" class="form-control">
          <option value="0">— Unassigned —</option>
          <?php foreach ($my_subjects as $s): ?>
          <option value="<?php echo $s['id']; ?>">
            <?php echo htmlspecialchars($s['subject_code'].' — '.$s['subject_name'].' ('.$s['section'].')'); ?>
          </option>
          <?php endforeach; ?>
        </select>
        <small>You can change this later without reflashing the device.</small>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" name="register_device" class="btn btn-primary" style="flex:1;justify-content:center;">
          <i class="ti ti-check"></i> Register
        </button>
        <button type="button" class="btn btn-outline" onclick="closeModal('registerModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Device -->
<div class="modal-overlay" id="editDeviceModal">
  <div class="modal">
    <h3><i class="ti ti-edit" style="color:var(--accent);"></i> Edit Device</h3>
    <p class="modal-sub">Reassign subject without reflashing.</p>
    <form method="POST">
      <input type="hidden" name="dev_id" id="editDevId">
      <div class="form-group">
        <label>Label</label>
        <input type="text" name="device_label" id="editDevLabel" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Assign to Subject</label>
        <select name="device_subject" id="editDevSubject" class="form-control">
          <option value="0">— Unassigned —</option>
          <?php foreach ($my_subjects as $s): ?>
          <option value="<?php echo $s['id']; ?>">
            <?php echo htmlspecialchars($s['subject_code'].' — '.$s['subject_name'].' ('.$s['section'].')'); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" name="update_device" class="btn btn-primary" style="flex:1;justify-content:center;">
          <i class="ti ti-check"></i> Save
        </button>
        <button type="button" class="btn btn-outline" onclick="closeModal('editDeviceModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
// Navbar dropdown
function toggleDD(){document.getElementById('ddMenu').classList.toggle('open');document.getElementById('ddBtn').classList.toggle('open');}
document.addEventListener('click',e=>{const dd=document.querySelector('.nav-dropdown');if(dd&&!dd.contains(e.target)){document.getElementById('ddMenu')?.classList.remove('open');document.getElementById('ddBtn')?.classList.remove('open');}});

// Modals
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
['registerModal','editDeviceModal'].forEach(id=>{
  const el=document.getElementById(id);
  if(el) el.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});

function openEditDevice(id,label,subjectId){
  document.getElementById('editDevId').value=id;
  document.getElementById('editDevLabel').value=label;
  document.getElementById('editDevSubject').value=subjectId;
  openModal('editDeviceModal');
}

// Roster search
function filterRoster(){
  const q=document.getElementById('rosterSearch').value.toLowerCase();
  document.querySelectorAll('#rosterTable tr').forEach(r=>{
    r.style.display=r.dataset.name?.includes(q)?'':'none';
  });
}

// Enroll list filter
function filterEnrollList(){
  const q=document.getElementById('enrollSearch').value.toLowerCase();
  document.querySelectorAll('#enrollSelect option').forEach(o=>{
    o.style.display=o.dataset.label.includes(q)?'':'none';
  });
}

// Enroll tab switching
function switchEnrollTab(tab) {
  const single  = document.getElementById('enrollSinglePanel');
  const section = document.getElementById('enrollSectionPanel');
  const tabS    = document.getElementById('tabSingle');
  const tabSec  = document.getElementById('tabSection');
  if (tab === 'single') {
    single.style.display  = '';
    section.style.display = 'none';
    tabS.className   = 'btn btn-sm btn-primary';
    tabSec.className = 'btn btn-sm btn-outline';
  } else {
    single.style.display  = 'none';
    section.style.display = '';
    tabS.className   = 'btn btn-sm btn-outline';
    tabSec.className = 'btn btn-sm btn-primary';
  }
}

// Load section students preview via AJAX
function loadSectionStudents(secId) {
  const preview = document.getElementById('sectionPreview');
  const list    = document.getElementById('sectionStudentList');
  const note    = document.getElementById('sectionEnrollNote');
  if (!secId) { preview.style.display='none'; return; }
  list.innerHTML = '<span style="color:var(--text3);">Loading…</span>';
  preview.style.display = '';
  fetch(`?ajax_section_students=${secId}`)
    .then(r => r.json())
    .then(data => {
      if (!data.length) { list.innerHTML='<span style="color:var(--text3);">No students found.</span>'; return; }
      const enrolled = data.filter(s => s.has_template).length;
      list.innerHTML = data.map(s =>
        `<div style="display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.04);">
          <span style="flex:1;">${s.last_name}, ${s.first_name}</span>
          ${s.has_template
            ? '<span class="badge badge-green" style="font-size:9px;">✓ Enrolled</span>'
            : '<span class="badge badge-gray" style="font-size:9px;">Not enrolled</span>'}
        </div>`
      ).join('');
      note.innerHTML = `<span style="color:var(--text3);">${enrolled} / ${data.length} already have templates — they will be <strong>re-enrolled</strong> (overwritten).</span>`;
    })
    .catch(() => { list.innerHTML='<span style="color:var(--red);">Failed to load.</span>'; });
}

// Spinner keyframe
const style=document.createElement('style');
style.textContent='@keyframes spin{to{transform:rotate(360deg);}}';
document.head.appendChild(style);

// Auto-refresh scan log
setTimeout(()=>location.reload(),15000);
</script>

<?php
// Small helper — human-readable time diff
function human_time_diff($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 60)   return $diff . 's';
    if ($diff < 3600) return round($diff/60) . 'm';
    return round($diff/3600) . 'h';
}
?>
</body>
</html>
