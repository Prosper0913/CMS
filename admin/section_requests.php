<?php
// ============================================================
//  admin/section_requests.php
//  Approve/deny teacher requests for ADMIN-OWNED sections
//  (sections.teacher_id IS NULL), and grant a section to a
//  teacher directly without waiting for a request.
//
//  Reuses the existing section_access_requests table (the same
//  one teacher/manage_sections.php uses for peer-to-peer
//  requests) — a request just gets routed here instead of to a
//  teacher when its target section has no teacher owner. Approval
//  and direct grants both work the same way: clone the section
//  (name, description, course) into a brand-new row owned by that
//  teacher, and copy the current roster as a one-time snapshot.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin_id    = (int)$_SESSION['user_id'];
$success_msg = '';
$error_msg   = '';

// Shared clone logic: copy a section (name/description/course) into a
// brand-new row owned by $target_teacher_id, then snapshot its roster.
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

// ── APPROVE / DENY an incoming request for an admin-owned section ──
if (isset($_POST['respond_admin_request'])) {
    $req_id = (int)$_POST['request_id'];
    $action = $_POST['action'] ?? '';
    $new_status = $action === 'approve' ? 'approved' : ($action === 'deny' ? 'denied' : null);

    if (!$new_status) {
        $error_msg = "Invalid request action.";
    } else {
        $rq = $conn->prepare(
            "SELECT r.section_id, r.requesting_teacher_id, s.teacher_id AS section_owner
             FROM section_access_requests r
             JOIN sections s ON s.id = r.section_id
             WHERE r.id = ? AND r.status = 'pending'"
        );
        $rq->bind_param('i', $req_id);
        $rq->execute();
        $req_row = $rq->get_result()->fetch_assoc();

        if (!$req_row) {
            $error_msg = "Request not found or already handled.";
        } elseif ($req_row['section_owner'] !== null) {
            $error_msg = "This request is for a teacher-owned section — it must be handled by that teacher, not admin.";
        } elseif ($new_status === 'approved') {
            $conn->begin_transaction();
            try {
                $new_section_id = clone_section_for_teacher($conn, $req_row['section_id'], $req_row['requesting_teacher_id']);
                $upd = $conn->prepare(
                    "UPDATE section_access_requests
                     SET status = 'approved', approved_by_teacher_id = ?, resulting_section_id = ?, responded_at = NOW()
                     WHERE id = ? AND status = 'pending'"
                );
                $upd->bind_param('iii', $admin_id, $new_section_id, $req_id);
                $upd->execute();
                $conn->commit();
                $success_msg = "Request approved — the teacher now has their own copy of this section.";
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Could not approve: " . $e->getMessage();
            }
        } else {
            $upd = $conn->prepare(
                "UPDATE section_access_requests
                 SET status = 'denied', approved_by_teacher_id = ?, responded_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $upd->bind_param('ii', $admin_id, $req_id);
            $upd->execute();
            $success_msg = "Request denied.";
        }
    }
}

// ── GRANT a section to a teacher directly (no request needed) ──────
if (isset($_POST['grant_section'])) {
    $section_id = (int)($_POST['grant_section_id'] ?? 0);
    $teacher_id = (int)($_POST['grant_teacher_id'] ?? 0);

    $sec_chk = $conn->prepare("SELECT id FROM sections WHERE id = ? AND teacher_id IS NULL LIMIT 1");
    $sec_chk->bind_param('i', $section_id);
    $sec_chk->execute();
    $sec_chk->store_result();

    $t_chk = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' LIMIT 1");
    $t_chk->bind_param('i', $teacher_id);
    $t_chk->execute();
    $t_chk->store_result();

    if ($sec_chk->num_rows === 0) {
        $error_msg = "Please choose a valid admin-owned section.";
    } elseif ($t_chk->num_rows === 0) {
        $error_msg = "Please choose a valid teacher.";
    } else {
        $conn->begin_transaction();
        try {
            $new_section_id = clone_section_for_teacher($conn, $section_id, $teacher_id);
            // Audit trail: log this as an already-approved "request" even
            // though the teacher never asked, so it shows up in their
            // history and in the admin log the same way a normal
            // approval would.
            $ins = $conn->prepare(
                "INSERT INTO section_access_requests
                    (section_id, requesting_teacher_id, message, status, approved_by_teacher_id, resulting_section_id, responded_at)
                 VALUES (?, ?, 'Granted directly by admin', 'approved', ?, ?, NOW())"
            );
            $ins->bind_param('iiii', $section_id, $teacher_id, $admin_id, $new_section_id);
            $ins->execute();
            $conn->commit();
            $success_msg = "Section granted — the teacher now has their own copy.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Could not grant: " . $e->getMessage();
        }
    }
}

// ── Data for the page ────────────────────────────────────────
$pending_admin_requests = $conn->query(
    "SELECT r.id, r.message, r.created_at, s.section_name, s.course,
            (SELECT COUNT(*) FROM section_students WHERE section_id = r.section_id) AS section_size,
            u.username AS teacher_username, u.display_name AS teacher_display_name
     FROM section_access_requests r
     JOIN sections s ON s.id = r.section_id
     JOIN users u    ON u.id = r.requesting_teacher_id
     WHERE r.status = 'pending' AND s.teacher_id IS NULL
     ORDER BY r.created_at ASC"
)->fetch_all(MYSQLI_ASSOC);

$admin_sections = $conn->query(
    "SELECT id, section_name, course,
        (SELECT COUNT(*) FROM section_students WHERE section_id = sections.id) AS student_count
     FROM sections WHERE teacher_id IS NULL ORDER BY section_name ASC"
)->fetch_all(MYSQLI_ASSOC);

$teachers_list = $conn->query(
    "SELECT id, username, display_name FROM users WHERE role = 'teacher' ORDER BY username ASC"
)->fetch_all(MYSQLI_ASSOC);

$recent_grants = $conn->query(
    "SELECT r.status, r.message, r.responded_at, s.section_name,
            u.username AS teacher_username, ab.username AS approved_by_name
     FROM section_access_requests r
     JOIN sections s ON s.id = r.section_id
     JOIN users u     ON u.id = r.requesting_teacher_id
     LEFT JOIN users ab ON ab.id = r.approved_by_teacher_id
     WHERE r.responded_at IS NOT NULL AND ab.id IS NOT NULL
       AND EXISTS (SELECT 1 FROM users au WHERE au.id = r.approved_by_teacher_id AND au.role = 'admin')
     ORDER BY r.responded_at DESC LIMIT 15"
)->fetch_all(MYSQLI_ASSOC);

$active_nav = 'section_requests';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Section Requests — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-section-requests">
<div class="app-shell">


<?php $active_nav = 'section_requests'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-hand-stop" style="color:var(--accent)"></i> Section Requests</h1>
    <p>Approve teacher requests for sections in your pool, or grant a section directly.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($success_msg): ?>
  <div class="alert alert-success"><i class="ti ti-circle-check"></i><div><?php echo htmlspecialchars($success_msg); ?></div></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
  <div class="alert alert-error"><i class="ti ti-alert-circle"></i><div><?php echo htmlspecialchars($error_msg); ?></div></div>
  <?php endif; ?>

  <!-- Pending requests -->
  <div class="card" style="margin-bottom:20px;">
    <p class="card-title"><i class="ti ti-inbox"></i> Pending Requests (<?php echo count($pending_admin_requests); ?>)</p>
    <?php if (empty($pending_admin_requests)): ?>
      <p style="font-size:13px;color:var(--text7);">No pending requests for admin-owned sections.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Teacher</th><th>Section</th><th>Message</th><th>Requested</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($pending_admin_requests as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['teacher_display_name'] ?: $r['teacher_username']); ?></td>
            <td>
              <?php echo htmlspecialchars($r['section_name']); ?>
              <?php if ($r['course']): ?><span class="badge badge-blue"><?php echo htmlspecialchars($r['course']); ?></span><?php endif; ?>
              <div style="font-size:11px;color:var(--text7);"><?php echo (int)$r['section_size']; ?> students</div>
            </td>
            <td style="font-size:12px;color:var(--text2);max-width:220px;"><?php echo htmlspecialchars($r['message'] ?: '—'); ?></td>
            <td style="font-size:11px;color:var(--text7);"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td>
              <div class="td-actions">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="approve">
                  <button type="submit" name="respond_admin_request" class="btn btn-sm btn-primary"><i class="ti ti-check"></i> Approve</button>
                </form>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="deny">
                  <button type="submit" name="respond_admin_request" class="btn btn-sm btn-outline"><i class="ti ti-x"></i> Deny</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="two-col">
    <!-- Grant a section directly -->
    <div class="card">
      <p class="card-title"><i class="ti ti-gift"></i> Grant a Section to a Teacher</p>
      <p style="font-size:12px;color:var(--text7);margin-top:-6px;margin-bottom:14px;">
        Skip the request step entirely — pick a section and a teacher, and they'll get their own copy immediately.
      </p>
      <?php if (empty($admin_sections) || empty($teachers_list)): ?>
        <p style="font-size:13px;color:var(--text7);">
          <?php echo empty($admin_sections) ? "No admin-owned sections yet." : "No teacher accounts yet."; ?>
        </p>
      <?php else: ?>
      <form method="POST">
        <div class="form-group">
          <label>Section</label>
          <select name="grant_section_id" class="form-control" required>
            <option value="">— Select a section —</option>
            <?php foreach ($admin_sections as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>">
              <?php echo htmlspecialchars($s['section_name']); ?><?php echo $s['course'] ? ' — '.htmlspecialchars($s['course']) : ''; ?>
              (<?php echo (int)$s['student_count']; ?> students)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Teacher</label>
          <select name="grant_teacher_id" class="form-control" required>
            <option value="">— Select a teacher —</option>
            <?php foreach ($teachers_list as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>">
              <?php echo htmlspecialchars($t['display_name'] ?: $t['username']); ?> (<?php echo htmlspecialchars($t['username']); ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="grant_section" class="btn btn-primary">
          <i class="ti ti-gift"></i> Grant Section
        </button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Recent activity -->
    <div class="card">
      <p class="card-title"><i class="ti ti-history"></i> Recent Admin-Handled Requests</p>
      <?php if (empty($recent_grants)): ?>
        <p style="font-size:13px;color:var(--text7);">No admin-approved or denied requests yet.</p>
      <?php else: ?>
        <ul style="font-size:12.5px;line-height:1.9;padding-left:0;margin:0;list-style:none;">
          <?php foreach ($recent_grants as $g): ?>
          <li style="padding:8px 0;border-bottom:1px solid var(--border);">
            <?php if ($g['status'] === 'approved'): ?><i class="ti ti-check" style="color:var(--green);"></i><?php else: ?><i class="ti ti-x" style="color:var(--red);"></i><?php endif; ?>
            <b><?php echo htmlspecialchars($g['teacher_username']); ?></b> &middot; <?php echo htmlspecialchars($g['section_name']); ?>
            &middot; <span style="color:var(--text7);"><?php echo ucfirst($g['status']); ?></span>
            <br>
            <span style="color:var(--text7);font-size:11.5px;"><?php echo htmlspecialchars($g['responded_at']); ?><?php if ($g['message']) echo ' — '.htmlspecialchars($g['message']); ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

</main>
</div>
</body>
</html>
