<?php
// ============================================================
//  admin/students.php
//  Read-only student directory. Teachers are the only ones who
//  create, edit, or delete student accounts now (teacher/students.php) —
//  admin can look, filter, and search, but nothing here writes to
//  the database.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';

const STUDENT_COURSES = ['BSIT','LAED','BSBA','BSN','FPST','BSA'];

// ── Filters ──────────────────────────────────────────────────
$search         = trim($_GET['search'] ?? '');
$filter_course  = trim($_GET['course'] ?? '');
$filter_year    = trim($_GET['year'] ?? '');
$filter_section = isset($_GET['section']) && $_GET['section'] !== '' ? $_GET['section'] : null;

if (!in_array($filter_course, STUDENT_COURSES, true)) $filter_course = '';
if ($filter_year !== '' && !ctype_digit($filter_year)) $filter_year = '';

$where  = [];
$types  = '';
$params = [];

if ($search !== '') {
    $where[] = "(s.last_name LIKE ? OR s.first_name LIKE ? OR s.student_id LIKE ?)";
    $like = "%{$search}%";
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($filter_course !== '') {
    $where[] = "s.course = ?";
    $types  .= 's';
    $params[] = $filter_course;
}
if ($filter_year !== '') {
    $where[] = "EXISTS (SELECT 1 FROM section_students ss JOIN sections sec ON sec.id = ss.section_id
                        WHERE ss.student_id = s.student_id AND sec.year_level = ?)";
    $types  .= 'i';
    $params[] = (int)$filter_year;
}
if ($filter_section === 'unassigned') {
    $where[] = "NOT EXISTS (SELECT 1 FROM section_students ss WHERE ss.student_id = s.student_id)";
} elseif ($filter_section !== null) {
    $where[] = "EXISTS (SELECT 1 FROM section_students ss WHERE ss.student_id = s.student_id AND ss.section_id = ?)";
    $types  .= 'i';
    $params[] = (int)$filter_section;
}

$sql = "SELECT s.*,
            (SELECT COUNT(*) FROM subject_enrollments e WHERE e.student_id=s.student_id) AS subject_count,
            (SELECT GROUP_CONCAT(DISTINCT CONCAT(sec.section_name, ' (', COALESCE(u.display_name, u.username), ')') SEPARATOR ', ')
             FROM section_students ss
             JOIN sections sec ON sec.id = ss.section_id
             LEFT JOIN users u ON u.id = sec.teacher_id
             WHERE ss.student_id = s.student_id) AS section_names
         FROM students s";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY s.last_name ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result();

$total_students = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];

// Sections for the filter dropdown — every section, across every teacher.
$sections_list = $conn->query(
    "SELECT sec.id, sec.section_name, sec.course, sec.year_level, COALESCE(u.display_name, u.username) AS teacher_name
     FROM sections sec
     LEFT JOIN users u ON u.id = sec.teacher_id
     ORDER BY sec.section_name ASC"
)->fetch_all(MYSQLI_ASSOC);

// Distinct year levels actually in use, for the Year filter dropdown.
$years_list = $conn->query(
    "SELECT DISTINCT year_level FROM sections WHERE year_level IS NOT NULL ORDER BY year_level ASC"
)->fetch_all(MYSQLI_ASSOC);

$active_nav = 'students';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Student Directory — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-students">
<div class="app-shell">

<?php $active_nav = 'students'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-users text-accent"></i> Student Directory</h1>
    <p>Read-only. Teachers create and manage student accounts from their own Students page. <?php echo (int)$total_students; ?> total.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <div class="card">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
      <div class="input-wrap" style="flex:1;min-width:200px;">
        <i class="ti ti-search"></i>
        <input type="text" name="search" class="form-control" placeholder="Search by name or ID…"
          value="<?php echo htmlspecialchars($search); ?>">
      </div>
      <select name="course" class="form-control" style="max-width:150px;">
        <option value="">All courses</option>
        <?php foreach (STUDENT_COURSES as $c): ?>
        <option value="<?php echo $c; ?>" <?php echo $filter_course === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="year" class="form-control" style="max-width:150px;">
        <option value="">All years</option>
        <?php foreach ($years_list as $y): ?>
        <option value="<?php echo (int)$y['year_level']; ?>" <?php echo $filter_year === (string)$y['year_level'] ? 'selected' : ''; ?>>Year <?php echo (int)$y['year_level']; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="section" class="form-control" style="max-width:220px;">
        <option value="">All sections</option>
        <option value="unassigned" <?php echo $filter_section === 'unassigned' ? 'selected' : ''; ?>>Not in any section</option>
        <?php foreach ($sections_list as $sec): ?>
        <option value="<?php echo (int)$sec['id']; ?>" <?php echo $filter_section === (string)$sec['id'] ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($sec['section_name'] . ' — ' . $sec['teacher_name']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-outline btn-sm">Filter</button>
      <?php if ($search || $filter_course || $filter_year || $filter_section !== null): ?>
        <a href="students.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i> Clear</a>
      <?php endif; ?>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Student ID</th>
            <th>Course</th>
            <th>Sections</th>
            <th>Subjects</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($students->num_rows === 0): ?>
          <tr><td colspan="5">
            <div class="empty-state">
              <i class="ti ti-users-off"></i>
              <p>No students match these filters.</p>
            </div>
          </td></tr>
          <?php endif; ?>

          <?php while ($s = $students->fetch_assoc()):
            $initials = strtoupper(substr($s['last_name'],0,1).substr($s['first_name'],0,1));
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div class="avatar"><?php echo $initials; ?></div>
                <div>
                  <div style="font-weight:500;">
                    <?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?>
                    <?php if ($s['middle_initial']): ?><span><?php echo htmlspecialchars($s['middle_initial']); ?></span><?php endif; ?>
                  </div>
                  <div style="font-size:11px;color:var(--text7);">
                    <?php
                      $sub = [$s['email'] ?: '—'];
                      if (!empty($s['contact_number'])) $sub[] = $s['contact_number'];
                      if (!empty($s['gender']))         $sub[] = $s['gender'];
                      echo htmlspecialchars(implode(' · ', $sub));
                    ?>
                  </div>
                </div>
              </div>
            </td>
            <td class="td-mono"><?php echo htmlspecialchars($s['student_id']); ?></td>
            <td><?php echo htmlspecialchars($s['course'] ?: '—'); ?></td>
            <td style="font-size:12px;"><?php echo $s['section_names'] ? htmlspecialchars($s['section_names']) : '<span class="text-muted">Not in a section</span>'; ?></td>
            <td>
              <?php if ($s['subject_count'] > 0): ?>
                <span class="badge badge-green"><?php echo $s['subject_count']; ?></span>
              <?php else: ?>
                <span class="text-muted">0</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</main>
</div>
</body>
</html>
