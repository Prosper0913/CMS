<?php
// ============================================================
//  teacher/_nav.php  —  Shared teacher sidebar
//  Include this and set $active_nav beforehand:
//  'dashboard' | 'subjects' | 'students' | 'sections' | 'biometric'
// ============================================================
$active_nav = $active_nav ?? '';
function _nav_class($key, $active) { return 'sidebar-link' . ($key === $active ? ' active' : ''); }
?>
<aside class="sidebar">
  <a class="sidebar-brand" href="/classroomv2/teacher/dashboard.php">
    <img src="/classroomv2/assets/images/TCM logo (2).png" alt="TCM logo">
    <span>Classroom Management System</span>
  </a>
  <nav class="sidebar-links">
    <a href="/classroomv2/teacher/dashboard.php" class="<?php echo _nav_class('dashboard', $active_nav); ?>"><i class="ti ti-layout-dashboard"></i><span>Dashboard</span></a>
    <a href="/classroomv2/teacher/all_subjects.php" class="<?php echo _nav_class('subjects', $active_nav); ?>"><i class="ti ti-books"></i><span>My Subjects</span></a>
    <a href="/classroomv2/teacher/students.php" class="<?php echo _nav_class('students', $active_nav); ?>"><i class="ti ti-users"></i><span>Students</span></a>
    <a href="/classroomv2/teacher/manage_sections.php" class="<?php echo _nav_class('sections', $active_nav); ?>"><i class="ti ti-building-community"></i><span>Sections</span></a>
    <a href="/classroomv2/teacher/biometric.php" class="<?php echo _nav_class('biometric', $active_nav); ?>"><i class="ti ti-fingerprint"></i><span>Biometric</span></a>
  </nav>
  <div class="sidebar-footer">
    <span class="sidebar-role role-teacher">Teacher</span>
    <div class="sidebar-username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
    <a href="/classroomv2/logout.php" class="sidebar-logout"><i class="ti ti-logout"></i><span>Logout</span></a>
  </div>
</aside>
