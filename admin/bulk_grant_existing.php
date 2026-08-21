<?php
// ============================================================
//  admin/bulk_grant_existing.php
//  ONE-TIME migration helper. Finds every (teacher, section) pair
//  currently in active use via subject_enrollments where the
//  section has no owner (teacher_id IS NULL) — i.e. teachers who
//  will lose access the moment section-gating goes live — and lets
//  admin grant each of them their own clone in one click.
//
//  Safe to re-run: pairs that already have a matching clone
//  (teacher_id = X AND cloned_from_section_id = Y) are skipped
//  automatically, so running this twice does nothing extra the
//  second time. Not linked in the nav on purpose — this is meant
//  to be run once right after deploying the section-gating update,
//  then can be deleted.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin_id = (int)$_SESSION['user_id'];
$results  = null;

function clone_section_for_teacher($conn, $section_id, $target_teacher_id) {
    $orig = $conn->prepare("SELECT section_name, description, course FROM sections WHERE id = ?");
    $orig->bind_param('i', $section_id);
    $orig->execute();
    $orig_row = $orig->get_result()->fetch_assoc();
    if (!$orig_row) throw new Exception("Original section not found.");

    $clone_name = $orig_row['section_name'];
    $nameChk = $conn->prepare("SELECT id FROM sections WHERE section_name = ? AND teacher_id = ? LIMIT 1");
    $nameChk->bind_param('si', $clone_name, $target_teacher_id);
    $nameChk->execute();
    $nameChk->store_result();
    if ($nameChk->num_rows > 0) {
        $clone_name = $orig_row['section_name'] . ' (from Admin)';
    }

    $ins = $conn->prepare(
        "INSERT INTO sections (section_name, description, course, teacher_id, cloned_from_section_id)
         VALUES (?, ?, ?, ?, ?)"
    );
    $ins->bind_param('sssii', $clone_name, $orig_row['description'], $orig_row['course'],
                      $target_teacher_id, $section_id);
    $ins->execute();
    $new_section_id = $conn->insert_id;

    $copy = $conn->prepare(
        "INSERT INTO section_students (section_id, student_id)
         SELECT ?, student_id FROM section_students WHERE section_id = ?"
    );
    $copy->bind_param('ii', $new_section_id, $section_id);
    $copy->execute();

    return $new_section_id;
}

// ── Discover every (teacher, section) pair currently relying on an
//    unowned section through an active subject enrollment ──────────
function find_pairs($conn) {
    $rows = $conn->query(
        "SELECT DISTINCT sub.teacher_id, se.section_id, sec.section_name, sec.course,
                u.username AS teacher_username, u.display_name AS teacher_display_name,
                EXISTS(
                    SELECT 1 FROM sections c
                    WHERE c.teacher_id = sub.teacher_id AND c.cloned_from_section_id = se.section_id
                ) AS already_granted
         FROM subject_enrollments se
         JOIN subjects sub  ON sub.id = se.subject_id
         JOIN sections sec  ON sec.id = se.section_id
         JOIN users u       ON u.id = sub.teacher_id
         WHERE sec.teacher_id IS NULL
         ORDER BY u.username, sec.section_name"
    )->fetch_all(MYSQLI_ASSOC);
    return $rows;
}

if (isset($_POST['run_grants'])) {
    $pairs = find_pairs($conn);
    $results = ['granted' => [], 'skipped' => [], 'errors' => []];

    foreach ($pairs as $p) {
        $label = ($p['teacher_display_name'] ?: $p['teacher_username']) . ' — ' . $p['section_name'];
        if ($p['already_granted']) {
            $results['skipped'][] = "$label: already granted, nothing to do.";
            continue;
        }
        $conn->begin_transaction();
        try {
            $new_section_id = clone_section_for_teacher($conn, (int)$p['section_id'], (int)$p['teacher_id']);
            $ins = $conn->prepare(
                "INSERT INTO section_access_requests
                    (section_id, requesting_teacher_id, message, status, approved_by_teacher_id, resulting_section_id, responded_at)
                 VALUES (?, ?, 'Grandfathered in during section-gating rollout', 'approved', ?, ?, NOW())"
            );
            $ins->bind_param('iiii', $p['section_id'], $p['teacher_id'], $admin_id, $new_section_id);
            $ins->execute();
            $conn->commit();
            $results['granted'][] = "$label: granted (new section id $new_section_id).";
        } catch (Exception $e) {
            $conn->rollback();
            $results['errors'][] = "$label: " . htmlspecialchars($e->getMessage());
        }
    }
}

$pairs = find_pairs($conn);
$pending_count = count(array_filter($pairs, fn($p) => !$p['already_granted']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Bulk Grant Existing Sections — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-bulk-grant">

<?php include __DIR__ . '/_nav.php'; ?>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-shield-check" style="color:var(--accent)"></i> Bulk Grant Existing Sections</h1>
    <p>One-time helper: find teachers actively using an admin-owned section, and grant each of them their own copy before section-gating locks them out.</p>
  </div>
  <hr class="thin-line" style="margin-bottom:25px;">

  <?php if ($results !== null): ?>
    <div class="card" style="margin-bottom:20px;">
      <p class="card-title"><i class="ti ti-report"></i> Run Results</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
        <span class="badge badge-green"><?php echo count($results['granted']); ?> granted</span>
        <span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;background:rgba(234,179,8,.12);color:var(--yellow);border:1px solid rgba(234,179,8,.25);">
          <?php echo count($results['skipped']); ?> already granted
        </span>
        <?php if (!empty($results['errors'])): ?>
        <span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);">
          <?php echo count($results['errors']); ?> errors
        </span>
        <?php endif; ?>
      </div>
      <?php if (!empty($results['granted'])): ?>
        <p style="font-size:12px;font-weight:600;color:var(--text7);margin-bottom:6px;">GRANTED</p>
        <ul style="font-size:12.5px;line-height:1.8;padding-left:18px;margin:0 0 14px;">
          <?php foreach ($results['granted'] as $l): ?><li><?php echo htmlspecialchars($l); ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($results['errors'])): ?>
        <p style="font-size:12px;font-weight:600;color:var(--red);margin-bottom:6px;">ERRORS</p>
        <ul style="font-size:12.5px;line-height:1.8;padding-left:18px;margin:0;">
          <?php foreach ($results['errors'] as $l): ?><li><?php echo $l; ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <p class="card-title"><i class="ti ti-list-check"></i> Pairs Found (<?php echo count($pairs); ?>)</p>
    <?php if (empty($pairs)): ?>
      <p style="font-size:13px;color:var(--text7);">No teachers are relying on an unowned section right now — nothing to grant.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Teacher</th><th>Section</th><th>Course</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($pairs as $p): ?>
          <tr>
            <td><?php echo htmlspecialchars($p['teacher_display_name'] ?: $p['teacher_username']); ?></td>
            <td><?php echo htmlspecialchars($p['section_name']); ?></td>
            <td style="color:var(--text7);font-size:12px;"><?php echo htmlspecialchars($p['course'] ?: '—'); ?></td>
            <td>
              <?php if ($p['already_granted']): ?>
                <span class="badge badge-green">Already granted</span>
              <?php else: ?>
                <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:99px;background:rgba(234,179,8,.12);color:var(--yellow);border:1px solid rgba(234,179,8,.25);">Will grant</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pending_count > 0): ?>
    <form method="POST" style="margin-top:16px;" onsubmit="return confirm('Grant <?php echo $pending_count; ?> section(s) to their teachers now?');">
      <button type="submit" name="run_grants" class="btn btn-primary">
        <i class="ti ti-shield-check"></i> Grant All Pending (<?php echo $pending_count; ?>)
      </button>
    </form>
    <?php else: ?>
      <p style="font-size:13px;color:var(--green);margin-top:12px;"><i class="ti ti-circle-check"></i> Everything is already granted — safe to deploy.</p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
