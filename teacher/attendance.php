<?php
// ============================================================
//  teacher/attendance.php
//  Location: classroom/teacher/attendance.php
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

$success_msg = '';
$error_msg   = '';

// ── SAVE bulk attendance ────────────────────────────────────
if (isset($_POST['save_attendance'])) {
    $date     = $_POST['date'];
    $statuses = $_POST['status'] ?? []; // ['STU-001' => 'Present', ...]

    if (empty($statuses)) {
        $error_msg = "No students found to save.";
    } elseif (empty($date)) {
        $error_msg = "Please select a date.";
    } else {
        $saved = 0;
        $skipped = 0;

        foreach ($statuses as $student_id => $status) {
            $student_id = $conn->real_escape_string($student_id);
            $remarks    = trim($_POST['remarks'][$student_id] ?? '');
            $time_in    = ($status === 'Absent') ? null : $_POST['time_in'][$student_id] ?? null;

            // Check for duplicate (same student, same date)
            $check = $conn->prepare(
                "SELECT id FROM attendance WHERE student_id = ? AND date = ?"
            );
            $check->bind_param("ss", $student_id, $date);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                // UPDATE existing record
                $upd = $conn->prepare(
                    "UPDATE attendance SET status=?, time_in=?, remarks=?
                     WHERE student_id=? AND date=?"
                );
                $upd->bind_param("sssss", $status, $time_in, $remarks, $student_id, $date);
                $upd->execute();
            } else {
                // INSERT new record
                $ins = $conn->prepare(
                    "INSERT INTO attendance (student_id, date, time_in, status, remarks)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $ins->bind_param("sssss", $student_id, $date, $time_in, $status, $remarks);
                $ins->execute();
                $saved++;
            }

            // Recalculate this student's grade
            recalcGrades($conn, $student_id);
        }

        $success_msg = "Attendance saved for <strong>{$date}</strong>. "
                     . "{$saved} new record(s) added. Grades updated.";
    }
}

// ── FETCH date to display ───────────────────────────────────
$view_date = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');

// ── FETCH all students ──────────────────────────────────────
$students = $conn->query(
    "SELECT student_id, last_name, first_name, section FROM students ORDER BY last_name ASC"
);

// ── FETCH existing attendance for this date (for pre-fill) ──
$existing = [];
$stmt = $conn->prepare(
    "SELECT student_id, status, time_in, remarks FROM attendance WHERE date = ?"
);
$stmt->bind_param("s", $view_date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $existing[$row['student_id']] = $row;
}

// ── FETCH attendance summary per student ───────────────────
$summary = [];
$sum_res = $conn->query(
    "SELECT student_id,
        COUNT(*) AS total,
        SUM(status='Present') AS present,
        SUM(status='Absent')  AS absent,
        SUM(status='Late')    AS late
     FROM attendance GROUP BY student_id"
);
while ($row = $sum_res->fetch_assoc()) {
    $summary[$row['student_id']] = $row;
}

// ── Count how many dates have records ──────────────────────
$total_days = $conn->query(
    "SELECT COUNT(DISTINCT date) as c FROM attendance"
)->fetch_assoc()['c'] ?? 0;

$page_title = "Attendance";
$active_nav = "attendance";
include '../includes/header.php';
?>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-calendar-check" style="color:var(--accent)"></i> Attendance Management</h1>
    <p>Mark attendance for the whole class at once. Grades recalculate automatically after saving.</p>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Class Days Recorded</div>
      <div class="stat-value"><?php echo $total_days; ?></div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Students</div>
      <div class="stat-value"><?php echo $students->num_rows; ?></div>
    </div>
    <?php
    $today_present = $conn->query(
        "SELECT COUNT(*) as c FROM attendance WHERE date=CURDATE() AND status='Present'"
    )->fetch_assoc()['c'] ?? 0;
    $today_absent = $conn->query(
        "SELECT COUNT(*) as c FROM attendance WHERE date=CURDATE() AND status='Absent'"
    )->fetch_assoc()['c'] ?? 0;
    ?>
    <div class="stat-card stat-green">
      <div class="stat-label">Present Today</div>
      <div class="stat-value"><?php echo $today_present; ?></div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-label">Absent Today</div>
      <div class="stat-value"><?php echo $today_absent; ?></div>
    </div>
  </div>

  <!-- Date picker -->
  <div class="card" style="margin-bottom:24px;">
    <form method="GET" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
      <div class="form-group" style="margin:0;flex:1;min-width:200px;">
        <label>Select Date to Mark Attendance</label>
        <input type="date" name="date" class="form-control"
          value="<?php echo htmlspecialchars($view_date); ?>"
          max="<?php echo date('Y-m-d'); ?>">
      </div>
      <button type="submit" class="btn btn-outline" style="margin-bottom:0;">
        <i class="ti ti-calendar"></i> Load Date
      </button>
    </form>
  </div>

  <!-- Bulk attendance form -->
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
      <p class="card-title" style="margin:0;">
        <i class="ti ti-list-check"></i>
        Attendance for
        <span style="color:var(--text);font-size:14px;">
          <?php echo date('F d, Y', strtotime($view_date)); ?>
        </span>
        <?php if (!empty($existing)): ?>
          <span class="badge badge-yellow" style="margin-left:8px;">
            <i class="ti ti-edit"></i> Updating existing record
          </span>
        <?php endif; ?>
      </p>

      <!-- Quick mark all buttons -->
      <div style="display:flex;gap:6px;">
        <button type="button" class="btn btn-sm btn-outline" onclick="markAll('Present')">
          <i class="ti ti-check"></i> All Present
        </button>
        <button type="button" class="btn btn-sm btn-outline" onclick="markAll('Absent')">
          <i class="ti ti-x"></i> All Absent
        </button>
      </div>
    </div>

    <form method="POST">
      <input type="hidden" name="date" value="<?php echo htmlspecialchars($view_date); ?>">

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Student</th>
              <th>Section</th>
              <th style="text-align:center;">Present</th>
              <th style="text-align:center;">Late</th>
              <th style="text-align:center;">Absent</th>
              <th>Time In</th>
              <th>Remarks</th>
              <th>Overall</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $students->data_seek(0);
            if ($students->num_rows === 0):
            ?>
            <tr><td colspan="8">
              <div class="empty-state">
                <i class="ti ti-users-off"></i>
                <p>No students enrolled. Add students first.</p>
              </div>
            </td></tr>
            <?php endif; ?>

            <?php while ($s = $students->fetch_assoc()):
              $sid      = $s['student_id'];
              $existing_status  = $existing[$sid]['status']  ?? 'Present';
              $existing_remarks = $existing[$sid]['remarks'] ?? '';
              $existing_time    = $existing[$sid]['time_in'] ?? '';
              $sum = $summary[$sid] ?? ['total'=>0,'present'=>0,'absent'=>0,'late'=>0];
              $att_pct = ($sum['total'] > 0)
                ? round(($sum['present'] + $sum['late']*0.5) / $sum['total'] * 100, 1)
                : 0;
            ?>
            <tr id="row-<?php echo htmlspecialchars($sid); ?>">
              <td>
                <div style="font-weight:500;"><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?></div>
                <div class="td-mono" style="font-size:11px;"><?php echo htmlspecialchars($sid); ?></div>
              </td>
              <td><span class="badge badge-blue"><?php echo htmlspecialchars($s['section']); ?></span></td>

              <!-- Present radio -->
              <td style="text-align:center;">
                <input type="radio"
                  name="status[<?php echo htmlspecialchars($sid); ?>]"
                  value="Present"
                  class="status-radio"
                  data-sid="<?php echo htmlspecialchars($sid); ?>"
                  <?php echo $existing_status === 'Present' ? 'checked' : ''; ?>
                  style="width:18px;height:18px;cursor:pointer;accent-color:var(--green);">
              </td>
              <!-- Late radio -->
              <td style="text-align:center;">
                <input type="radio"
                  name="status[<?php echo htmlspecialchars($sid); ?>]"
                  value="Late"
                  class="status-radio"
                  data-sid="<?php echo htmlspecialchars($sid); ?>"
                  <?php echo $existing_status === 'Late' ? 'checked' : ''; ?>
                  style="width:18px;height:18px;cursor:pointer;accent-color:var(--yellow);">
              </td>
              <!-- Absent radio -->
              <td style="text-align:center;">
                <input type="radio"
                  name="status[<?php echo htmlspecialchars($sid); ?>]"
                  value="Absent"
                  class="status-radio"
                  data-sid="<?php echo htmlspecialchars($sid); ?>"
                  <?php echo $existing_status === 'Absent' ? 'checked' : ''; ?>
                  style="width:18px;height:18px;cursor:pointer;accent-color:var(--red);">
              </td>

              <!-- Time in -->
              <td>
                <input type="time"
                  name="time_in[<?php echo htmlspecialchars($sid); ?>]"
                  id="time_<?php echo htmlspecialchars($sid); ?>"
                  class="form-control"
                  style="width:120px;padding:5px 8px;font-size:12px;"
                  value="<?php echo htmlspecialchars($existing_time); ?>"
                  <?php echo $existing_status === 'Absent' ? 'disabled' : ''; ?>>
              </td>

              <!-- Remarks -->
              <td>
                <input type="text"
                  name="remarks[<?php echo htmlspecialchars($sid); ?>]"
                  class="form-control"
                  style="width:130px;padding:5px 8px;font-size:12px;"
                  placeholder="optional"
                  value="<?php echo htmlspecialchars($existing_remarks); ?>">
              </td>

              <!-- Overall attendance rate -->
              <td>
                <?php if ($sum['total'] > 0): ?>
                <div class="score-bar-wrap">
                  <div class="score-bar-track">
                    <div class="score-bar-fill"
                      style="width:<?php echo $att_pct; ?>%;
                             background:<?php echo $att_pct>=75?'var(--green)':($att_pct>=50?'var(--yellow)':'var(--red)'); ?>">
                    </div>
                  </div>
                  <span style="font-size:11px;color:var(--text2);min-width:36px;"><?php echo $att_pct; ?>%</span>
                </div>
                <div style="font-size:10px;color:var(--text3);margin-top:2px;">
                  <?php echo $sum['present']; ?>P · <?php echo $sum['late']; ?>L · <?php echo $sum['absent']; ?>A
                </div>
                <?php else: ?>
                  <span style="font-size:11px;color:var(--text3);">No records</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <p style="font-size:12px;color:var(--text2);">
          <i class="ti ti-info-circle" style="color:var(--accent)"></i>
          Saving will recalculate attendance rates and final grades for all listed students.
          Late = 0.5 points toward attendance rate.
        </p>
        <button type="submit" name="save_attendance" class="btn btn-primary" style="width:auto;padding:10px 28px;">
          <i class="ti ti-device-floppy"></i> Save Attendance
        </button>
      </div>
    </form>
  </div>

  <!-- History table -->
  <div class="card" style="margin-top:24px;">
    <p class="card-title"><i class="ti ti-history"></i> Recent Attendance Dates</p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Present</th>
            <th>Late</th>
            <th>Absent</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $hist = $conn->query(
              "SELECT date,
                  SUM(status='Present') AS present,
                  SUM(status='Late')    AS late,
                  SUM(status='Absent')  AS absent,
                  COUNT(*)              AS total
               FROM attendance
               GROUP BY date
               ORDER BY date DESC
               LIMIT 20"
          );
          if ($hist->num_rows === 0):
          ?>
          <tr><td colspan="6">
            <div class="empty-state">
              <i class="ti ti-calendar-off"></i>
              <p>No attendance records yet.</p>
            </div>
          </td></tr>
          <?php endif; ?>
          <?php while ($h = $hist->fetch_assoc()): ?>
          <tr>
            <td style="font-weight:500;"><?php echo date('M d, Y', strtotime($h['date'])); ?></td>
            <td><span class="badge badge-green"><?php echo $h['present']; ?> Present</span></td>
            <td><span class="badge badge-yellow"><?php echo $h['late']; ?> Late</span></td>
            <td><span class="badge badge-red"><?php echo $h['absent']; ?> Absent</span></td>
            <td style="color:var(--text2);"><?php echo $h['total']; ?> students</td>
            <td>
              <a href="attendance.php?date=<?php echo $h['date']; ?>"
                 class="btn btn-sm btn-edit">
                <i class="ti ti-edit"></i> Edit
              </a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
// Mark all students with one status
function markAll(status) {
  document.querySelectorAll('.status-radio').forEach(radio => {
    if (radio.value === status) {
      radio.checked = true;
      updateTimeIn(radio.dataset.sid, status);
    }
  });
}

// Disable time_in when Absent is selected
document.querySelectorAll('.status-radio').forEach(radio => {
  radio.addEventListener('change', function () {
    updateTimeIn(this.dataset.sid, this.value);
  });
});

function updateTimeIn(sid, status) {
  const timeInput = document.getElementById('time_' + sid);
  if (!timeInput) return;
  if (status === 'Absent') {
    timeInput.disabled = true;
    timeInput.value = '';
  } else {
    timeInput.disabled = false;
    // Auto-fill current time if empty
    if (!timeInput.value) {
      const now = new Date();
      timeInput.value = now.toTimeString().slice(0, 5);
    }
  }
}
</script>

<?php include '../includes/footer.php'; ?>