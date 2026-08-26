<?php
// ============================================================
//  admin/_nav.php  —  Shared admin sidebar
//  Included by every admin/*.php page. Expects $active_nav to
//  be set beforehand ('dashboard' | 'teachers' | 'sections' |
//  'students' | 'import' | 'api_keys' | 'section_requests').
// ============================================================
$active_nav = $active_nav ?? '';
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
    <a href="/classroomv2/admin/import_students.php" class="<?php echo _nav_class('import', $active_nav); ?>"><i class="ti ti-file-import"></i><span>Import</span></a>
    <a href="/classroomv2/admin/api_keys.php" class="<?php echo _nav_class('api_keys', $active_nav); ?>"><i class="ti ti-key"></i><span>API Keys</span></a>
    <a href="/classroomv2/admin/section_requests.php" class="<?php echo _nav_class('section_requests', $active_nav); ?>"><i class="ti ti-hand-stop"></i><span>Section Requests</span></a>
  </nav>
  <div class="sidebar-footer">
    <span class="sidebar-role role-admin">Admin</span>
    <div class="sidebar-username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
    <a href="/classroomv2/logout.php" class="sidebar-logout"><i class="ti ti-logout"></i><span>Logout</span></a>
  </div>
</aside>
