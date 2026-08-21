<?php
// ============================================================
//  teacher/borrowed_equipment.php
//  Read-only view of equipment borrow records the FPST Inventory
//  System has synced into this CMS, scoped to subjects owned by
//  the logged-in teacher. Nothing here writes back to FPST —
//  this page just reflects what's already landed in
//  fpst_borrowed_equipment via the API.
//
//  NOTE: I don't have your actual teacher/_nav.php or the exact
//  shape of teacher-side auth, so the include below assumes the
//  same pattern as the admin side (requireRole(), _nav.php at the
//  same relative path). If your teacher folder differs, send me
//  teacher/_nav.php (and includes/auth.php if it's role-specific)
//  and I'll line this up exactly instead of guessing.
// ============================================================
require_once '../includes/auth.php';
requireRole('teacher');
require_once '../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$teacher_id = (int)$_SESSION['user_id'];

// Filters
$section_filter = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$subject_filter = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$status_filter  = $_GET['status'] ?? '';

// This teacher's FPST subjects only (subjects.teacher_id = this teacher,
// joined to sections tagged course = 'FPST')
$fpst_subjects = $conn->prepare(
    "SELECT DISTINCT sub.id, sub.subject_code, sub.subject_name, sub.section AS section_name
     FROM subjects sub
     WHERE sub.teacher_id = ?
       AND EXISTS (
         SELECT 1 FROM sections sec
         WHERE sec.section_name = sub.section AND UPPER(TRIM(sec.course)) = 'FPST'
       )
     ORDER BY sub.subject_name"
);
$fpst_subjects->bind_param('i', $teacher_id);
$fpst_subjects->execute();
$subjects = $fpst_subjects->get_result()->fetch_all(MYSQLI_ASSOC);

$fpst_sections = $conn->prepare(
    "SELECT id, section_name FROM sections WHERE UPPER(TRIM(course)) = 'FPST' ORDER BY section_name"
);
$fpst_sections->execute();
$sections = $fpst_sections->get_result()->fetch_all(MYSQLI_ASSOC);

// Build the records query
$where = ["sub.teacher_id = ?"];
$params = [$teacher_id];
$types = 'i';

if ($section_filter) { $where[] = "fbe.section_id = ?"; $params[] = $section_filter; $types .= 'i'; }
if ($subject_filter) { $where[] = "fbe.subject_id = ?"; $params[] = $subject_filter; $types .= 'i'; }
if ($status_filter && in_array($status_filter, ['borrowed','returned','overdue','lost'], true)) {
    $where[] = "fbe.status = ?"; $params[] = $status_filter; $types .= 's';
}
$where_sql = implode(' AND ', $where);

$sql = "SELECT fbe.*, s.first_name, s.last_name, sec.section_name, sub.subject_name, sub.subject_code
        FROM fpst_borrowed_equipment fbe
        JOIN subjects sub ON sub.id = fbe.subject_id
        JOIN students s ON s.student_id = fbe.student_id
        JOIN sections sec ON sec.id = fbe.section_id
        WHERE $where_sql
        ORDER BY fbe.borrow_date DESC, fbe.synced_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$status_badge = [
    'borrowed' => 'background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.25);',
    'returned' => 'background:rgba(34,197,94,.12);color:var(--green);border:1px solid rgba(34,197,94,.25);',
    'overdue'  => 'background:rgba(234,179,8,.12);color:var(--yellow);border:1px solid rgba(234,179,8,.25);',
    'lost'     => 'background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>FPST Borrowed Equipment</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-teacher-fpst-equipment">

<?php if (file_exists(__DIR__ . '/_nav.php')) include __DIR__ . '/_nav.php'; ?>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-tools" style="color:var(--accent)"></i> FPST Borrowed Equipment</h1>
    <p>Equipment borrow records synced from the FPST Inventory System for your FPST subjects.</p>
  </div>
  <hr class="thin-line" style="margin-bottom:25px;">

  <div class="card" style="margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <div class="form-group" style="margin:0;">
        <label>Section</label>
        <select name="section_id" class="form-control">
          <option value="">All FPST sections</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo $section_filter===(int)$s['id']?'selected':''; ?>>
              <?php echo htmlspecialchars($s['section_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Subject</label>
        <select name="subject_id" class="form-control">
          <option value="">All your FPST subjects</option>
          <?php foreach ($subjects as $sub): ?>
            <option value="<?php echo (int)$sub['id']; ?>" <?php echo $subject_filter===(int)$sub['id']?'selected':''; ?>>
              <?php echo htmlspecialchars($sub['subject_code'] . ' — ' . $sub['subject_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label>Status</label>
        <select name="status" class="form-control">
          <option value="">All statuses</option>
          <?php foreach (['borrowed','returned','overdue','lost'] as $st): ?>
            <option value="<?php echo $st; ?>" <?php echo $status_filter===$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter"></i> Filter</button>
      <?php if ($section_filter || $subject_filter || $status_filter): ?>
        <a href="borrowed_equipment.php" class="btn btn-outline btn-sm">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <p class="card-title"><i class="ti ti-list"></i> Records (<?php echo count($records); ?>)</p>
    <?php if (empty($records)): ?>
      <p style="font-size:13px;color:var(--text7);">
        No borrow records yet. These appear automatically once the FPST Inventory System syncs them through the API — nothing to do here.
      </p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Student</th><th>Section</th><th>Subject</th><th>Equipment</th><th>Qty</th><th>Borrowed</th><th>Status</th><th>Approved By</th></tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['last_name'] . ', ' . $r['first_name']); ?><br>
                <span style="font-size:11px;color:var(--text7);"><?php echo htmlspecialchars($r['student_id']); ?></span></td>
            <td><?php echo htmlspecialchars($r['section_name']); ?></td>
            <td><?php echo htmlspecialchars($r['subject_code']); ?></td>
            <td><?php echo htmlspecialchars($r['equipment_name']); ?></td>
            <td><?php echo (int)$r['quantity']; ?></td>
            <td><?php echo htmlspecialchars($r['borrow_date']); ?></td>
            <td><span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:99px;<?php echo $status_badge[$r['status']] ?? ''; ?>">
                  <?php echo ucfirst($r['status']); ?>
                </span></td>
            <td><?php echo htmlspecialchars($r['approved_by']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
