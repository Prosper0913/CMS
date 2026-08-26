<?php
// ============================================================
//  admin/api_keys.php
//  Generate and manage API keys for ANY external system that
//  integrates with this CMS (the FPST Inventory System is just
//  the first one). Each key is scoped to ONE course via
//  allowed_course — that's what api/_api_common.php checks on
//  every request, so a key can never see/touch a course outside
//  what it was issued for. Onboarding a new integration is just:
//  generate a key here with the right course, done — no code
//  changes needed anywhere else.
//  Keys are shown in FULL exactly once, right after generation —
//  only a SHA-256 hash is stored, so if the plaintext key is lost
//  it can't be recovered, only revoked and replaced. Admin-only.
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$admin_id = (int)$_SESSION['user_id'];
$newly_generated_key = null; // plaintext, shown once
$flash_error = null;

// ── Generate a new key ──────────────────────────────────────
if (isset($_POST['generate_key'])) {
    $client_name    = trim($_POST['client_name'] ?? '');
    $allowed_course = trim($_POST['allowed_course'] ?? '');
    if ($client_name === '') {
        $flash_error = "Please enter a name for the client system (e.g. \"FPST Inventory System\").";
    } else {
        $plaintext = bin2hex(random_bytes(32)); // 64 hex chars
        $prefix    = substr($plaintext, 0, 8);
        $hash      = hash('sha256', $plaintext);
        $course_val = $allowed_course !== '' ? $allowed_course : null;

        $ins = $conn->prepare(
            "INSERT INTO api_keys (client_name, allowed_course, key_prefix, key_hash, created_by) VALUES (?,?,?,?,?)"
        );
        $ins->bind_param('ssssi', $client_name, $course_val, $prefix, $hash, $admin_id);
        $ins->execute();

        $newly_generated_key = $plaintext;
    }
}

// ── Update an existing key's allowed course ─────────────────
if (isset($_POST['update_course'])) {
    $key_id = (int)($_POST['key_id'] ?? 0);
    $new_course = trim($_POST['new_allowed_course'] ?? '');
    $course_val = $new_course !== '' ? $new_course : null;
    $upd = $conn->prepare("UPDATE api_keys SET allowed_course = ? WHERE id = ?");
    $upd->bind_param('si', $course_val, $key_id);
    $upd->execute();
    header("Location: api_keys.php");
    exit;
}

// ── Revoke a key ─────────────────────────────────────────────
if (isset($_POST['revoke_key_id'])) {
    $revoke_id = (int)$_POST['revoke_key_id'];
    $upd = $conn->prepare("UPDATE api_keys SET is_active = 0, revoked_at = NOW() WHERE id = ?");
    $upd->bind_param('i', $revoke_id);
    $upd->execute();
    header("Location: api_keys.php");
    exit;
}

// ── List keys ────────────────────────────────────────────────
$keys = $conn->query(
    "SELECT id, client_name, allowed_course, key_prefix, is_active, created_at, last_used_at, revoked_at
     FROM api_keys ORDER BY created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// ── Recent activity (last 25 calls) ─────────────────────────
$recent_log = $conn->query(
    "SELECT l.endpoint, l.ip_address, l.success, l.message, l.created_at, k.client_name
     FROM api_request_log l
     LEFT JOIN api_keys k ON k.id = l.api_key_id
     ORDER BY l.created_at DESC LIMIT 25"
)->fetch_all(MYSQLI_ASSOC);

$active_nav = 'api_keys';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>API Keys — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-admin-apikeys">
<div class="app-shell">


<?php $active_nav = 'api_keys'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-key" style="color:var(--accent)"></i> API Keys</h1>
    <p>Manage access for any external system that connects to this CMS — each key is scoped to one course.</p>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($newly_generated_key): ?>
    <div class="alert alert-success" style="margin-bottom:20px;align-items:flex-start;">
      <i class="ti ti-circle-check" style="margin-top:2px;"></i>
      <div>
        <p style="margin:0 0 8px;font-weight:600;">Key generated — copy it now, it won't be shown again.</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <code id="new-api-key" style="font-family:'DM Mono',monospace;font-size:13px;background:var(--bg3);padding:8px 12px;border-radius:8px;border:1px solid var(--border);word-break:break-all;">
            <?php echo htmlspecialchars($newly_generated_key); ?>
          </code>
          <button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('new-api-key').innerText.trim())">
            <i class="ti ti-copy"></i> Copy
          </button>
        </div>
        <p style="margin:10px 0 0;font-size:12px;color:var(--text7);">
          Give this to the client system to send as an <code>X-API-Key</code> header on every request. If it's lost, revoke this key below and generate a new one — the plaintext is never stored, so it can't be retrieved later.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($flash_error): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="ti ti-alert-circle"></i><div><?php echo htmlspecialchars($flash_error); ?></div></div>
  <?php endif; ?>

  <div class="two-col">
    <div>
      <div class="card" style="margin-bottom:20px;">
        <p class="card-title"><i class="ti ti-plus"></i> Generate New Key</p>
        <form method="POST">
          <div class="form-group">
            <label>Client / System Name</label>
            <input type="text" name="client_name" class="form-control" placeholder="e.g. FPST Inventory System" required>
          </div>
          <div class="form-group">
            <label>Allowed Course</label>
            <input type="text" name="allowed_course" class="form-control" placeholder="e.g. FPST — leave blank for unrestricted">
            <p style="font-size:11px;color:var(--text7);margin-top:4px;">
              This key can only read/write data for sections tagged with this course. Leave blank only for a fully-trusted internal key.
            </p>
          </div>
          <button type="submit" name="generate_key" class="btn btn-primary">
            <i class="ti ti-key"></i> Generate Key
          </button>
        </form>
      </div>

      <div class="card">
        <p class="card-title"><i class="ti ti-list"></i> Existing Keys</p>
        <?php if (empty($keys)): ?>
          <p style="font-size:13px;color:var(--text7);">No API keys yet.</p>
        <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>Client</th><th>Allowed Course</th><th>Prefix</th><th>Status</th><th>Created</th><th>Last Used</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($keys as $k): ?>
              <tr>
                <td><?php echo htmlspecialchars($k['client_name']); ?></td>
                <td>
                  <form method="POST" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="key_id" value="<?php echo (int)$k['id']; ?>">
                    <input type="text" name="new_allowed_course" value="<?php echo htmlspecialchars($k['allowed_course'] ?? ''); ?>"
                      placeholder="Unrestricted" class="form-control" style="font-size:12px;padding:4px 8px;width:110px;">
                    <button type="submit" name="update_course" class="btn btn-outline btn-sm" title="Save">
                      <i class="ti ti-check"></i>
                    </button>
                  </form>
                </td>
                <td><code style="font-size:11px;"><?php echo htmlspecialchars($k['key_prefix']); ?>&hellip;</code></td>
                <td>
                  <?php if ($k['is_active']): ?>
                    <span class="badge badge-green">Active</span>
                  <?php else: ?>
                    <span style="font-size:11px;font-weight:600;padding:3px 9px;border-radius:99px;background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25);">Revoked</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:12px;color:var(--text7);"><?php echo htmlspecialchars($k['created_at']); ?></td>
                <td style="font-size:12px;color:var(--text7);"><?php echo $k['last_used_at'] ? htmlspecialchars($k['last_used_at']) : 'Never'; ?></td>
                <td>
                  <?php if ($k['is_active']): ?>
                  <form method="POST" onsubmit="return confirm('Revoke this key? Anything using it will immediately lose access.');">
                    <input type="hidden" name="revoke_key_id" value="<?php echo (int)$k['id']; ?>">
                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--red);border-color:rgba(239,68,68,.35);">
                      <i class="ti ti-ban"></i> Revoke
                    </button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <div class="card">
        <p class="card-title"><i class="ti ti-activity"></i> Recent API Activity</p>
        <p style="font-size:12px;color:var(--text7);margin-top:-6px;margin-bottom:14px;">Last 25 calls across all keys.</p>
        <?php if (empty($recent_log)): ?>
          <p style="font-size:13px;color:var(--text7);">No API activity yet.</p>
        <?php else: ?>
          <ul style="font-size:12.5px;line-height:1.9;padding-left:0;margin:0;list-style:none;">
            <?php foreach ($recent_log as $l): ?>
            <li style="padding:8px 0;border-bottom:1px solid var(--border);">
              <?php if ($l['success']): ?><i class="ti ti-check" style="color:var(--green);"></i><?php else: ?><i class="ti ti-x" style="color:var(--red);"></i><?php endif; ?>
              <b><?php echo htmlspecialchars($l['client_name'] ?? 'Unknown key'); ?></b>
              &middot; <code style="font-size:11px;"><?php echo htmlspecialchars($l['endpoint']); ?></code>
              &middot; <span style="color:var(--text7);"><?php echo htmlspecialchars($l['ip_address']); ?></span>
              <br>
              <span style="color:var(--text7);font-size:11.5px;"><?php echo htmlspecialchars($l['created_at']); ?><?php if (!$l['success']) echo ' — ' . htmlspecialchars($l['message']); ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

</main>
</div>
</body>
</html>
