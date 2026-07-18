<?php
session_start();
require '../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    header("Location: /classroom/login.php"); exit;
}

$success_msg = '';
$error_msg   = '';

// Shared grade recalc function (same as quizzes.php — move to includes/functions.php later)
function recalcGrades($conn, $student_id) {
    $q = $conn->prepare("SELECT AVG(score/total_items*100) as avg FROM quizzes WHERE student_id=?");
    $q->bind_param("s",$student_id); $q->execute();
    $quiz_avg = $q->get_result()->fetch_assoc()['avg'] ?? 0;

    $a = $conn->prepare("SELECT AVG(score/total_items*100) as avg FROM activities WHERE student_id=?");
    $a->bind_param("s",$student_id); $a->execute();
    $act_avg = $a->get_result()->fetch_assoc()['avg'] ?? 0;

    $at = $conn->prepare("SELECT COUNT(*) as total,
        SUM(CASE WHEN status='Present' THEN 1 WHEN status='Late' THEN 0.5 ELSE 0 END) as p
        FROM attendance WHERE student_id=?");
    $at->bind_param("s",$student_id); $at->execute();
    $att = $at->get_result()->fetch_assoc();
    $att_rate = ($att['total'] > 0) ? ($att['p'] / $att['total'] * 100) : 0;

    $final = ($quiz_avg*0.30)+($act_avg*0.40)+($att_rate*0.30);
    $letter = '5.00';
    if ($final>=97) $letter='1.00'; elseif ($final>=94) $letter='1.25';
    elseif ($final>=91) $letter='1.50'; elseif ($final>=88) $letter='1.75';
    elseif ($final>=85) $letter='2.00'; elseif ($final>=82) $letter='2.25';
    elseif ($final>=79) $letter='2.50'; elseif ($final>=76) $letter='2.75';
    elseif ($final>=75) $letter='3.00'; elseif ($final>=70) $letter='4.00';

    $u = $conn->prepare("INSERT INTO grades
        (student_id,quiz_avg,activity_avg,attendance_rate,final_grade,letter_grade)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            quiz_avg=VALUES(quiz_avg),activity_avg=VALUES(activity_avg),
            attendance_rate=VALUES(attendance_rate),
            final_grade=VALUES(final_grade),letter_grade=VALUES(letter_grade)");
    $u->bind_param("sdddds",$student_id,$quiz_avg,$act_avg,$att_rate,$final,$letter);
    $u->execute();
}

$activity_types = ['Lab Exercise','Seatwork','Assignment','Project','Performance Task'];

// ── SAVE activity ───────────────────────────────────────
if (isset($_POST['save_activity'])) {
    $student_id    = trim($_POST['student_id']);
    $activity_name = trim($_POST['activity_name']);
    $activity_type = trim($_POST['activity_type']);
    $score         = (float)$_POST['score'];
    $total_items   = (int)$_POST['total_items'];
    $date_given    = $_POST['date_given'];

    if ($score > $total_items) {
        $error_msg = "Score cannot be greater than total items.";
    } elseif (!in_array($activity_type, $activity_types)) {
        $error_msg = "Invalid activity type.";
    } else {
        $stmt = $conn->prepare("INSERT INTO activities
            (student_id,activity_name,activity_type,score,total_items,date_given)
            VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("sssids",$student_id,$activity_name,$activity_type,$score,$total_items,$date_given);
        $stmt->execute();
        recalcGrades($conn, $student_id);
        $success_msg = "Activity score saved and grades updated.";
    }
}

// ── DELETE activity ─────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $row = $conn->query("SELECT student_id FROM activities WHERE id=$id")->fetch_assoc();
    $conn->query("DELETE FROM activities WHERE id=$id");
    if ($row) recalcGrades($conn, $row['student_id']);
    header("Location: activities.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $success_msg = "Activity record deleted and grades updated.";

// ── FETCH ───────────────────────────────────────────────
$students = $conn->query("SELECT student_id,last_name,first_name FROM students ORDER BY last_name");

$filter_student = $_GET['filter'] ?? '';
$filter_type    = $_GET['type']   ?? '';

$where = []; $params = []; $types = '';
if ($filter_student !== '') { $where[] = "a.student_id=?"; $params[] = $filter_student; $types .= 's'; }
if ($filter_type    !== '') { $where[] = "a.activity_type=?"; $params[] = $filter_type;    $types .= 's'; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

if ($params) {
    $stmt = $conn->prepare("SELECT a.*, s.last_name, s.first_name
        FROM activities a JOIN students s USING(student_id)
        $where_sql ORDER BY a.date_given DESC, s.last_name ASC");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $records = $stmt->get_result();
} else {
    $records = $conn->query("SELECT a.*, s.last_name, s.first_name
        FROM activities a JOIN students s USING(student_id)
        ORDER BY a.date_given DESC, s.last_name ASC");
}

$total_records = $conn->query("SELECT COUNT(*) as c FROM activities")->fetch_assoc()['c'];
$avg_score     = $conn->query("SELECT AVG(score/total_items*100) as a FROM activities")->fetch_assoc()['a'] ?? 0;

$page_title = "Activity Scores";
$active_nav = "activities";
include '../includes/header.php';
?>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-clipboard-list" style="color:var(--accent)"></i> Activity Score Management</h1>
    <p>Record lab exercises, seatwork, assignments, and other activities. Grades update automatically.</p>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Records</div>
      <div class="stat-value"><?php echo $total_records; ?></div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Class Activity Average</div>
      <div class="stat-value"><?php echo number_format($avg_score,1); ?>%</div>
    </div>
    <?php foreach ($activity_types as $type):
      $count = $conn->query("SELECT COUNT(*) as c FROM activities WHERE activity_type='$type'")->fetch_assoc()['c'];
    ?>
    <div class="stat-card">
      <div class="stat-label"><?php echo $type; ?></div>
      <div class="stat-value"><?php echo $count; ?></div>
      <div class="stat-sub">entries</div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="two-col">

    <!-- ── FORM ── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-plus"></i> Add Activity Score</p>
      <form method="POST">

        <div class="form-group">
          <label>Student</label>
          <select name="student_id" class="form-control" required>
            <option value="">— Select student —</option>
            <?php $students->data_seek(0); while ($s = $students->fetch_assoc()): ?>
              <option value="<?php echo htmlspecialchars($s['student_id']); ?>">
                <?php echo htmlspecialchars($s['last_name'].', '.$s['first_name'].' ('.$s['student_id'].')'); ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Activity Name</label>
          <input type="text" name="activity_name" class="form-control"
            placeholder="e.g. Lab Exercise 1, Seatwork 3" required>
        </div>

        <div class="form-group">
          <label>Activity Type</label>
          <select name="activity_type" class="form-control" required>
            <option value="">— Select type —</option>
            <?php foreach ($activity_types as $type): ?>
              <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Score</label>
            <input type="number" step="0.01" name="score" class="form-control"
              placeholder="e.g. 45" min="0" required>
          </div>
          <div class="form-group">
            <label>Total Items</label>
            <input type="number" name="total_items" class="form-control"
              placeholder="e.g. 50" min="1" required>
          </div>
        </div>

        <div class="form-group">
          <label>Date Given</label>
          <input type="date" name="date_given" class="form-control"
            value="<?php echo date('Y-m-d'); ?>" required>
        </div>

        <button type="submit" name="save_activity" class="btn btn-primary">
          <i class="ti ti-device-floppy"></i> Save Activity Score
        </button>
      </form>
    </div>

    <!-- ── RECORDS ── -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
        <p class="card-title" style="margin:0"><i class="ti ti-list"></i> Activity Records</p>
        <form method="GET" style="display:flex;gap:6px;flex-wrap:wrap;">
          <select name="filter" class="form-control" style="width:auto;padding:6px 10px;font-size:12px;">
            <option value="">All students</option>
            <?php $students->data_seek(0); while ($s = $students->fetch_assoc()): ?>
              <option value="<?php echo htmlspecialchars($s['student_id']); ?>"
                <?php echo $filter_student===$s['student_id']?'selected':''; ?>>
                <?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?>
              </option>
            <?php endwhile; ?>
          </select>
          <select name="type" class="form-control" style="width:auto;padding:6px 10px;font-size:12px;">
            <option value="">All types</option>
            <?php foreach ($activity_types as $type): ?>
              <option value="<?php echo $type; ?>" <?php echo $filter_type===$type?'selected':''; ?>>
                <?php echo $type; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline btn-sm">Filter</button>
          <?php if ($filter_student || $filter_type): ?>
            <a href="activities.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i></a>
          <?php endif; ?>
        </form>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Student</th>
              <th>Activity</th>
              <th>Type</th>
              <th>Score</th>
              <th>Pct.</th>
              <th>Date</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($records->num_rows === 0): ?>
            <tr><td colspan="7">
              <div class="empty-state">
                <i class="ti ti-clipboard-off"></i>
                <p>No activity records yet.</p>
              </div>
            </td></tr>
            <?php endif; ?>
            <?php while ($r = $records->fetch_assoc()):
              $pct = ($r['total_items']>0) ? ($r['score']/$r['total_items']*100) : 0;
              $pct_class = $pct>=75?'badge-green':($pct>=60?'badge-yellow':'badge-red');
              $type_colors = [
                'Lab Exercise'=>'badge-blue','Seatwork'=>'badge-yellow',
                'Assignment'=>'badge-green','Project'=>'badge-blue',
                'Performance Task'=>'badge-yellow'
              ];
              $tc = $type_colors[$r['activity_type']] ?? 'badge-blue';
            ?>
            <tr>
              <td>
                <div style="font-weight:500;font-size:13px;"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
                <div class="td-mono" style="font-size:11px;"><?php echo htmlspecialchars($r['student_id']); ?></div>
              </td>
              <td style="font-size:13px;"><?php echo htmlspecialchars($r['activity_name']); ?></td>
              <td><span class="badge <?php echo $tc; ?>"><?php echo htmlspecialchars($r['activity_type']); ?></span></td>
              <td class="td-mono"><?php echo $r['score'].'/'.$r['total_items']; ?></td>
              <td>
                <div class="score-bar-wrap">
                  <div class="score-bar-track">
                    <div class="score-bar-fill" style="width:<?php echo min($pct,100); ?>%;background:<?php echo $pct>=75?'var(--green)':($pct>=60?'var(--yellow)':'var(--red)'); ?>"></div>
                  </div>
                  <span class="badge <?php echo $pct_class; ?>" style="min-width:48px;justify-content:center;">
                    <?php echo number_format($pct,1); ?>%
                  </span>
                </div>
              </td>
              <td style="color:var(--text2);font-size:12px;"><?php echo date('M d, Y',strtotime($r['date_given'])); ?></td>
              <td>
                <a href="activities.php?delete=<?php echo $r['id']; ?>"
                   class="btn btn-sm btn-delete"
                   onclick="return confirm('Delete this activity record?')">
                  <i class="ti ti-trash"></i>
                </a>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<?php include '../includes/footer.php'; ?>
