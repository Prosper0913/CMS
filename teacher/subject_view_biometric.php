<?php
// ============================================================
//  teacher/subject_view_biometric.php
//  Embedded biometric session panel for subject_view.php
//
//  USAGE — paste this inside subject_view.php where you want
//  the biometric panel to appear:
//
//    <?php include 'subject_view_biometric.php'; ?>
//
//  Requires: $conn, $teacher_id, $subject_id already set.
//  If subject_id comes from $_GET['id'], add before include:
//    $subject_id = (int)($_GET['id'] ?? 0);
//
//  Also works as a STANDALONE page:
//    /classroom/teacher/subject_view_biometric.php?id=7
// ============================================================

// ── Allow standalone use ───────────────────────────────────────
if (!isset($conn)) {
    require_once '../includes/auth.php';
    requireRole('teacher');
    require_once '../config/db.php';
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $teacher_id = $_SESSION['user_id'];
    $subject_id = (int)($_GET['id'] ?? 0);
    $standalone = true;
} else {
    $standalone = false;
}

if (!$subject_id) {
    if ($standalone) { echo '<p style="color:red;">No subject specified.</p>'; exit; }
    return;
}

// ── Verify this teacher owns the subject ──────────────────────
$subj_q = $conn->prepare(
    "SELECT id, subject_code, subject_name, section, teacher_id
     FROM subjects WHERE id=? LIMIT 1"
);
$subj_q->bind_param('i', $subject_id); $subj_q->execute();
$subject = $subj_q->get_result()->fetch_assoc();
if (!$subject || $subject['teacher_id'] != $teacher_id) {
    if ($standalone) { echo '<p style="color:red;">Subject not found.</p>'; exit; }
    return;
}

// ── POST HANDLERS ─────────────────────────────────────────────
$bio_msg   = '';
$bio_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bio_action'])) {
    $action = $_POST['bio_action'];

    // Start session
    if ($action === 'start_session') {
        $dev_id         = (int)$_POST['bio_dev_id'];
        $late_threshold = trim($_POST['late_threshold'] ?? '08:15:00');
        $duration_min   = (int)($_POST['duration_min'] ?? 90);
        if ($dev_id === 0) {
            $bio_error = "Select a device.";
        } else {
            // End any existing active session for this device
            $end = $conn->prepare(
                "UPDATE bio_sessions SET status='ended', ended_at=NOW()
                 WHERE device_id=? AND status='active'"
            );
            $end->bind_param('i', $dev_id); $end->execute();
            // Start new session
            $ins = $conn->prepare(
                "INSERT INTO bio_sessions
                 (device_id, subject_id, started_by, status, late_threshold, auto_expire_at, started_at)
                 VALUES (?, ?, ?, 'active', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())"
            );
            $ins->bind_param('iiisi', $dev_id, $subject_id, $teacher_id, $late_threshold, $duration_min);
            $ins->execute();
            $bio_msg = "Session started. Device will pick it up within seconds.";
        }
    }

    // Stop session
    if ($action === 'stop_session') {
        $session_id = (int)$_POST['bio_session_id'];
        $end = $conn->prepare(
            "UPDATE bio_sessions SET status='ended', ended_at=NOW()
             WHERE id=? AND subject_id=?");
        $end->bind_param('ii', $session_id, $subject_id); $end->execute();
        $bio_msg = "Session ended.";
    }
}

// ── AJAX: live attendance feed ────────────────────────────────
if (isset($_GET['bio_ajax']) && $_GET['bio_ajax'] === 'live' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $date = $_GET['date'] ?? date('Y-m-d');
    $since = $_GET['since'] ?? '0000-00-00 00:00:00';

    // All students enrolled + today's attendance
    $sq = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name,
                a.status AS att_status, a.time_in, a.source,
                (ft.id IS NOT NULL) AS has_template
         FROM subject_enrollments se
         JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = se.student_id COLLATE utf8mb4_unicode_ci
         LEFT JOIN attendance a ON a.subject_id = se.subject_id
             AND a.student_id COLLATE utf8mb4_unicode_ci = se.student_id COLLATE utf8mb4_unicode_ci
             AND a.date = ?
         LEFT JOIN fingerprint_templates ft ON ft.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
         WHERE se.subject_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $sq->bind_param('si', $date, $subject_id); $sq->execute();
    $rows = $sq->get_result()->fetch_all(MYSQLI_ASSOC);

    // Active session info
    $sess_q = $conn->prepare(
        "SELECT bs.id, bs.late_threshold, bs.auto_expire_at, bs.started_at,
                bd.label AS device_label
         FROM bio_sessions bs
         JOIN bio_devices bd ON bd.id = bs.device_id
         WHERE bs.subject_id=? AND bs.status='active'
         ORDER BY bs.started_at DESC LIMIT 1"
    );
    $sess_q->bind_param('i', $subject_id); $sess_q->execute();
    $session = $sess_q->get_result()->fetch_assoc();

    // Counts
    $present = count(array_filter($rows, fn($r) => $r['att_status'] === 'Present'));
    $late    = count(array_filter($rows, fn($r) => $r['att_status'] === 'Late'));
    $absent  = count(array_filter($rows, fn($r) => !$r['att_status']));
    $total   = count($rows);

    echo json_encode([
        'session'  => $session,
        'students' => $rows,
        'counts'   => compact('present','late','absent','total'),
        'time'     => date('H:i:s'),
    ]);
    exit;
}

// ── Load devices bound to this subject + active session ───────
// Auto-expire first
$conn->query(
    "UPDATE bio_sessions SET status='ended', ended_at=NOW()
     WHERE status='active' AND auto_expire_at IS NOT NULL AND auto_expire_at < NOW()"
);

$dev_q = $conn->prepare(
    "SELECT d.id, d.label, d.last_seen, d.device_key
     FROM bio_devices d
     WHERE d.subject_id = ?
     ORDER BY d.label ASC"
);
$dev_q->bind_param('i', $subject_id); $dev_q->execute();
$bio_devices = $dev_q->get_result()->fetch_all(MYSQLI_ASSOC);

// Active session for this subject
$sess_q = $conn->prepare(
    "SELECT bs.*, bd.label AS device_label
     FROM bio_sessions bs
     JOIN bio_devices bd ON bd.id = bs.device_id
     WHERE bs.subject_id=? AND bs.status='active'
     ORDER BY bs.started_at DESC LIMIT 1"
);
$sess_q->bind_param('i', $subject_id); $sess_q->execute();
$active_session = $sess_q->get_result()->fetch_assoc();

// Today's attendance summary
$today = date('Y-m-d');
$att_q = $conn->prepare(
    "SELECT a.status, COUNT(*) AS cnt FROM attendance a
     WHERE a.subject_id=? AND a.date=? GROUP BY a.status"
);
$att_q->bind_param('is', $subject_id, $today); $att_q->execute();
$att_counts = ['Present'=>0,'Late'=>0,'Absent'=>0];
$att_res = $att_q->get_result();
while ($r = $att_res->fetch_assoc()) $att_counts[$r['status']] = (int)$r['cnt'];

$total_enrolled_q = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM subject_enrollments WHERE subject_id=?"
);
$total_enrolled_q->bind_param('i', $subject_id); $total_enrolled_q->execute();
$total_enrolled = (int)$total_enrolled_q->get_result()->fetch_assoc()['cnt'];

// ── Standalone page output ────────────────────────────────────
if ($standalone):
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Biometric Session — <?php echo htmlspecialchars($subject['subject_code']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroom/assets/style.css">
</head>
<?php if ($standalone): ?>
<body style="background:#0c0e14;min-height:100vh;padding:32px 28px;">
<div style="max-width:1200px;margin:0 auto;">
  <div style="margin-bottom:20px;">
    <a href="subject_view.php?id=<?php echo $subject_id; ?>" style="color:#5b8dee;font-size:13px;text-decoration:none;">
      <i class="ti ti-arrow-left"></i> Back to <?php echo htmlspecialchars($subject['subject_code']); ?>
    </a>
  </div>
<?php endif; ?>

<!-- ══ BIO PANEL ══ -->
<div class="bio-panel" id="bioPanelWrap">

  <!-- Header -->
  <div class="bio-section-header">
    <h2>
      <i class="ti ti-fingerprint" style="color:#5b8dee;"></i>
      Biometric Attendance
      <?php if ($active_session): ?>
        <span class="bio-badge bio-badge-green" style="font-size:11px;">
          <span class="bio-live-dot"></span> LIVE
        </span>
      <?php endif; ?>
    </h2>
    <span style="font-size:12px;color:#7d8aaa;margin-left:auto;">
      <?php echo htmlspecialchars($subject['subject_code'].' — '.$subject['section']); ?>
      &nbsp;·&nbsp; <?php echo date('D, M d Y'); ?>
    </span>
  </div>

  <?php if ($bio_msg): ?>
  <div class="bio-alert bio-alert-success"><i class="ti ti-circle-check"></i> <?php echo $bio_msg; ?></div>
  <?php endif; ?>
  <?php if ($bio_error): ?>
  <div class="bio-alert bio-alert-error"><i class="ti ti-alert-circle"></i> <?php echo $bio_error; ?></div>
  <?php endif; ?>

  <!-- Live stats bar -->
  <div class="bio-stat-row">
    <div class="bio-stat present">
      <div class="bio-stat-val" id="statPresent"><?php echo $att_counts['Present']; ?></div>
      <div class="bio-stat-lbl">Present</div>
    </div>
    <div class="bio-stat late">
      <div class="bio-stat-val" id="statLate"><?php echo $att_counts['Late']; ?></div>
      <div class="bio-stat-lbl">Late</div>
    </div>
    <div class="bio-stat absent">
      <div class="bio-stat-val" id="statAbsent"><?php echo $total_enrolled - $att_counts['Present'] - $att_counts['Late']; ?></div>
      <div class="bio-stat-lbl">Not Yet</div>
    </div>
    <div class="bio-stat total">
      <div class="bio-stat-val" id="statTotal"><?php echo $total_enrolled; ?></div>
      <div class="bio-stat-lbl">Total</div>
    </div>
  </div>

  <div class="bio-grid">

    <!-- LEFT: Session control -->
    <div>
      <div class="bio-card">
        <div class="bio-card-title"><i class="ti ti-player-play" style="color:#34d399;"></i> Session Control</div>

        <?php if ($active_session): ?>
        <!-- Active session -->
        <div class="bio-session-active">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <span class="bio-live-dot"></span>
            <span style="font-weight:600;font-size:13px;">Session Active</span>
            <span class="bio-pill" style="margin-left:auto;font-size:10px;"><?php echo htmlspecialchars($active_session['device_label']); ?></span>
          </div>
          <div class="bio-info-row"><span>Started</span><span><?php echo date('H:i', strtotime($active_session['started_at'])); ?></span></div>
          <div class="bio-info-row"><span>Expires</span><span><?php echo $active_session['auto_expire_at'] ? date('H:i', strtotime($active_session['auto_expire_at'])) : 'Manual'; ?></span></div>
          <div class="bio-info-row"><span>Late after</span><span><?php echo $active_session['late_threshold']; ?></span></div>
        </div>
        <form method="POST">
          <input type="hidden" name="bio_action" value="stop_session">
          <input type="hidden" name="bio_session_id" value="<?php echo $active_session['id']; ?>">
          <button type="submit" class="bio-btn bio-btn-red"
            onclick="return confirm('Stop the active session?')">
            <i class="ti ti-player-stop"></i> Stop Session
          </button>
        </form>

        <?php elseif (empty($bio_devices)): ?>
        <div class="bio-empty">
          <i class="ti ti-cpu-off"></i>
          <p style="font-size:12px;">No devices assigned to this subject.<br>Go to <a href="biometric.php" style="color:#5b8dee;">Biometric Setup</a> to register one.</p>
        </div>

        <?php else: ?>
        <!-- Start session form -->
        <div class="bio-session-idle">
          <div style="font-size:12px;color:#7d8aaa;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
            <i class="ti ti-clock" style="font-size:14px;"></i> No active session
          </div>
          <form method="POST">
            <input type="hidden" name="bio_action" value="start_session">
            <div class="bio-form-group">
              <label>Device</label>
              <select name="bio_dev_id" class="bio-input" required>
                <option value="">— Choose —</option>
                <?php foreach ($bio_devices as $bd):
                  $online = $bd['last_seen'] && (time() - strtotime($bd['last_seen']) < 120);
                ?>
                <option value="<?php echo $bd['id']; ?>">
                  <?php echo htmlspecialchars($bd['label']); ?>
                  <?php echo $online ? ' 🟢' : ' ⚪'; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="bio-row">
              <div class="bio-form-group">
                <label>Late After</label>
                <input type="time" name="late_threshold" class="bio-input" value="08:15" step="60" required>
              </div>
              <div class="bio-form-group">
                <label>Duration (min)</label>
                <input type="number" name="duration_min" class="bio-input" value="90" min="10" max="480" required>
              </div>
            </div>
            <button type="submit" class="bio-btn bio-btn-green">
              <i class="ti ti-player-play"></i> Start Session
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>

      <!-- Device status card -->
      <?php if (!empty($bio_devices)): ?>
      <div class="bio-card">
        <div class="bio-card-title"><i class="ti ti-cpu"></i> Devices</div>
        <?php foreach ($bio_devices as $bd):
          $online = $bd['last_seen'] && (time() - strtotime($bd['last_seen']) < 120);
          $last_seen_str = $bd['last_seen']
            ? (time() - strtotime($bd['last_seen']) < 60 ? 'Just now'
              : round((time()-strtotime($bd['last_seen']))/60).'m ago')
            : 'Never';
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #252a3d;">
          <div style="width:7px;height:7px;border-radius:50%;background:<?php echo $online ? '#34d399' : '#3d4560'; ?>;flex-shrink:0;<?php echo $online ? 'animation:bio-pulse 1.5s infinite;' : ''; ?>"></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:500;"><?php echo htmlspecialchars($bd['label']); ?></div>
            <div style="font-size:10px;color:#3d4560;font-family:'DM Mono',monospace;"><?php echo htmlspecialchars($bd['device_key']); ?></div>
          </div>
          <span style="font-size:11px;color:<?php echo $online ? '#34d399' : '#3d4560'; ?>;">
            <?php echo $online ? 'Online' : $last_seen_str; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Live attendance roster -->
    <div>
      <div class="bio-card">
        <div class="bio-card-title">
          <i class="ti ti-users"></i> Live Attendance Roster
          <?php if ($active_session): ?>
          <span style="display:flex;align-items:center;gap:5px;margin-left:6px;">
            <span class="bio-live-dot"></span>
            <span style="font-size:9px;font-weight:400;color:#34d399;">Updates every 5s</span>
          </span>
          <?php endif; ?>
          <span id="bioLastUpdated" style="margin-left:auto;font-size:9px;font-weight:400;color:#3d4560;"></span>
        </div>

        <div class="bio-search">
          <i class="ti ti-search"></i>
          <input type="text" class="bio-input" id="bioRosterSearch"
            placeholder="Search student…" oninput="filterBioRoster()">
        </div>

        <!-- Filter tabs -->
        <div style="display:flex;gap:6px;margin-bottom:12px;">
          <button class="bio-btn bio-btn-green" id="bioFilterAll" style="width:auto;padding:4px 12px;font-size:11px;" onclick="setBioFilter('all')">All</button>
          <button class="bio-btn" id="bioFilterPresent" style="width:auto;padding:4px 12px;font-size:11px;background:transparent;border:1px solid #252a3d;color:#7d8aaa;" onclick="setBioFilter('present')">Present</button>
          <button class="bio-btn" id="bioFilterLate" style="width:auto;padding:4px 12px;font-size:11px;background:transparent;border:1px solid #252a3d;color:#7d8aaa;" onclick="setBioFilter('late')">Late</button>
          <button class="bio-btn" id="bioFilterAbsent" style="width:auto;padding:4px 12px;font-size:11px;background:transparent;border:1px solid #252a3d;color:#7d8aaa;" onclick="setBioFilter('not yet')">Not Yet</button>
        </div>

        <div id="bioRosterWrap">
          <table class="bio-table" id="bioRosterTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Student</th>
                <th>Fingerprint</th>
                <th>Status</th>
                <th>Time In</th>
                <th>Source</th>
              </tr>
            </thead>
            <tbody id="bioRosterBody">
              <!-- Populated by JS -->
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- end bio-grid -->
</div><!-- end bio-panel -->

<script>
(function(){
  const SUBJECT_ID  = <?php echo $subject_id; ?>;
  const IS_ACTIVE   = <?php echo $active_session ? 'true' : 'false'; ?>;
  const POLL_MS     = IS_ACTIVE ? 5000 : 30000;
  const TODAY       = '<?php echo $today; ?>';
  let   bioFilter   = 'all';
  let   bioSearch   = '';
  let   lastData    = null;

  // Initial render — use PHP-rendered data so page feels instant
  const initStudents = <?php
    $init_q = $conn->prepare(
        "SELECT s.student_id, s.last_name, s.first_name,
                a.status AS att_status, a.time_in, a.source,
                (ft.id IS NOT NULL) AS has_template
         FROM subject_enrollments se
         JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = se.student_id COLLATE utf8mb4_unicode_ci
         LEFT JOIN attendance a ON a.subject_id = se.subject_id
             AND a.student_id COLLATE utf8mb4_unicode_ci = se.student_id COLLATE utf8mb4_unicode_ci
             AND a.date = ?
         LEFT JOIN fingerprint_templates ft ON ft.student_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci
         WHERE se.subject_id = ?
         ORDER BY s.last_name ASC, s.first_name ASC"
    );
    $init_q->bind_param('si', $today, $subject_id);
    $init_q->execute();
    $init_students = $init_q->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($init_students);
  ?>;

  renderRoster(initStudents);

  function statusBadge(s) {
    if (s === 'Present') return '<span class="bio-badge bio-badge-green">Present</span>';
    if (s === 'Late')    return '<span class="bio-badge bio-badge-yellow">Late</span>';
    return '<span class="bio-badge bio-badge-gray">—</span>';
  }
  function sourceBadge(src) {
    if (!src) return '';
    return src === 'Biometric'
      ? '<span class="bio-badge bio-badge-blue"><i class="ti ti-fingerprint" style="font-size:9px;"></i> Bio</span>'
      : '<span class="bio-badge bio-badge-gray">Manual</span>';
  }
  function fpBadge(has) {
    return has
      ? '<span class="bio-badge bio-badge-green" style="font-size:9px;">✓</span>'
      : '<span class="bio-badge bio-badge-gray" style="font-size:9px;">✗</span>';
  }

  function renderRoster(students) {
    const tbody = document.getElementById('bioRosterBody');
    if (!tbody) return;
    const q = bioSearch.toLowerCase();
    let   n = 0;
    const rows = students.map((s, i) => {
      const name    = (s.last_name + ', ' + s.first_name).toLowerCase();
      const status  = s.att_status || '';
      const dispSt  = status === '' ? 'not yet' : status.toLowerCase();
      const visible = (bioFilter === 'all' || bioFilter === dispSt)
                   && (q === '' || name.includes(q));
      if (!visible) return `<tr data-name="${name}" data-status="${dispSt}" style="display:none;"></tr>`;
      n++;
      return `<tr data-name="${name}" data-status="${dispSt}">
        <td style="color:#3d4560;font-size:11px;">${i+1}</td>
        <td>
          <div style="font-weight:500;">${s.last_name}, ${s.first_name}</div>
          <div style="font-family:'DM Mono',monospace;font-size:10px;color:#3d4560;">${s.student_id}</div>
        </td>
        <td>${fpBadge(s.has_template)}</td>
        <td>${statusBadge(s.att_status)}</td>
        <td style="font-family:'DM Mono',monospace;font-size:11px;color:#7d8aaa;">
          ${s.time_in ? s.time_in.substring(0,5) : '—'}
        </td>
        <td>${sourceBadge(s.source)}</td>
      </tr>`;
    });
    tbody.innerHTML = rows.join('');
  }

  function updateCounts(counts) {
    const p = document.getElementById('statPresent');
    const l = document.getElementById('statLate');
    const a = document.getElementById('statAbsent');
    if (p) p.textContent = counts.present;
    if (l) l.textContent = counts.late;
    if (a) a.textContent = counts.absent;
  }

  function pollLive() {
    fetch(`?id=${SUBJECT_ID}&bio_ajax=live&date=${TODAY}`)
      .then(r => r.json())
      .then(d => {
        lastData = d;
        renderRoster(d.students);
        updateCounts(d.counts);
        const el = document.getElementById('bioLastUpdated');
        if (el) el.textContent = 'Updated ' + d.time;
      })
      .catch(() => {});
  }

  // Start polling
  setInterval(pollLive, POLL_MS);

  window.filterBioRoster = function() {
    bioSearch = document.getElementById('bioRosterSearch').value;
    if (lastData) renderRoster(lastData.students);
    else renderRoster(initStudents);
  };

  window.setBioFilter = function(f) {
    bioFilter = f;
    // Update button styles
    ['bioFilterAll','bioFilterPresent','bioFilterLate','bioFilterAbsent'].forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      const map = {bioFilterAll:'all',bioFilterPresent:'present',bioFilterLate:'late',bioFilterAbsent:'not yet'};
      const active = map[id] === f;
      el.style.background = active ? 'rgba(52,211,153,.12)' : 'transparent';
      el.style.color       = active ? '#34d399' : '#7d8aaa';
      el.style.borderColor = active ? 'rgba(52,211,153,.2)' : '#252a3d';
    });
    if (lastData) renderRoster(lastData.students);
    else renderRoster(initStudents);
  };
})();
</script>

<?php if ($standalone): ?>
</div><!-- max-width wrap -->
</body>
</html>
<?php endif; ?>
