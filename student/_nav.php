<?php
// ============================================================
//  student/_nav.php  —  Shared student sidebar
//  Include this and set $active_nav beforehand:
//  'dashboard' | 'subjects' | 'notifications'
//  Expects $conn and $sid to already be set by the including page.
// ============================================================
$active_nav = $active_nav ?? '';
$unread_count = getUnreadNotificationCount($conn, $sid);
function _nav_class($key, $active) { return 'sidebar-link' . ($key === $active ? ' active' : ''); }
?>
<button class="mobile-menu-btn" onclick="document.querySelector('.sidebar').classList.toggle('is-open'); document.querySelector('.sidebar-overlay').classList.toggle('is-open');" aria-label="Toggle menu">
  <i class="ti ti-menu-2"></i>
</button>
<div class="sidebar-overlay" onclick="document.querySelector('.sidebar').classList.remove('is-open'); this.classList.remove('is-open');"></div>
<aside class="sidebar">
  <a class="sidebar-brand" href="/classroomv2/student/dashboard.php">
    <img src="/classroomv2/assets/images/TCM logo (2).png" alt="TCM logo">
    <span>Classroom Management System</span>
  </a>
  <nav class="sidebar-links">
    <a href="/classroomv2/student/dashboard.php" class="<?php echo _nav_class('dashboard', $active_nav); ?>"><i class="ti ti-home"></i><span>Home</span></a>
    <a href="/classroomv2/student/subjects.php" class="<?php echo _nav_class('subjects', $active_nav); ?>"><i class="ti ti-books"></i><span>My Subjects</span></a>
    <a href="/classroomv2/student/notifications.php" class="<?php echo _nav_class('notifications', $active_nav); ?>">
      <i class="ti ti-bell"></i><span>Notifications</span>
      <?php if ($unread_count > 0): ?><span class="badge-count"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span><?php endif; ?>
    </a>
  </nav>
  <div class="sidebar-footer">
    <span class="sidebar-role role-student">Student</span>
    <div class="sidebar-username"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></div>
    <a href="/classroomv2/logout.php" class="sidebar-logout"><i class="ti ti-logout"></i><span>Logout</span></a>
  </div>
</aside>
