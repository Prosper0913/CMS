<?php
session_start();
require '../config/db.php';

// ── Auth guard ──────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    header("Location: /classroom/login.php"); exit;
}

$success_msg = '';
$error_msg   = '';

// ── Helper: recalculate a student's quiz avg + final grade ──
function recalcGrades($conn, $student_id) {
    // Quiz average
    $stmt = $conn->prepare("SELECT AVG(score/total_items*100) as avg FROM quizzes WHERE student_id=?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $quiz_avg = $stmt->get_result()->fetch_assoc()['avg'] ?? 0;

    // Activity average
    $stmt2 = $conn->prepare("SELECT AVG(score/total_items*100) as avg FROM activities WHERE student_id=?");
    $stmt2->bind_param("s", $student_id);
    $stmt2->execute();
    $act_avg = $stmt2->get_result()->fetch_assoc()['avg'] ?? 0;

    // Attendance rate
    $stmt3 = $conn->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status='Present' THEN 1 WHEN status='Late' THEN 0.5 ELSE 0 END) as present_count
        FROM attendance WHERE student_id=?");
    $stmt3->bind_param("s", $student_id);
    $stmt3->execute();
    $att = $stmt3->get_result()->fetch_assoc();
    $att_rate = ($att['total'] > 0) ? ($att['present_count'] / $att['total'] * 100) : 0;

    // Final grade
    $final = ($quiz_avg * 0.30) + ($act_avg * 0.40) + ($att_rate * 0.30);

    // Letter grade
    $letter = '5.00';
    if ($final >= 97)      $letter = '1.00';
    elseif ($final >= 94)  $letter = '1.25';
    elseif ($final >= 91)  $letter = '1.50';
    elseif ($final >= 88)  $letter = '1.75';
    elseif ($final >= 85)  $letter = '2.00';
    elseif ($final >= 82)  $letter = '2.25';
    elseif ($final >= 79)  $letter = '2.50';
    elseif ($final >= 76)  $letter = '2.75';
    elseif ($final >= 75)  $letter = '3.00';
    elseif ($final >= 70)  $letter = '4.00';

    $upd = $conn->prepare("INSERT INTO grades (student_id,quiz_avg,activity_avg,attendance_rate,final_grade,letter_grade)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            quiz_avg=VALUES(quiz_avg),
            activity_avg=VALUES(activity_avg),
            attendance_rate=VALUES(attendance_rate),
            final_grade=VALUES(final_grade),
            letter_grade=VALUES(letter_grade)");
    $upd->bind_param("sdddds", $student_id,$quiz_avg,$act_avg,$att_rate,$final,$letter);
    $upd->execute();
}

// ── SAVE quiz ───────────────────────────────────────────
if (isset($_POST['save_quiz'])) {
    $student_id  = trim($_POST['student_id']);
    $quiz_num    = (int)$_POST['quiz_num'];
    $score       = (float)$_POST['score'];
    $total_items = (int)$_POST['total_items'];
    $date_given  = $_POST['date_given'];

    if ($score > $total_items) {
        $error_msg = "Score cannot be greater than total items.";
    } else {
        // Check for duplicate quiz entry for same student + quiz number
        $check = $conn->prepare("SELECT id FROM quizzes WHERE student_id=? AND quiz_num=?");
        $check->bind_param("si", $student_id, $quiz_num);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error_msg = "Quiz {$quiz_num} score for this student already exists. Delete the existing record first.";
        } else {
            $stmt = $conn->prepare("INSERT INTO quizzes
                (student_id,quiz_num,score,total_items,date_given)
                VALUES (?,?,?,?,?)");
            $stmt->bind_param("sidis", $student_id,$quiz_num,$score,$total_items,$date_given);
            $stmt->execute();
            recalcGrades($conn, $student_id);
            $success_msg = "Quiz score saved and grades updated.";
        }
    }
}

// ── DELETE quiz record ──────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $row = $conn->query("SELECT student_id FROM quizzes WHERE id=$id")->fetch_assoc();
    $conn->query("DELETE FROM quizzes WHERE id=$id");
    if ($row) recalcGrades($conn, $row['student_id']);
    header("Location: quizzes.php?deleted=1"); exit;
}
if (isset($_GET['deleted'])) $success_msg = "Quiz record deleted and grades updated.";

// ── FETCH students for dropdown ─────────────────────────
$students = $conn->query("SELECT student_id,last_name,first_name FROM students ORDER BY last_name");

// ── FETCH quiz records ──────────────────────────────────
$filter_student = $_GET['filter'] ?? '';
if ($filter_student !== '') {
    $stmt = $conn->prepare("SELECT q.*, s.last_name, s.first_name
        FROM quizzes q JOIN students s USING(student_id)
        WHERE q.student_id=?
        ORDER BY q.quiz_num ASC, s.last_name ASC");
    $stmt->bind_param("s", $filter_student);
    $stmt->execute();
    $records = $stmt->get_result();
} else {
    $records = $conn->query("SELECT q.*, s.last_name, s.first_name
        FROM quizzes q JOIN students s USING(student_id)
        ORDER BY q.quiz_num ASC, s.last_name ASC");
}

$total_records = $conn->query("SELECT COUNT(*) as c FROM quizzes")->fetch_assoc()['c'];
$avg_score     = $conn->query("SELECT AVG(score/total_items*100) as a FROM quizzes")->fetch_assoc()['a'] ?? 0;

$page_title = "Quiz Scores";
$active_nav = "quizzes";
include '../includes/header.php';
?>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-pencil" style="color:var(--accent)"></i> Quiz Score Management</h1>
    <p>Record quiz scores per student. Grades are recalculated automatically after every save.</p>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?php echo $error_msg; ?></div>
  <?php endif; ?>

  <!-- stats -->
  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Records</div>
      <div class="stat-value"><?php echo $total_records; ?></div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Class Quiz Average</div>
      <div class="stat-value"><?php echo number_format($avg_score, 1); ?>%</div>
    </div>
  </div>

  <div class="two-col">

    <!-- ── FORM ── -->
    <div class="card">
      <p class="card-title"><i class="ti ti-plus"></i> Add Quiz Score</p>
      <form method="POST">

        <div class="form-group">
          <label>Student</label>
          <select name="student_id" class="form-control" required>
            <option value="">— Select student —</option>
            <?php
            $students->data_seek(0);
            while ($s = $students->fetch_assoc()):
            ?>
              <option value="<?php echo htmlspecialchars($s['student_id']); ?>">
                <?php echo htmlspecialchars($s['last_name'].', '.$s['first_name'].' ('.$s['student_id'].')'); ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Quiz Number</label>
            <input type="number" name="quiz_num" class="form-control"
              placeholder="e.g. 1" min="1" required>
          </div>
          <div class="form-group">
            <label>Date Given</label>
            <input type="date" name="date_given" class="form-control"
              value="<?php echo date('Y-m-d'); ?>" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Score</label>
            <input type="number" step="0.01" name="score" class="form-control"
              placeholder="e.g. 18" min="0" required>
          </div>
          <div class="form-group">
            <label>Total Items</label>
            <input type="number" name="total_items" class="form-control"
              placeholder="e.g. 20" min="1" required>
          </div>
        </div>

        <button type="submit" name="save_quiz" class="btn btn-primary">
          <i class="ti ti-device-floppy"></i> Save Quiz Score
        </button>
      </form>

      <hr class="divider">
      <p style="font-size:12px;color:var(--text2);">
        <i class="ti ti-info-circle" style="color:var(--accent)"></i>
        Saving a score automatically recalculates the student's quiz average and final grade using the formula:
        <strong style="color:var(--text)">Final = Quiz(30%) + Activity(40%) + Attendance(30%)</strong>
      </p>
    </div>

    <!-- ── RECORDS TABLE ── -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <p class="card-title" style="margin:0"><i class="ti ti-list"></i> Quiz Records</p>
        <form method="GET" style="display:flex;gap:6px;">
          <select name="filter" class="form-control" style="width:auto;padding:6px 10px;font-size:12px;">
            <option value="">All students</option>
            <?php
            $students->data_seek(0);
            while ($s = $students->fetch_assoc()):
            ?>
              <option value="<?php echo htmlspecialchars($s['student_id']); ?>"
                <?php echo $filter_student === $s['student_id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?>
              </option>
            <?php endwhile; ?>
          </select>
          <button type="submit" class="btn btn-outline btn-sm">Filter</button>
          <?php if ($filter_student): ?>
            <a href="quizzes.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i></a>
          <?php endif; ?>
        </form>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Student</th>
              <th>Quiz #</th>
              <th>Score</th>
              <th>Percentage</th>
              <th>Date</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($records->num_rows === 0): ?>
            <tr><td colspan="6">
              <div class="empty-state">
                <i class="ti ti-pencil-off"></i>
                <p>No quiz records yet.</p>
              </div>
            </td></tr>
            <?php endif; ?>
            <?php while ($r = $records->fetch_assoc()):
              $pct = ($r['total_items'] > 0) ? ($r['score'] / $r['total_items'] * 100) : 0;
              $pct_class = $pct >= 75 ? 'badge-green' : ($pct >= 60 ? 'badge-yellow' : 'badge-red');
            ?>
            <tr>
              <td>
                <div style="font-weight:500;font-size:13px;"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
                <div class="td-mono" style="font-size:11px;"><?php echo htmlspecialchars($r['student_id']); ?></div>
              </td>
              <td><span class="badge badge-blue">Quiz <?php echo $r['quiz_num']; ?></span></td>
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
              <td style="color:var(--text2);font-size:12px;"><?php echo date('M d, Y', strtotime($r['date_given'])); ?></td>
              <td>
                <a href="quizzes.php?delete=<?php echo $r['id']; ?>"
                   class="btn btn-sm btn-delete"
                   onclick="return confirm('Delete this quiz record? The grade will be recalculated.')">
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
