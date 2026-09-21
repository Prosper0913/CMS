<?php
// ============================================================
//  admin/audit_log.php
//  Security / sign-in audit trail.
//    - Who tried to get in (successful AND failed), from which IP,
//      on which device / browser / OS, and when
//    - Filter + search, paginated
//    - Export to CSV for a chosen date range
//    - Delete history (all, or a date range) — needs the admin's
//      password, and the deletion itself is recorded
// ============================================================
require_once '../includes/auth.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/audit.php';
require_once '../includes/csrf.php';

const AUDIT_PER_PAGE = 25;

$admin_id       = (int)$_SESSION['user_id'];
$admin_username = $_SESSION['username'] ?? '';

// ── Labels ───────────────────────────────────────────────────
$EVENTS = [
    'login'                => ['Sign in',                    'ti-login'],
    'logout'               => ['Sign out',                   'ti-logout'],
    'recovery_request'     => ['Password recovery request',  'ti-lock-question'],
    'recovery_link_issued' => ['Reset link issued',          'ti-link'],
    'recovery_completed'   => ['Password reset completed',   'ti-lock-check'],
    'recovery_link_invalid'=> ['Invalid reset link used',    'ti-lock-x'],
    'recovery_dismissed'   => ['Recovery request dismissed', 'ti-x'],
    'log_export'           => ['Audit log exported',         'ti-download'],
    'log_clear'            => ['Audit log deleted',          'ti-trash'],
];
$REASONS = [
    'unknown_user'       => 'Unknown account',
    'wrong_password'     => 'Wrong password',
    'rate_limited'       => 'Too many requests — blocked',
    'already_pending'    => 'Request already open',
    'invalid_or_expired' => 'Link invalid, expired or already used',
    'malformed_token'    => 'Malformed link',
];
$DEVICES = [
    'Desktop'    => 'ti-device-desktop',
    'Mobile'     => 'ti-device-mobile',
    'Tablet'     => 'ti-device-tablet',
    'Bot/Script' => 'ti-robot',
    'Unknown'    => 'ti-help',
];
$ROLE_BADGE = ['student' => 'badge-green', 'teacher' => 'badge-blue', 'admin' => 'badge-purple'];

// ── Helpers ──────────────────────────────────────────────────
function audit_valid_date($s): bool {
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** WHERE clause (+ bind types/params) for the given filters. */
function audit_build_where(array $f): array {
    $where = []; $types = ''; $params = [];
    if (!empty($f['from'])) { $where[] = "l.created_at >= ?";                         $types .= 's'; $params[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to']))   { $where[] = "l.created_at < DATE_ADD(?, INTERVAL 1 DAY)"; $types .= 's'; $params[] = $f['to']; }
    if (!empty($f['event']))   { $where[] = "l.event_type = ?";  $types .= 's'; $params[] = $f['event']; }
    if (!empty($f['outcome'])) { $where[] = "l.outcome = ?";     $types .= 's'; $params[] = $f['outcome']; }
    if (!empty($f['device']))  { $where[] = "l.device_type = ?"; $types .= 's'; $params[] = $f['device']; }
    if (!empty($f['q'])) {
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        $where[] = "(l.username LIKE ? OR l.ip_address LIKE ? OR l.browser LIKE ? OR l.os LIKE ? OR l.reason LIKE ?)";
        $types  .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $types, $params];
}

/** Stop spreadsheet apps from running attacker-controlled text as a formula. */
function audit_csv_safe($v): string {
    $v = (string)$v;
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) return "'" . $v;
    return $v;
}

function audit_flash_and_redirect(string $key, string $msg): void {
    $_SESSION[$key] = $msg;
    header("Location: audit_log.php");
    exit;
}

// ── One-time messages ────────────────────────────────────────
$flash_ok  = $_SESSION['audit_flash_ok']  ?? '';
$flash_err = $_SESSION['audit_flash_err'] ?? '';
unset($_SESSION['audit_flash_ok'], $_SESSION['audit_flash_err']);

// ── Is the table there? ──────────────────────────────────────
$table_ok = true;
try { $conn->query("SELECT 1 FROM audit_log LIMIT 1"); }
catch (Throwable $e) { $table_ok = false; }

// ============================================================
//  EXPORT (CSV) — GET ?export=1&export_from=YYYY-MM-DD&export_to=YYYY-MM-DD
//  Blank dates = everything.
// ============================================================
if ($table_ok && isset($_GET['export'])) {
    $ef = trim((string)($_GET['export_from'] ?? ''));
    $et = trim((string)($_GET['export_to']   ?? ''));

    if (($ef !== '' && !audit_valid_date($ef)) || ($et !== '' && !audit_valid_date($et))) {
        audit_flash_and_redirect('audit_flash_err', "Export cancelled: please pick valid dates.");
    }
    if ($ef !== '' && $et !== '' && $ef > $et) {
        audit_flash_and_redirect('audit_flash_err', "Export cancelled: the “from” date is after the “to” date.");
    }

    [$rangeWhere, $rangeTypes, $rangeParams] = audit_build_where(['from' => $ef, 'to' => $et]);
    $where = $rangeWhere === '' ? 'WHERE l.id > ?' : ($rangeWhere . ' AND l.id > ?');
    $sql   = "SELECT l.* FROM audit_log l $where ORDER BY l.id ASC LIMIT 2000";

    $label = ($ef !== '' ? $ef : 'start') . '_to_' . ($et !== '' ? $et : 'today');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . $label . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads it correctly
    fputcsv($out, ['ID','Date/Time','Event','Result','Account','Role','IP Address','Device Type','Browser','OS','Details','User Agent']);

    $lastId = 0; $count = 0;
    do {
        $stmt = $conn->prepare($sql);
        $types  = $rangeTypes . 'i';
        $params = array_merge($rangeParams, [$lastId]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $chunk = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($chunk as $r) {
            $reason = $REASONS[$r['reason']] ?? $r['reason'];
            fputcsv($out, [
                (int)$r['id'],
                $r['created_at'],
                $EVENTS[$r['event_type']][0] ?? $r['event_type'],
                $r['outcome'] === 'success' ? 'Success' : 'Failed',
                audit_csv_safe($r['username']),
                audit_csv_safe($r['role']),
                audit_csv_safe($r['ip_address']),
                audit_csv_safe($r['device_type']),
                audit_csv_safe($r['browser']),
                audit_csv_safe($r['os']),
                audit_csv_safe($reason),
                audit_csv_safe($r['user_agent']),
            ]);
            $lastId = (int)$r['id'];
            $count++;
        }
    } while (count($chunk) === 2000);
    fclose($out);

    audit_log($conn, 'log_export', 'success', [
        'user_id' => $admin_id, 'username' => $admin_username, 'role' => 'admin',
        'reason'  => "Exported $count entries (" . ($ef !== '' ? $ef : 'earliest') . " to " . ($et !== '' ? $et : 'latest') . ")",
    ]);
    exit;
}

// ============================================================
//  DELETE HISTORY — POST, needs CSRF + the admin's own password
// ============================================================
if ($table_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
    if (!csrf_verify()) {
        audit_flash_and_redirect('audit_flash_err', "Your session expired. Nothing was deleted — please try again.");
    }

    // 1) Re-verify the admin's password (same trimming as the sign-in form)
    $pw = trim((string)($_POST['confirm_password'] ?? ''));
    $pq = $conn->prepare("SELECT password FROM users WHERE id=? AND role='admin' LIMIT 1");
    $pq->bind_param('i', $admin_id);
    $pq->execute();
    $me = $pq->get_result()->fetch_assoc();

    if ($pw === '' || !$me || !password_verify($pw, $me['password'])) {
        audit_log($conn, 'log_clear', 'failure', [
            'user_id' => $admin_id, 'username' => $admin_username, 'role' => 'admin',
            'reason'  => 'wrong_password',
        ]);
        audit_flash_and_redirect('audit_flash_err', "Incorrect password. Nothing was deleted.");
    }

    // 2) What to delete
    $scope = $_POST['scope'] ?? '';
    if ($scope === 'all') {
        $conn->query("DELETE FROM audit_log");
        $deleted = $conn->affected_rows;
        $what    = 'all history';
    } elseif ($scope === 'range') {
        $df = trim((string)($_POST['del_from'] ?? ''));
        $dt = trim((string)($_POST['del_to']   ?? ''));
        if (!audit_valid_date($df) || !audit_valid_date($dt)) {
            audit_flash_and_redirect('audit_flash_err', "Please choose both a valid “from” and “to” date. Nothing was deleted.");
        }
        if ($df > $dt) {
            audit_flash_and_redirect('audit_flash_err', "The “from” date is after the “to” date. Nothing was deleted.");
        }
        $del = $conn->prepare(
            "DELETE FROM audit_log WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)"
        );
        $from_ts = $df . ' 00:00:00';
        $del->bind_param('ss', $from_ts, $dt);
        $del->execute();
        $deleted = $del->affected_rows;
        $what    = "$df to $dt";
    } else {
        audit_flash_and_redirect('audit_flash_err', "Choose what to delete. Nothing was deleted.");
    }

    // 3) Deleting history is itself recorded, and this entry survives.
    audit_log($conn, 'log_clear', 'success', [
        'user_id' => $admin_id, 'username' => $admin_username, 'role' => 'admin',
        'reason'  => "Deleted $deleted entries ($what)",
    ]);
    audit_flash_and_redirect('audit_flash_ok', "Deleted $deleted audit entries ($what).");
}

// ============================================================
//  VIEW
// ============================================================
$f = ['q' => '', 'event' => '', 'outcome' => '', 'device' => '', 'from' => '', 'to' => ''];
$stats = ['ok24' => 0, 'fail24' => 0, 'fail_ips' => 0]; $total_all = 0;
$top_fail = []; $rows = []; $total = 0; $pages = 1; $page = 1;

if ($table_ok) {
    $f['q'] = trim((string)($_GET['q'] ?? ''));
    $ev = (string)($_GET['event'] ?? '');    $f['event']   = isset($EVENTS[$ev]) ? $ev : '';
    $oc = (string)($_GET['outcome'] ?? '');  $f['outcome'] = in_array($oc, ['success', 'failure'], true) ? $oc : '';
    $dv = (string)($_GET['device'] ?? '');   $f['device']  = isset($DEVICES[$dv]) ? $dv : '';
    $fr = trim((string)($_GET['from'] ?? '')); $f['from']  = audit_valid_date($fr) ? $fr : '';
    $to = trim((string)($_GET['to']   ?? '')); $f['to']    = audit_valid_date($to) ? $to : '';

    // Summary cards (last 24 hours)
    $s = $conn->query(
        "SELECT
            SUM(event_type='login' AND outcome='success') AS ok24,
            SUM(event_type='login' AND outcome='failure') AS fail24,
            COUNT(DISTINCT CASE WHEN event_type='login' AND outcome='failure' THEN ip_address END) AS fail_ips
         FROM audit_log WHERE created_at >= (NOW() - INTERVAL 24 HOUR)"
    )->fetch_assoc();
    $stats = ['ok24' => (int)$s['ok24'], 'fail24' => (int)$s['fail24'], 'fail_ips' => (int)$s['fail_ips']];
    $total_all = (int)$conn->query("SELECT COUNT(*) AS c FROM audit_log")->fetch_assoc()['c'];

    $top_fail = $conn->query(
        "SELECT ip_address, COUNT(*) AS c, MAX(created_at) AS last_at
         FROM audit_log
         WHERE event_type='login' AND outcome='failure' AND created_at >= (NOW() - INTERVAL 24 HOUR)
         GROUP BY ip_address ORDER BY c DESC, last_at DESC LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);

    // Filtered list
    [$whereSql, $types, $params] = audit_build_where($f);
    $cs = $conn->prepare("SELECT COUNT(*) AS c FROM audit_log l $whereSql");
    if ($types !== '') $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['c'];

    $pages = max(1, (int)ceil($total / AUDIT_PER_PAGE));
    $page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $limit = AUDIT_PER_PAGE; $offset = ($page - 1) * AUDIT_PER_PAGE;

    $ls = $conn->prepare("SELECT l.* FROM audit_log l $whereSql ORDER BY l.id DESC LIMIT ? OFFSET ?");
    $ls->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
    $ls->execute();
    $rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
}

function audit_page_url(array $f, int $page): string {
    $q = array_filter($f, fn($v) => $v !== '');
    if ($page > 1) $q['page'] = $page;
    return 'audit_log.php' . ($q ? '?' . http_build_query($q) : '');
}
$has_filters = (bool)array_filter($f, fn($v) => $v !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Audit Log — Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
  <style>
    /* assets/style.css gives .stat-card a fixed width (1350px), which stretched these
       cards far past the screen. Keep them fluid on this page: equal columns that
       shrink/grow with the window and wrap on small screens. */
    .page-admin-audit .stats-row{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
      gap:14px;
      margin-bottom:20px;
    }
    .page-admin-audit .stat-card{
      width:auto;
      min-width:0;
      place-items:stretch;
    }
  </style>
</head>
<body class="page-admin-audit">
<div class="app-shell">

<?php $active_nav = 'audit_log'; include __DIR__ . '/_nav.php'; ?>
<main class="main-content">

<div class="page-wrap">
  <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
    <div>
      <h1><i class="ti ti-history text-accent"></i> Audit Log</h1>
      <p>Every sign-in attempt (successful and failed) with the IP address, device and browser it came from, plus password-recovery and log-management activity.</p>
    </div>
    <?php if ($table_ok): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="button" class="btn btn-outline btn-sm" onclick="openModal('exportModal')"><i class="ti ti-download"></i> Export</button>
      <button type="button" class="btn btn-outline btn-sm" style="color:var(--red);border-color:rgba(239,68,68,.35);" onclick="openModal('clearModal')"><i class="ti ti-trash"></i> Delete history</button>
    </div>
    <?php endif; ?>
  </div>
  <hr class="thin-line" style="margin-bottom: 25px;">

  <?php if ($flash_ok): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><i class="ti ti-circle-check"></i><div><?php echo htmlspecialchars($flash_ok); ?></div></div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><i class="ti ti-alert-circle"></i><div><?php echo htmlspecialchars($flash_err); ?></div></div>
  <?php endif; ?>

  <?php if (!$table_ok): ?>
    <div class="alert alert-error"><i class="ti ti-alert-circle"></i>
      <div>The <code>audit_log</code> table doesn't exist yet. Run <code>audit_and_recovery.sql</code> on the database, then reload this page.</div>
    </div>
  <?php else: ?>

  <!-- Summary -->
  <div class="stats-row">
    <div class="stat-card stat-accent">
      <div class="stat-label">Successful sign-ins</div>
      <div class="stat-value"><?php echo $stats['ok24']; ?></div>
      <div class="stat-sub">last 24 hours</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Failed sign-ins</div>
      <div class="stat-value" style="<?php echo $stats['fail24'] > 0 ? 'color:var(--red);' : ''; ?>"><?php echo $stats['fail24']; ?></div>
      <div class="stat-sub">last 24 hours</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">IPs with failed sign-ins</div>
      <div class="stat-value"><?php echo $stats['fail_ips']; ?></div>
      <div class="stat-sub">last 24 hours</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Entries stored</div>
      <div class="stat-value"><?php echo $total_all; ?></div>
      <div class="stat-sub">in the audit log</div>
    </div>
  </div>

  <?php if (!empty($top_fail)): ?>
  <div class="card" style="margin-bottom:20px;">
    <p class="card-title"><i class="ti ti-alert-triangle"></i> Most failed sign-ins — last 24 hours</p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
      <?php foreach ($top_fail as $t): ?>
        <a href="<?php echo htmlspecialchars('audit_log.php?' . http_build_query(['q' => $t['ip_address'], 'event' => 'login', 'outcome' => 'failure'])); ?>"
           style="text-decoration:none;color:inherit;border:1px solid var(--border);border-radius:10px;padding:8px 12px;font-size:12.5px;">
          <code><?php echo htmlspecialchars($t['ip_address']); ?></code>
          <span class="badge badge-red" style="margin-left:6px;"><?php echo (int)$t['c']; ?> failed</span>
          <div style="font-size:11px;color:var(--text7);margin-top:3px;">last <?php echo htmlspecialchars($t['last_at']); ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <!-- Filters -->
    <form method="GET" class="search-bar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
      <div class="input-wrap" style="flex:1;min-width:190px;">
        <i class="ti ti-search"></i>
        <input type="text" name="q" class="form-control" placeholder="Search username, IP, browser, OS, details…"
               value="<?php echo htmlspecialchars($f['q']); ?>">
      </div>
      <select name="event" class="form-control" style="max-width:210px;">
        <option value="">All events</option>
        <?php foreach ($EVENTS as $k => $e): ?>
          <option value="<?php echo $k; ?>" <?php echo $f['event'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($e[0]); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="outcome" class="form-control" style="max-width:140px;">
        <option value="">Any result</option>
        <option value="success" <?php echo $f['outcome'] === 'success' ? 'selected' : ''; ?>>Successful</option>
        <option value="failure" <?php echo $f['outcome'] === 'failure' ? 'selected' : ''; ?>>Failed</option>
      </select>
      <select name="device" class="form-control" style="max-width:140px;">
        <option value="">Any device</option>
        <?php foreach ($DEVICES as $k => $ic): ?>
          <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $f['device'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($k); ?></option>
        <?php endforeach; ?>
      </select>
      <div>
        <label style="display:block;font-size:11px;color:var(--text7);margin-bottom:3px;">From</label>
        <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($f['from']); ?>">
      </div>
      <div>
        <label style="display:block;font-size:11px;color:var(--text7);margin-bottom:3px;">To</label>
        <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($f['to']); ?>">
      </div>
      <button type="submit" class="btn btn-outline btn-sm">Filter</button>
      <?php if ($has_filters): ?>
        <a href="audit_log.php" class="btn btn-outline btn-sm"><i class="ti ti-x"></i> Clear</a>
      <?php endif; ?>
    </form>

    <div style="font-size:12px;color:var(--text7);margin-bottom:10px;">
      <?php echo number_format($total); ?> <?php echo $total === 1 ? 'entry' : 'entries'; ?><?php echo $has_filters ? ' match your filters' : ''; ?>
      <?php if ($total > 0): ?> &middot; page <?php echo $page; ?> of <?php echo $pages; ?><?php endif; ?>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Date / Time</th><th>Event</th><th>Result</th><th>Account</th><th>IP Address</th><th>Device</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7"><div class="empty-state"><i class="ti ti-history-off"></i><p><?php echo $has_filters ? 'No entries match those filters.' : 'No activity recorded yet.'; ?></p></div></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $ev   = $EVENTS[$r['event_type']] ?? [$r['event_type'], 'ti-point'];
            $dic  = $DEVICES[$r['device_type']] ?? 'ti-help';
            $reason = $r['reason'] !== null && $r['reason'] !== '' ? ($REASONS[$r['reason']] ?? $r['reason']) : '';
        ?>
          <tr>
            <td style="font-size:12px;color:var(--text7);white-space:nowrap;"><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td style="white-space:nowrap;"><i class="ti <?php echo htmlspecialchars($ev[1]); ?>" style="color:var(--text7);"></i> <?php echo htmlspecialchars($ev[0]); ?></td>
            <td>
              <?php if ($r['outcome'] === 'success'): ?>
                <span class="badge badge-green">Success</span>
              <?php else: ?>
                <span class="badge badge-red">Failed</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['username'] !== null && $r['username'] !== ''): ?>
                <code style="font-size:12px;"><?php echo htmlspecialchars($r['username']); ?></code>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              <?php if ($r['role']): ?>
                <span class="badge <?php echo $ROLE_BADGE[$r['role']] ?? 'badge-gray'; ?>" style="margin-left:4px;"><?php echo htmlspecialchars(ucfirst($r['role'])); ?></span>
              <?php endif; ?>
            </td>
            <td><code style="font-size:12px;"><?php echo htmlspecialchars($r['ip_address']); ?></code></td>
            <td style="font-size:12.5px;" title="<?php echo htmlspecialchars($r['user_agent'] ?? ''); ?>">
              <i class="ti <?php echo $dic; ?>" style="color:var(--text7);"></i>
              <?php echo htmlspecialchars($r['device_type'] ?? 'Unknown'); ?>
              <div style="font-size:11px;color:var(--text7);"><?php echo htmlspecialchars(trim(($r['browser'] ?? '') . ' · ' . ($r['os'] ?? ''), ' ·')); ?></div>
            </td>
            <td style="font-size:12.5px;"><?php echo $reason !== '' ? htmlspecialchars($reason) : '<span class="text-muted">—</span>'; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;align-items:center;margin-top:16px;flex-wrap:wrap;">
      <?php if ($page > 1): ?>
        <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars(audit_page_url($f, $page - 1)); ?>"><i class="ti ti-chevron-left"></i> Prev</a>
      <?php endif; ?>
      <?php
        $from_p = max(1, $page - 2); $to_p = min($pages, $page + 2);
        if ($from_p > 1) { echo '<a class="btn btn-outline btn-sm" href="' . htmlspecialchars(audit_page_url($f, 1)) . '">1</a>'; if ($from_p > 2) echo '<span class="text-muted">…</span>'; }
        for ($p = $from_p; $p <= $to_p; $p++):
      ?>
        <a class="btn btn-sm <?php echo $p === $page ? 'btn-primary' : 'btn-outline'; ?>" href="<?php echo htmlspecialchars(audit_page_url($f, $p)); ?>"><?php echo $p; ?></a>
      <?php endfor;
        if ($to_p < $pages) { if ($to_p < $pages - 1) echo '<span class="text-muted">…</span>'; echo '<a class="btn btn-outline btn-sm" href="' . htmlspecialchars(audit_page_url($f, $pages)) . '">' . $pages . '</a>'; }
      ?>
      <?php if ($page < $pages): ?>
        <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars(audit_page_url($f, $page + 1)); ?>">Next <i class="ti ti-chevron-right"></i></a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

<?php if ($table_ok): ?>
<!-- ── EXPORT MODAL ── -->
<div class="modal-overlay" id="exportModal">
  <div class="modal">
    <h3><i class="ti ti-download text-accent"></i> Export audit log</h3>
    <p class="modal-sub">Downloads a CSV (opens in Excel / Sheets) with every entry in the date range you choose. Leave a date blank for no limit on that side.</p>
    <form method="GET">
      <input type="hidden" name="export" value="1">
      <div class="form-group">
        <label>From date</label>
        <input type="date" name="export_from" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d', strtotime('-30 days'))); ?>">
      </div>
      <div class="form-group">
        <label>To date</label>
        <input type="date" name="export_to" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" class="btn btn-sm btn-primary" onclick="setTimeout(function(){closeModal('exportModal')},300)"><i class="ti ti-download"></i> Download CSV</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('exportModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE HISTORY MODAL ── -->
<div class="modal-overlay" id="clearModal">
  <div class="modal">
    <h3><i class="ti ti-trash" style="color:var(--red);"></i> Delete audit history</h3>
    <p class="modal-sub">This permanently removes audit entries and can't be undone. Export first if you need a copy. The deletion itself is recorded in the log.</p>
    <form method="POST" id="clearForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="clear_logs" value="1">
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
          <input type="radio" name="scope" value="range" checked onchange="toggleScope()"> Only entries in a date range
        </label>
        <div id="rangeFields" style="display:flex;gap:8px;margin:8px 0 0 22px;">
          <input type="date" name="del_from" class="form-control" title="From date">
          <input type="date" name="del_to" class="form-control" title="To date">
        </div>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
          <input type="radio" name="scope" value="all" onchange="toggleScope()"> All history
        </label>
      </div>
      <div class="form-group">
        <label>Confirm with your password <span class="text-red">*</span></label>
        <input type="password" name="confirm_password" class="form-control" required autocomplete="current-password" placeholder="Your admin password">
      </div>
      <div style="display:flex;gap:8px;margin-top:4px;">
        <button type="submit" class="btn btn-sm btn-primary" style="background:var(--red);border-color:var(--red);"><i class="ti ti-trash"></i> Delete</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('clearModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(function(m){
  m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('open'); });
});
function toggleScope(){
  var all = document.querySelector('#clearForm input[name=scope][value=all]').checked;
  var box = document.getElementById('rangeFields');
  box.style.opacity = all ? '.4' : '1';
  box.querySelectorAll('input').forEach(function(i){ i.disabled = all; });
}
document.getElementById('clearForm').addEventListener('submit', function(e){
  var all = document.querySelector('#clearForm input[name=scope][value=all]').checked;
  var msg = all ? 'Permanently delete ALL audit history?' : 'Permanently delete the audit entries in this date range?';
  if (!confirm(msg)) e.preventDefault();
});
</script>
<?php endif; ?>

</main>
</div>
</body>
</html>