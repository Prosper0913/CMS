<?php
// ============================================================
//  teacher/rfid.php  —  RFID Enrollment & Scan Log
//    - Register each student's RFID card (single or whole section)
//    - See which students still need a card
//    - View the recent RFID scan log for your subjects
//  Attendance sessions themselves are started from each subject's
//  Attendance tab (teacher/subject_view.php).
// ============================================================
session_start();
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

// ── Clear enrolled RFID card ───────────────────────────────────
if (isset($_POST['clear_card'])) {
    $student_id = trim($_POST['student_id']);
    // Only students enrolled in one of THIS teacher's subjects
    $own = $conn->prepare(
        "SELECT 1 FROM subject_enrollments se JOIN subjects sub ON sub.id = se.subject_id
         WHERE se.student_id = ? AND sub.teacher_id = ? LIMIT 1"
    );
    $own->bind_param('si', $student_id, $teacher_id);
    $own->execute(); $own->store_result();
    if ($own->num_rows === 0) {
        $error_msg = "That student is not in any of your subjects.";
    } else {
        $del = $conn->prepare("DELETE FROM rfid_cards WHERE student_id=?");
        $del->bind_param('s', $student_id);
        $del->execute();
        $success_msg = "RFID card cleared for <strong>" . htmlspecialchars($student_id) . "</strong>.";
    }
}

// ── AJAX: return students in a section (only this teacher's) ───
if (isset($_GET['ajax_section_students'])) {
    header('Content-Type: application/json');
    $sec_id = (int)$_GET['ajax_section_students'];
    $sq = $conn->prepare(
        "SELECT DISTINCT ss.student_id, s.last_name, s.first_name,
                (rc.id IS NOT NULL) AS has_card
         FROM section_students ss
         JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
         JOIN subject_enrollments se ON se.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
         JOIN subjects sub ON sub.id = se.subject_id AND sub.teacher_id = ?
         LEFT JOIN rfid_cards rc ON rc.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
         WHERE ss.section_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $sq->bind_param('ii', $teacher_id, $sec_id); $sq->execute();
    echo json_encode($sq->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

// ══════════════════════════════════════════════════════════════
//  DATA
// ══════════════════════════════════════════════════════════════

// Teacher's active subjects
$subj_res = $conn->prepare(
    "SELECT id, subject_code, subject_name, section
     FROM subjects WHERE teacher_id=? AND is_active=1 ORDER BY subject_name ASC"
);
$subj_res->bind_param('i', $teacher_id);
$subj_res->execute();
$my_subjects = $subj_res->get_result()->fetch_all(MYSQLI_ASSOC);
$my_subj_ids = array_column($my_subjects, 'id');

// Students enrolled in any of this teacher's subjects + card status
$students = [];
if ($my_subj_ids) {
    $ph  = implode(',', array_fill(0, count($my_subj_ids), '?'));
    $sq  = $conn->prepare(
        "SELECT DISTINCT s.student_id, s.last_name, s.first_name,
                rc.id AS has_card,
                rc.card_uid AS card_uid,
                rc.enrolled_at AS card_enrolled_at,
                sub.subject_code, sub.section
         FROM subject_enrollments se
         JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = se.student_id COLLATE utf8mb4_unicode_ci
         JOIN subjects sub ON sub.id = se.subject_id
         LEFT JOIN rfid_cards rc ON rc.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
         WHERE se.subject_id IN ($ph)
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $types = str_repeat('i', count($my_subj_ids));
    $sq->bind_param($types, ...$my_subj_ids);
    $sq->execute();
    $rows = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
    // One row per student (a student can be in several of the teacher's subjects)
    $deduped = [];
    foreach ($rows as $st) {
        if (!isset($deduped[$st['student_id']])) $deduped[$st['student_id']] = $st;
    }
    $students = array_values($deduped);
}

$enrolled_count = count($students);
$card_count     = count(array_filter($students, fn($s) => $s['has_card']));
$pending_count  = $enrolled_count - $card_count;

// Sections that have students in this teacher's subjects (RFID bulk enroll)
$sec_res = $conn->prepare(
    "SELECT DISTINCT sec.id, sec.section_name, sec.course, sec.year_level,
            COUNT(DISTINCT ss.student_id) AS student_count
     FROM sections sec
     JOIN section_students ss ON ss.section_id = sec.id
     JOIN subject_enrollments se ON se.student_id COLLATE utf8mb4_unicode_ci = ss.student_id COLLATE utf8mb4_unicode_ci
     JOIN subjects sub ON sub.id = se.subject_id AND sub.teacher_id = ?
     GROUP BY sec.id
     ORDER BY sec.section_name ASC"
);
$sec_res->bind_param('i', $teacher_id);
$sec_res->execute();
$sections_list = $sec_res->get_result()->fetch_all(MYSQLI_ASSOC);

// Attendance sessions currently running on this teacher's subjects
$as = $conn->prepare(
    "SELECT COUNT(*) AS n FROM bio_sessions bs JOIN subjects s ON s.id = bs.subject_id
     WHERE s.teacher_id = ? AND bs.status = 'active'
       AND (bs.auto_expire_at IS NULL OR bs.auto_expire_at > NOW())"
);
$as->bind_param('i', $teacher_id); $as->execute();
$active_sessions = (int)$as->get_result()->fetch_assoc()['n'];

// Recent scan log — only scans on THIS teacher's subjects (last 60)
$lq = $conn->prepare(
    "SELECT bl.*, s.last_name, s.first_name, sub.subject_code
     FROM biometric_log bl
     JOIN subjects sub ON sub.id = bl.subject_id AND sub.teacher_id = ?
     LEFT JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = bl.student_id COLLATE utf8mb4_unicode_ci
     ORDER BY bl.scanned_at DESC LIMIT 60"
);
$lq->bind_param('i', $teacher_id); $lq->execute();
$log_rows = $lq->get_result()->fetch_all(MYSQLI_ASSOC);

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
  <title>RFID Enrollment — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-teacher-rfid page-teacher-biometric">
<div class="app-shell">

<!-- NAVBAR -->
<?php $active_nav = 'rfid'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">

  <!-- PAGE HEADER -->
  <div class="page-header with-actions">
    <div>
      <h1><i class="ti ti-id-badge-2 text-accent"></i> RFID Attendance</h1>
      <p>Enroll student RFID cards and review recent card scans.</p>
    </div>
  </div>

  <!-- ── STEP-BY-STEP GUIDE ── -->
  <div class="card" style="margin-bottom:24px;">
    <p class="card-title"><i class="ti ti-route"></i> Setup Guide</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
      <?php
      $steps = [
        ['1','Plug In the RFID Reader','No setup needed — it\'s a USB card reader. Plug it into this computer; it works like a keyboard.','ti-plug'],
        ['2','Enroll Cards','Use the "Enroll via RFID Card" panel below: pick a student (or a whole section) and tap their card on the reader.','ti-id-badge-2'],
        ['3','Start an Attendance Session','Open a subject, go to its Attendance tab, set the late threshold and start a session.','ti-player-play'],
        ['4','Students Tap In','Each student taps their card. The server records Present or Late instantly and updates grades.','ti-checks'],
      ];
      foreach ($steps as [$n,$title,$desc,$icon]): ?>
      <div style="display:flex;gap:12px;align-items:flex-start;">
        <div style="width:28px;height:28px;border-radius:8px;background:var(--accent-dim);border:1px solid var(--accent-glow);display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-size:12px;font-weight:700;color:var(--accent);flex-shrink:0;"><?php echo $n; ?></div>
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--bg);margin-bottom:3px;display:flex;align-items:center;gap:5px;">
            <i class="ti <?php echo $icon; ?>" style="font-size:13px;color:var(--accent);"></i>
            <?php echo $title; ?>
          </div>
          <div style="font-size:12px;color:var(--bg4);"><?php echo $desc; ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- STAT CHIPS -->
  <div class="stat-chips">
    <div class="stat-chip g">
      <div class="stat-chip-val"><?php echo $active_sessions; ?></div>
      <div class="stat-chip-lbl">Active Sessions</div>
    </div>
    <div class="stat-chip g">
      <div class="stat-chip-val"><?php echo $card_count; ?></div>
      <div class="stat-chip-lbl">Enrolled Cards</div>
    </div>
    <div class="stat-chip y">
      <div class="stat-chip-val"><?php echo $pending_count; ?></div>
      <div class="stat-chip-lbl">Pending Enrollment</div>
    </div>
  </div>

  <div class="two-col">

    <!-- LEFT COLUMN: roster -->
    <div>
      <div class="card">
        <p class="card-title">
          <i class="ti ti-users"></i> Student Card Roster
          <span class="card-title-right" style="font-size:11px;font-weight:400;color:var(--text3);">
            <?php echo $card_count; ?> / <?php echo $enrolled_count; ?> students have a card
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
                <th>RFID Card</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="rosterTable">
              <?php foreach ($students as $st): ?>
              <tr data-name="<?php echo htmlspecialchars(strtolower($st['last_name'].' '.$st['first_name'].' '.$st['student_id'])); ?>">
                <td>
                  <div style="font-weight:500;"><?php echo htmlspecialchars($st['last_name'].', '.$st['first_name']); ?></div>
                  <div class="td-mono"><?php echo htmlspecialchars($st['student_id']); ?></div>
                </td>
                <td style="font-size:12px;color:var(--text7);">
                  <?php echo htmlspecialchars($st['subject_code'].' '.$st['section']); ?>
                </td>
                <td>
                  <?php if ($st['has_card']): ?>
                  <span class="badge badge-green"><i class="ti ti-check"></i> Enrolled</span>
                  <div style="font-size:10px;color:var(--text7);margin-top:2px;">
                    <?php echo htmlspecialchars($st['card_uid'] ?? ''); ?>
                  </div>
                  <?php else: ?>
                  <span class="badge badge-gray">Not enrolled</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap;">
                  <?php if ($st['has_card']): ?>
                  <form method="POST" style="display:inline;"
                    onsubmit="return confirm('Delete RFID card for <?php echo htmlspecialchars(addslashes($st['first_name']), ENT_QUOTES); ?>? They must re-enroll a card.')">
                    <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($st['student_id']); ?>">
                    <button type="submit" name="clear_card" class="btn btn-xs btn-danger" title="Clear RFID card">
                      <i class="ti ti-id-badge-2"></i>
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
    </div><!-- end left column -->

    <!-- RIGHT COLUMN: enroll -->
    <div>
      <!-- ── ENROLL VIA RFID CARD ── -->
      <!-- The USB reader is plug-and-play: no device to register, no
           queue to poll. Everything here is synchronous — tap a card,
           it's saved immediately, client-side JS moves on to the next
           student for the bulk path. -->
      <div class="card">
        <p class="card-title"><i class="ti ti-id-badge-2 text-green"></i> Enroll via RFID Card</p>

        <?php if (empty($students)): ?>
        <div class="alert alert-info" style="margin-bottom:0;">
          <i class="ti ti-info-circle"></i> No students enrolled in your subjects yet.
        </div>
        <?php else: ?>

        <!-- Enroll mode tabs -->
        <div style="display:flex;gap:6px;margin-bottom:14px;">
          <button type="button" class="btn btn-sm btn-primary" id="rfidTabSingle" onclick="switchRfidEnrollTab('single')">
            <i class="ti ti-user"></i> Single Student
          </button>
          <button type="button" class="btn btn-sm btn-outline" id="rfidTabSection" onclick="switchRfidEnrollTab('section')">
            <i class="ti ti-users-group"></i> Whole Section
          </button>
        </div>

        <!-- ── Single student ── -->
        <div id="rfidEnrollSinglePanel">
          <div class="form-group">
            <label>Student <span class="text-red">*</span></label>
            <input type="text" id="rfidEnrollSearch" class="form-control"
              placeholder="Type to filter…" oninput="filterRfidEnrollList()" style="margin-bottom:6px;">
            <select id="rfidEnrollSelect" class="form-control" size="5" style="height:auto;padding:4px;">
              <?php foreach ($students as $st): ?>
              <option value="<?php echo htmlspecialchars($st['student_id']); ?>"
                data-label="<?php echo strtolower($st['last_name'].' '.$st['first_name'].' '.$st['student_id']); ?>">
                <?php echo htmlspecialchars($st['last_name'].', '.$st['first_name'].' ('.$st['student_id'].')'); ?>
                <?php echo $st['has_card'] ? ' ✓RFID' : ''; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="button" id="rfidStartCaptureBtn" class="btn btn-primary btn-full" onclick="startRfidCapture()">
            <i class="ti ti-id-badge-2"></i> Start Capture
          </button>

          <div id="rfidCapturePanel" style="display:none;margin-top:10px;">
            <div class="alert alert-info" style="padding:10px 12px;font-size:12px;margin-bottom:10px;">
              <i class="ti ti-id-badge-2"></i>
              Tap <strong id="rfidCaptureStudentName"></strong>'s card on the USB reader now.
            </div>
            <input type="text" id="rfidEnrollCaptureInput" class="form-control" autocomplete="off"
              placeholder="Waiting for tap…" style="text-align:center;font-family:var(--font-mono);">
            <div id="rfidEnrollResult" style="margin-top:8px;font-size:12px;min-height:18px;"></div>
            <button type="button" class="btn btn-outline btn-sm" style="margin-top:6px;" onclick="cancelRfidCapture()">
              <i class="ti ti-x"></i> Cancel
            </button>
          </div>
        </div>

        <!-- ── Whole section — walks through students one at a time,
             tap a card and it auto-advances; no device, no queue, the
             teacher just keeps tapping cards in front of the class. ── -->
        <div id="rfidEnrollSectionPanel" style="display:none;">
          <?php if (empty($sections_list)): ?>
          <div class="alert alert-info" style="margin-bottom:0;">
            <i class="ti ti-info-circle"></i> No sections found. Assign students to sections in the Sections panel.
          </div>
          <?php else: ?>
          <div class="form-group">
            <label>Section <span class="text-red">*</span></label>
            <select id="rfidSectionSelect" class="form-control" onchange="loadRfidSectionStudents(this.value)">
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

          <div id="rfidSectionPreview" style="display:none;margin-bottom:10px;">
            <p class="section-label">Students in this section</p>
            <div id="rfidSectionStudentList" style="background:var(--bg3);border-radius:var(--radius);padding:8px;max-height:160px;overflow-y:auto;font-size:12px;"></div>
            <small style="margin-top:4px;display:block;color:var(--text3);">
              Students who already have a card are included too — tapping a new card for them overwrites it. Skip anyone who's absent.
            </small>
          </div>

          <button type="button" id="rfidBulkStartBtn" class="btn btn-primary btn-full" style="display:none;" onclick="startRfidBulkCapture()">
            <i class="ti ti-users-group"></i> Start Bulk Capture
          </button>

          <div id="rfidBulkPanel" style="display:none;margin-top:10px;">
            <div class="alert alert-info" style="padding:10px 12px;font-size:12px;margin-bottom:10px;">
              <i class="ti ti-id-badge-2"></i>
              <span id="rfidBulkProgress"></span> — tap <strong id="rfidBulkStudentName"></strong>'s card now.
            </div>
            <input type="text" id="rfidBulkCaptureInput" class="form-control" autocomplete="off"
              placeholder="Waiting for tap…" style="text-align:center;font-family:var(--font-mono);">
            <div id="rfidBulkResult" style="margin-top:8px;font-size:12px;min-height:18px;"></div>
            <div style="display:flex;gap:8px;margin-top:6px;">
              <button type="button" class="btn btn-outline btn-sm" style="flex:1;" onclick="skipRfidBulkStudent()">
                <i class="ti ti-player-skip-forward"></i> Skip
              </button>
              <button type="button" class="btn btn-outline btn-sm" style="flex:1;" onclick="cancelRfidBulkCapture()">
                <i class="ti ti-x"></i> Stop
              </button>
            </div>
            <div id="rfidBulkLog" style="margin-top:10px;font-size:11px;color:var(--text6);max-height:100px;overflow-y:auto;"></div>
          </div>
          <?php endif; ?>
        </div>

        <?php endif; ?>
      </div>

    </div><!-- end right column -->
  </div><!-- end two-col -->

  <!-- ── SCAN LOG ── -->
  <div class="card">
    <p class="card-title">
      <i class="ti ti-activity"></i> RFID Scan Log
      <span style="display:flex;align-items:center;gap:5px;margin-left:6px;">
        <span class="live-dot"></span>
        <span style="font-size:10px;font-weight:400;color:var(--green);">Auto-refresh 15s</span>
      </span>
      <a href="rfid.php" class="btn btn-sm btn-outline card-title-right" style="font-size:11px;">
        <i class="ti ti-refresh"></i> Refresh Now
      </a>
    </p>

    <?php if (empty($log_rows)): ?>
    <div class="empty-state">
      <i class="ti ti-id-badge-2"></i>
      <p>No scans yet. The log populates as students tap their cards.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Time</th>
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
                : '<span class="text-muted">Unknown</span>';
          ?>
          <tr>
            <td class="td-mono"><?php echo date('M d H:i:s', strtotime($lg['scanned_at'])); ?></td>
            <td><?php echo $student_name; ?></td>
            <td style="font-size:12px;color:var(--text7);"><?php echo htmlspecialchars($lg['subject_code'] ?? '—'); ?></td>
            <td>
              <span class="<?php echo htmlspecialchars($sc); ?>" style="font-size:11px;font-weight:700;text-transform:uppercase;">
                <?php echo htmlspecialchars(str_replace('_',' ',$lg['status'])); ?>
              </span>
            </td>
            <td style="font-size:12px;color:var(--text7);"><?php echo htmlspecialchars($lg['message'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- end page-wrap -->

<script>
// Navbar dropdown
function toggleDD(){document.getElementById('ddMenu').classList.toggle('open');document.getElementById('ddBtn').classList.toggle('open');}
document.addEventListener('click',e=>{const dd=document.querySelector('.nav-dropdown');if(dd&&!dd.contains(e.target)){document.getElementById('ddMenu')?.classList.remove('open');document.getElementById('ddBtn')?.classList.remove('open');}});

// Roster search
function filterRoster(){
  const q=document.getElementById('rosterSearch').value.toLowerCase();
  document.querySelectorAll('#rosterTable tr').forEach(r=>{
    r.style.display=r.dataset.name?.includes(q)?'':'none';
  });
}

// ── RFID enrollment (synchronous — no device, no queue) ─────────
// The USB reader is a keyboard-emulation HID device: tapping a card
// types its UID into whatever input has focus, then presses Enter.
function filterRfidEnrollList(){
  const q=document.getElementById('rfidEnrollSearch').value.toLowerCase();
  document.querySelectorAll('#rfidEnrollSelect option').forEach(o=>{
    o.style.display=o.dataset.label.includes(q)?'':'none';
  });
}

let _rfidEnterHandler = null;

function startRfidCapture(){
  const sel = document.getElementById('rfidEnrollSelect');
  if (!sel.value) { alert('Choose a student first.'); return; }
  const label = sel.options[sel.selectedIndex].textContent.trim();

  document.getElementById('rfidCaptureStudentName').textContent = label;
  document.getElementById('rfidCapturePanel').style.display = '';
  document.getElementById('rfidStartCaptureBtn').style.display = 'none';
  const input = document.getElementById('rfidEnrollCaptureInput');
  const result = document.getElementById('rfidEnrollResult');
  result.textContent = '';
  input.value = '';
  input.focus();

  _rfidEnterHandler = function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const uid = input.value.trim();
    input.value = '';
    if (!uid) return;

    result.textContent = 'Saving…';
    result.style.color = 'var(--text6)';

    fetch('/classroomv2/api/rfid_enroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ student_id: sel.value, card_uid: uid })
    })
      .then(r => r.json())
      .then(d => {
        if (d.status === 'ok') {
          result.textContent = `✓ Card saved for ${d.student}`;
          result.style.color = 'var(--green)';
          setTimeout(() => location.reload(), 900);
        } else {
          result.textContent = d.message || 'Failed to save card';
          result.style.color = '#f87171';
        }
      })
      .catch(() => {
        result.textContent = 'Network error — card not saved';
        result.style.color = '#f87171';
      });
  };
  input.addEventListener('keydown', _rfidEnterHandler);
}

function cancelRfidCapture(){
  const input = document.getElementById('rfidEnrollCaptureInput');
  if (_rfidEnterHandler) input.removeEventListener('keydown', _rfidEnterHandler);
  document.getElementById('rfidCapturePanel').style.display = 'none';
  document.getElementById('rfidStartCaptureBtn').style.display = '';
}

// RFID enroll tab switching (Single Student / Whole Section)
function switchRfidEnrollTab(tab) {
  const single  = document.getElementById('rfidEnrollSinglePanel');
  const section = document.getElementById('rfidEnrollSectionPanel');
  const tabS    = document.getElementById('rfidTabSingle');
  const tabSec  = document.getElementById('rfidTabSection');
  if (tab === 'single') {
    cancelRfidBulkCapture();
    single.style.display  = '';
    section.style.display = 'none';
    tabS.className   = 'btn btn-sm btn-primary';
    tabSec.className = 'btn btn-sm btn-outline';
  } else {
    cancelRfidCapture();
    single.style.display  = 'none';
    section.style.display = '';
    tabS.className   = 'btn btn-sm btn-outline';
    tabSec.className = 'btn btn-sm btn-primary';
  }
}

// ── RFID whole-section bulk enroll ───────────────────────────────
// No device, no queue — this walks a local in-browser list of
// students one at a time. Tap a card, it's saved via rfid_enroll.php
// and the panel immediately moves to the next student. "Skip" moves
// on without saving (absent student, no card handy, etc).
let rfidBulkQueue = [];
let rfidBulkIndex = 0;
let _rfidBulkEnterHandler = null;

function loadRfidSectionStudents(secId) {
  const preview = document.getElementById('rfidSectionPreview');
  const list    = document.getElementById('rfidSectionStudentList');
  const startBtn = document.getElementById('rfidBulkStartBtn');
  rfidBulkQueue = [];
  startBtn.style.display = 'none';
  if (!secId) { preview.style.display = 'none'; return; }
  list.innerHTML = '<span style="color:var(--text3);">Loading…</span>';
  preview.style.display = '';
  fetch(`?ajax_section_students=${secId}`)
    .then(r => r.json())
    .then(data => {
      if (!data.length) { list.innerHTML = '<span style="color:var(--text3);">No students found.</span>'; return; }
      rfidBulkQueue = data.map(s => ({
        student_id: s.student_id,
        name: `${s.last_name}, ${s.first_name}`,
        has_card: !!s.has_card
      }));
      list.innerHTML = data.map(s =>
        `<div style="display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.04);">
          <span style="flex:1;">${s.last_name}, ${s.first_name}</span>
          ${s.has_card ? '<span class="badge badge-green" style="font-size:9px;">✓ RFID</span>' : '<span class="badge badge-gray" style="font-size:9px;">No card</span>'}
        </div>`
      ).join('');
      startBtn.style.display = '';
    })
    .catch(() => { list.innerHTML = '<span class="text-red">Failed to load.</span>'; });
}

function startRfidBulkCapture() {
  if (!rfidBulkQueue.length) return;
  rfidBulkIndex = 0;
  document.getElementById('rfidBulkStartBtn').style.display = 'none';
  document.getElementById('rfidBulkPanel').style.display = '';
  document.getElementById('rfidBulkLog').innerHTML = '';
  showRfidBulkCurrent();
}

function showRfidBulkCurrent() {
  const input = document.getElementById('rfidBulkCaptureInput');
  if (rfidBulkIndex >= rfidBulkQueue.length) {
    document.getElementById('rfidBulkProgress').textContent = 'All done';
    document.getElementById('rfidBulkStudentName').textContent = '';
    input.value = '';
    input.disabled = true;
    document.getElementById('rfidBulkResult').textContent = `Finished — ${rfidBulkQueue.length} student(s) processed.`;
    document.getElementById('rfidBulkResult').style.color = 'var(--green)';
    return;
  }
  const cur = rfidBulkQueue[rfidBulkIndex];
  document.getElementById('rfidBulkProgress').textContent = `${rfidBulkIndex + 1} of ${rfidBulkQueue.length}`;
  document.getElementById('rfidBulkStudentName').textContent = cur.name;
  document.getElementById('rfidBulkResult').textContent = '';
  input.disabled = false;
  input.value = '';
  input.focus();

  if (_rfidBulkEnterHandler) input.removeEventListener('keydown', _rfidBulkEnterHandler);
  _rfidBulkEnterHandler = function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const uid = input.value.trim();
    input.value = '';
    if (!uid) return;

    const result = document.getElementById('rfidBulkResult');
    result.textContent = 'Saving…';
    result.style.color = 'var(--text6)';

    fetch('/classroomv2/api/rfid_enroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ student_id: cur.student_id, card_uid: uid })
    })
      .then(r => r.json())
      .then(d => {
        const log = document.getElementById('rfidBulkLog');
        if (d.status === 'ok') {
          log.innerHTML = `<div style="color:var(--green);">✓ ${cur.name}</div>` + log.innerHTML;
          rfidBulkIndex++;
          showRfidBulkCurrent();
        } else {
          result.textContent = d.message || 'Failed to save card';
          result.style.color = '#f87171';
          log.innerHTML = `<div style="color:#f87171;">✗ ${cur.name} — ${d.message || 'failed'}</div>` + log.innerHTML;
        }
      })
      .catch(() => {
        result.textContent = 'Network error — card not saved';
        result.style.color = '#f87171';
      });
  };
  input.addEventListener('keydown', _rfidBulkEnterHandler);
}

function skipRfidBulkStudent() {
  if (rfidBulkIndex >= rfidBulkQueue.length) return;
  const cur = rfidBulkQueue[rfidBulkIndex];
  const log = document.getElementById('rfidBulkLog');
  log.innerHTML = `<div style="color:var(--text6);">— ${cur.name} (skipped)</div>` + log.innerHTML;
  rfidBulkIndex++;
  showRfidBulkCurrent();
}

function cancelRfidBulkCapture() {
  const input = document.getElementById('rfidBulkCaptureInput');
  if (input && _rfidBulkEnterHandler) input.removeEventListener('keydown', _rfidBulkEnterHandler);
  const panel = document.getElementById('rfidBulkPanel');
  if (panel) panel.style.display = 'none';
  const startBtn = document.getElementById('rfidBulkStartBtn');
  if (startBtn && rfidBulkQueue.length) startBtn.style.display = '';
}


// Auto-refresh scan log — but never while the teacher is mid-enrollment
// (capture panel open) or typing in a field; keep checking every few
// seconds and reload as soon as the coast is clear.
function scheduleLogRefresh(){
  setTimeout(function tick(){
    const el = document.activeElement;
    const typing = el && ['INPUT','SELECT','TEXTAREA'].includes(el.tagName);
    const capturing = ['rfidCapturePanel','rfidBulkPanel'].some(id => {
      const p = document.getElementById(id);
      return p && p.style.display !== 'none' && p.style.display !== '';
    });
    if (typing || capturing) {
      setTimeout(tick, 3000);
    } else {
      location.reload();
    }
  }, 15000);
}
scheduleLogRefresh();
</script>
</main>
</div>
<script src="/classroomv2/assets/ui.js" defer></script>
<script src="/classroomv2/assets/pwa.js" defer></script>
</body>
</html>
