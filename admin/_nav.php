<?php
// ============================================================
//  admin/_nav.php  —  Shared admin sidebar
//  Included by every admin/*.php page. Expects $active_nav to
//  be set beforehand ('dashboard' | 'teachers' | 'sections' |
//  'students' | 'api_keys' | 'audit_log' | 'password_requests').
// ============================================================
$active_nav = $active_nav ?? '';

// Pending "Forgot password?" requests — shown as a badge on the nav link.
// Silently 0 if the table hasn't been created yet.
$pending_pw_requests = 0;
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $__r = $conn->query("SELECT COUNT(*) AS c FROM password_reset_requests WHERE status = 'pending'");
        $pending_pw_requests = (int)($__r->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) { /* table not created yet */ }
}
function _nav_class($key, $active) { return 'sidebar-link' . ($key === $active ? ' active' : ''); }
?>
<button class="mobile-menu-btn" onclick="document.querySelector('.sidebar').classList.toggle('is-open'); document.querySelector('.sidebar-overlay').classList.toggle('is-open');" aria-label="Toggle menu">
  <i class="ti ti-menu-2"></i>
</button>
<div class="sidebar-overlay" onclick="document.querySelector('.sidebar').classList.remove('is-open'); this.classList.remove('is-open');"></div>
<aside class="sidebar">
  <a class="sidebar-brand" href="/classroomv2/admin/dashboard.php">
    <img src="/classroomv2/assets/images/TCM logo (2).png" alt="TCM logo">
    <span>Classroom Management System</span>
  </a>
  <nav class="sidebar-links">
    <a href="/classroomv2/admin/dashboard.php" class="<?php echo _nav_class('dashboard', $active_nav); ?>"><i class="ti ti-layout-dashboard"></i><span>Dashboard</span></a>
    <a href="/classroomv2/admin/teachers.php" class="<?php echo _nav_class('teachers', $active_nav); ?>"><i class="ti ti-user-star"></i><span>Teachers</span></a>
    <a href="/classroomv2/admin/sections.php" class="<?php echo _nav_class('sections', $active_nav); ?>"><i class="ti ti-building-community"></i><span>Sections</span></a>
    <a href="/classroomv2/admin/students.php" class="<?php echo _nav_class('students', $active_nav); ?>"><i class="ti ti-users"></i><span>Students</span></a>
    <a href="/classroomv2/admin/api_keys.php" class="<?php echo _nav_class('api_keys', $active_nav); ?>"><i class="ti ti-key"></i><span>API Keys</span></a>
    <a href="/classroomv2/admin/password_requests.php" class="<?php echo _nav_class('password_requests', $active_nav); ?>"><i class="ti ti-lock-open"></i><span>Password Requests</span><?php if ($pending_pw_requests > 0): ?><span class="nav-badge"><?php echo $pending_pw_requests; ?></span><?php endif; ?></a>
    <a href="/classroomv2/admin/audit_log.php" class="<?php echo _nav_class('audit_log', $active_nav); ?>"><i class="ti ti-history"></i><span>Audit Log</span></a>
    <a href="/classroomv2/admin/settings.php" class="<?php echo _nav_class('settings', $active_nav); ?>"><i class="ti ti-settings"></i><span>Settings</span></a>
  </nav>
  <div class="sidebar-footer">
    <span class="sidebar-role role-admin">Admin</span>
    <div class="sidebar-username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
    <a href="/classroomv2/logout.php" class="sidebar-logout"><i class="ti ti-logout"></i><span>Logout</span></a>
    <div style="margin-top:10px;font-size:11px;opacity:.6;color:var(--text);">
      <a href="/classroomv2/privacy.php" style="color:var(--text);">Privacy</a> ·
      <a href="/classroomv2/terms.php" style="color:var(--text);">Terms</a> ·
      <a href="/classroomv2/cookie_policy.php" style="color:var(--text);">Cookies</a>
    </div>
  </div>
</aside>
<?php include __DIR__ . '/../includes/cookie_notice.php'; ?>
