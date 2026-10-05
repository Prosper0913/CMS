<?php
// ============================================================
//  admin/sections.php
//  Read-only. Teachers are the only ones who create, rename, or
//  delete sections now (teacher/manage_sections.php) — admin can
//  browse every section and its roster, but nothing here writes
//  to the database.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';

$allowed_courses = ['BSIT','LAED','BSBA','BSN','FPST','BSA'];
$filter_course = trim($_GET['course'] ?? '');
if (!in_array($filter_course, $allowed_courses, true)) $filter_course = '';
$filter_year = trim($_GET['year'] ?? '');
if ($filter_year !== '' && !ctype_digit($filter_year)) $filter_year = '';

$where = []; $types = ''; $params = [];
if ($filter_course !== '') { $where[] = "s.course = ?";      $types .= 's'; $params[] = $filter_course; }
if ($filter_year   !== '') { $where[] = "s.year_level = ?";  $types .= 'i'; $params[] = (int)$filter_year; }

$sql = "SELECT s.*, COALESCE(u.display_name, u.username) AS teacher_name,
            COUNT(DISTINCT ss.student_id) AS student_count
         FROM sections s
         LEFT JOIN users u ON u.id = s.teacher_id
         LEFT JOIN section_students ss ON ss.section_id = s.id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " GROUP BY s.id ORDER BY s.section_name ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$all_sections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$years_list = $conn->query(
    "SELECT DISTINCT year_level FROM sections WHERE year_level IS NOT NULL ORDER BY year_level ASC"
)->fetch_all(MYSQLI_ASSOC);

// ── Roster for whichever section is selected ────────────────
$active_sec_id  = (int)($_GET['sec'] ?? ($all_sections[0]['id'] ?? 0));
$active_section = null;
foreach ($all_sections as $s) { if ((int)$s['id'] === $active_sec_id) { $active_section = $s; break; } }

$roster = [];
if ($active_section) {
    $rr = $conn->prepare(
        "SELECT st.student_id, st.last_name, st.first_name, st.middle_initial
         FROM section_students ss
         JOIN students st ON st.student_id = ss.student_id
         WHERE ss.section_id = ?
         ORDER BY st.last_name ASC"
    );
    $rr->bind_param('i', $active_sec_id);
    $rr->execute();
    $roster = $rr->get_result()->fetch_all(MYSQLI_ASSOC);
}

$active_nav = 'sections';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Sections — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-sections">
<div class="app-shell">

<?php $active_nav = 'sections'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-building-community text-accent"></i> Sections</h1>
    <p>Read-only. Teachers create and manage their own sections. <?php echo count($all_sections); ?> total.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <div class="two-col">
    <div class="card">
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
        <select name="course" class="form-control" style="max-width:150px;">
          <option value="">All courses</option>
          <?php foreach ($allowed_courses as $c): ?>
          <option value="<?php echo $c; ?>" <?php echo $filter_course === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
          <?php endforeach; ?>
        </select>
        <select name="year" class="form-control" style="max-width:150px;">
          <option value="">All years</option>
          <?php foreach ($years_list as $y): ?>
          <option value="<?php echo (int)$y['year_level']; ?>" <?php echo $filter_year === (string)$y['year_level'] ? 'selected' : ''; ?>>Year <?php echo (int)$y['year_level']; ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        <?php if ($filter_course || $filter_year): ?>
          <a href="sections.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i></a>
        <?php endif; ?>
      </form>

      <div class="table-wrap">
        <table>
          <thead><tr><th>Section</th><th>Teacher</th><th>Course</th><th>Year</th><th>Students</th></tr></thead>
          <tbody>
            <?php if (empty($all_sections)): ?>
            <tr><td colspan="5"><div class="empty-state"><i class="ti ti-building-community"></i><p>No sections match these filters.</p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($all_sections as $sec): ?>
            <tr style="cursor:pointer;<?php echo (int)$sec['id'] === $active_sec_id ? 'background:var(--bg3);' : ''; ?>"
                onclick="location.href='sections.php?sec=<?php echo (int)$sec['id']; ?><?php echo $filter_course ? '&course='.urlencode($filter_course) : ''; ?><?php echo $filter_year ? '&year='.urlencode($filter_year) : ''; ?>'">
              <td style="font-weight:500;"><?php echo htmlspecialchars($sec['section_name']); ?></td>
              <td><?php echo htmlspecialchars($sec['teacher_name'] ?: '—'); ?></td>
              <td><?php echo htmlspecialchars($sec['course'] ?: '—'); ?></td>
              <td><?php echo $sec['year_level'] ? 'Year ' . (int)$sec['year_level'] : '—'; ?></td>
              <td><?php echo (int)$sec['student_count']; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <p class="card-title"><i class="ti ti-list"></i>
        <?php echo $active_section ? htmlspecialchars($active_section['section_name']) . ' — Roster' : 'Roster'; ?>
      </p>
      <?php if (!$active_section): ?>
        <p style="font-size:13px;color:var(--text7);">Select a section to see its roster.</p>
      <?php else: ?>
        <p style="font-size:12px;color:var(--text7);margin-top:-8px;">
          Taught by <?php echo htmlspecialchars($active_section['teacher_name'] ?: '—'); ?> ·
          <?php echo htmlspecialchars($active_section['course'] ?: '—'); ?>
          <?php echo $active_section['year_level'] ? ' · Year ' . (int)$active_section['year_level'] : ''; ?>
        </p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Student</th><th>Student ID</th></tr></thead>
            <tbody>
              <?php if (empty($roster)): ?>
              <tr><td colspan="2"><div class="empty-state"><i class="ti ti-users-off"></i><p>No students in this section yet.</p></div></td></tr>
              <?php endif; ?>
              <?php foreach ($roster as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name'] . ' ' . ($r['middle_initial'] ?? '')); ?></td>
                <td class="td-mono"><?php echo htmlspecialchars($r['student_id']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</main>
</div>
</body>
</html>
