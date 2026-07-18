<?php
// ============================================================
//  teacher/grades.php
//  Shows all students' computed grades. Has a "Recompute All"
//  button and a CSV export. All grade math lives in db.php
//  inside recalcGrades() — this page only reads and displays.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';

$success_msg = '';

// ── RECOMPUTE ALL grades ────────────────────────────────────
// Loops every student and recalculates from scratch.
if (isset($_POST['recompute_all'])) {
    $all = $conn->query("SELECT student_id FROM students");
    while ($row = $all->fetch_assoc()) {
        recalcGrades($conn, $row['student_id']);
    }
    $success_msg = "All grades recomputed successfully.";
}

// ── CSV EXPORT ──────────────────────────────────────────────
// Sends a downloadable .csv file to the browser.
if (isset($_GET['export'])) {
    $rows = $conn->query(
        "SELECT s.student_id, s.last_name, s.first_name, s.section,
                COALESCE(g.quiz_avg,0)        AS quiz_avg,
                COALESCE(g.activity_avg,0)    AS activity_avg,
                COALESCE(g.attendance_rate,0) AS attendance_rate,
                COALESCE(g.final_grade,0)     AS final_grade,
                COALESCE(g.letter_grade,'N/A') AS letter_grade
         FROM students s
         LEFT JOIN grades g USING(student_id)
         ORDER BY s.last_name ASC"
    );

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grades_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID','Last Name','First Name','Section',
                   'Quiz Avg (%)','Activity Avg (%)','Attendance (%)','Final Grade (%)','Letter Grade']);
    while ($r = $rows->fetch_assoc()) {
        fputcsv($out, [
            $r['student_id'], $r['last_name'], $r['first_name'], $r['section'],
            round($r['quiz_avg'],2), round($r['activity_avg'],2),
            round($r['attendance_rate'],2), round($r['final_grade'],2),
            $r['letter_grade']
        ]);
    }
    fclose($out);
    exit;
}

// ── FETCH all grades ────────────────────────────────────────
$filter_section = trim($_GET['section'] ?? '');
$sort           = $_GET['sort'] ?? 'last_name';
$allowed_sorts  = ['last_name','final_grade','quiz_avg','activity_avg','attendance_rate'];
if (!in_array($sort, $allowed_sorts)) $sort = 'last_name';
$dir = ($sort === 'last_name') ? 'ASC' : 'DESC';

$where = $filter_section ? "WHERE s.section = '" . $conn->real_escape_string($filter_section) . "'" : '';

$grades = $conn->query(
    "SELECT s.student_id, s.last_name, s.first_name, s.section,
            COALESCE(g.quiz_avg,0)        AS quiz_avg,
            COALESCE(g.activity_avg,0)    AS activity_avg,
            COALESCE(g.attendance_rate,0) AS attendance_rate,
            COALESCE(g.final_grade,0)     AS final_grade,
            COALESCE(g.letter_grade,'N/A') AS letter_grade,
            COALESCE(g.updated_at, s.created_at) AS updated_at
     FROM students s
     LEFT JOIN grades g USING(student_id)
     $where
     ORDER BY s.$sort $dir"
);

// Class stats
$stats = $conn->query(
    "SELECT
        COUNT(*)                                      AS total,
        ROUND(AVG(g.final_grade),2)                   AS class_avg,
        ROUND(MAX(g.final_grade),2)                   AS highest,
        ROUND(MIN(g.final_grade),2)                   AS lowest,
        SUM(g.final_grade >= 75)                      AS passing,
        SUM(g.final_grade < 75 OR g.final_grade IS NULL) AS failing
     FROM students s LEFT JOIN grades g USING(student_id) $where"
)->fetch_assoc();

// Sections dropdown
$sections = $conn->query("SELECT DISTINCT section FROM students ORDER BY section");

$page_title = "Grades";
$active_nav = "grades";
include '../includes/header.php';

// Helper — badge colour per letter grade
function gradeBadge($letter) {
    $map = [
        '1.00'=>'badge-green','1.25'=>'badge-green','1.50'=>'badge-green',
        '1.75'=>'badge-green','2.00'=>'badge-green','2.25'=>'badge-blue',
        '2.50'=>'badge-blue', '2.75'=>'badge-blue', '3.00'=>'badge-yellow',
        '4.00'=>'badge-yellow','5.00'=>'badge-red',
    ];
    return $map[$letter] ?? 'badge-blue';
}
?>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-chart-bar" style="color:var(--accent)"></i> Grade Summary</h1>
    <p>All grades are computed automatically from quiz, activity, and attendance records.</p>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="ti ti-circle-check"></i> <?php echo $success_msg; ?></div>
  <?php endif; ?>

  <!-- Class stats cards -->
  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Total Students</div>
      <div class="stat-value"><?php echo $stats['total']; ?></div>
    </div>
    <div class="stat-card stat-accent">
      <div class="stat-label">Class Average</div>
      <div class="stat-value"><?php echo number_format($stats['class_avg'] ?? 0, 1); ?>%</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Passing</div>
      <div class="stat-value"><?php echo $stats['passing']; ?></div>
      <div class="stat-sub">grade ≥ 75</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-label">Failing / No Grade</div>
      <div class="stat-value"><?php echo $stats['failing']; ?></div>
      <div class="stat-sub">grade &lt; 75</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-label">Highest Grade</div>
      <div class="stat-value"><?php echo number_format($stats['highest'] ?? 0, 1); ?>%</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-label">Lowest Grade</div>
      <div class="stat-value"><?php echo number_format($stats['lowest'] ?? 0, 1); ?>%</div>
    </div>
  </div>

  <div class="card">
    <!-- Toolbar -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
      <p class="card-title" style="margin:0;"><i class="ti ti-list"></i> All Students</p>

      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">

        <!-- Section filter -->
        <form method="GET" style="display:flex;gap:6px;">
          <select name="section" class="form-control" style="width:auto;padding:6px 10px;font-size:12px;">
            <option value="">All Sections</option>
            <?php while ($sec = $sections->fetch_assoc()): ?>
              <option value="<?php echo htmlspecialchars($sec['section']); ?>"
                <?php echo $filter_section===$sec['section']?'selected':''; ?>>
                <?php echo htmlspecialchars($sec['section']); ?>
              </option>
            <?php endwhile; ?>
          </select>
          <select name="sort" class="form-control" style="width:auto;padding:6px 10px;font-size:12px;">
            <option value="last_name"       <?php echo $sort==='last_name'?'selected':''; ?>>Sort: Name</option>
            <option value="final_grade"     <?php echo $sort==='final_grade'?'selected':''; ?>>Sort: Final Grade</option>
            <option value="quiz_avg"        <?php echo $sort==='quiz_avg'?'selected':''; ?>>Sort: Quiz Avg</option>
            <option value="activity_avg"    <?php echo $sort==='activity_avg'?'selected':''; ?>>Sort: Activity Avg</option>
            <option value="attendance_rate" <?php echo $sort==='attendance_rate'?'selected':''; ?>>Sort: Attendance</option>
          </select>
          <button type="submit" class="btn btn-outline btn-sm">Apply</button>
        </form>

        <!-- Actions -->
        <form method="POST" style="display:inline;">
          <button type="submit" name="recompute_all"
            class="btn btn-outline btn-sm"
            onclick="return confirm('Recompute all grades from raw scores?')">
            <i class="ti ti-refresh"></i> Recompute All
          </button>
        </form>

        <a href="grades.php?export=1<?php echo $filter_section?'&section='.urlencode($filter_section):''; ?>"
           class="btn btn-outline btn-sm">
          <i class="ti ti-file-spreadsheet"></i> Export CSV
        </a>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Section</th>
            <th title="30% weight">Quiz Avg <span style="color:var(--text3);font-weight:400;">(30%)</span></th>
            <th title="40% weight">Activity Avg <span style="color:var(--text3);font-weight:400;">(40%)</span></th>
            <th title="30% weight">Attendance <span style="color:var(--text3);font-weight:400;">(30%)</span></th>
            <th>Final Grade</th>
            <th>Letter</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($grades->num_rows === 0): ?>
          <tr><td colspan="8">
            <div class="empty-state">
              <i class="ti ti-chart-off"></i>
              <p>No grade data yet. Add quiz and activity scores first.</p>
            </div>
          </td></tr>
          <?php endif; ?>

          <?php while ($r = $grades->fetch_assoc()):
            $final   = (float)$r['final_grade'];
            $passing = $final >= 75;
            $row_style = !$passing && $final > 0 ? 'background:rgba(239,68,68,.04);' : '';
          ?>
          <tr style="<?php echo $row_style; ?>">
            <td>
              <div style="font-weight:500;"><?php echo htmlspecialchars($r['last_name'].', '.$r['first_name']); ?></div>
              <div class="td-mono" style="font-size:11px;"><?php echo htmlspecialchars($r['student_id']); ?></div>
            </td>
            <td><span class="badge badge-blue"><?php echo htmlspecialchars($r['section']); ?></span></td>

            <!-- Quiz avg bar -->
            <td>
              <?php $v = (float)$r['quiz_avg']; ?>
              <div class="score-bar-wrap">
                <div class="score-bar-track">
                  <div class="score-bar-fill" style="width:<?php echo min($v,100); ?>%;background:<?php echo $v>=75?'var(--green)':($v>=60?'var(--yellow)':'var(--red)'); ?>"></div>
                </div>
                <span style="font-size:12px;min-width:42px;text-align:right;"><?php echo number_format($v,1); ?>%</span>
              </div>
            </td>

            <!-- Activity avg bar -->
            <td>
              <?php $v = (float)$r['activity_avg']; ?>
              <div class="score-bar-wrap">
                <div class="score-bar-track">
                  <div class="score-bar-fill" style="width:<?php echo min($v,100); ?>%;background:<?php echo $v>=75?'var(--green)':($v>=60?'var(--yellow)':'var(--red)'); ?>"></div>
                </div>
                <span style="font-size:12px;min-width:42px;text-align:right;"><?php echo number_format($v,1); ?>%</span>
              </div>
            </td>

            <!-- Attendance bar -->
            <td>
              <?php $v = (float)$r['attendance_rate']; ?>
              <div class="score-bar-wrap">
                <div class="score-bar-track">
                  <div class="score-bar-fill" style="width:<?php echo min($v,100); ?>%;background:<?php echo $v>=75?'var(--green)':($v>=60?'var(--yellow)':'var(--red)'); ?>"></div>
                </div>
                <span style="font-size:12px;min-width:42px;text-align:right;"><?php echo number_format($v,1); ?>%</span>
              </div>
            </td>

            <!-- Final grade (big) -->
            <td>
              <span style="font-size:18px;font-weight:600;color:<?php echo $passing?'var(--green)':'var(--red)'; ?>">
                <?php echo number_format($final,2); ?>%
              </span>
            </td>

            <!-- Letter grade badge -->
            <td>
              <span class="badge <?php echo gradeBadge($r['letter_grade']); ?>" style="font-size:13px;padding:4px 12px;">
                <?php echo htmlspecialchars($r['letter_grade']); ?>
              </span>
            </td>

            <!-- Pass/Fail status -->
            <td>
              <?php if ($final === 0.0): ?>
                <span class="badge badge-blue">No data</span>
              <?php elseif ($passing): ?>
                <span class="badge badge-green"><i class="ti ti-check"></i> Passed</span>
              <?php else: ?>
                <span class="badge badge-red"><i class="ti ti-x"></i> Failed</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <p style="font-size:11px;color:var(--text3);margin-top:12px;">
      Formula: Final Grade = (Quiz Avg × 30%) + (Activity Avg × 40%) + (Attendance Rate × 30%)
      &nbsp;·&nbsp; Last updated: <?php echo date('M d, Y h:i A'); ?>
    </p>
  </div>
</div>

<?php include '../includes/footer.php'; ?>