<?php
// ============================================================
//  includes/header.php  —  Shared navigation
//
//  Variables you can set BEFORE including this file:
//    $page_title  — browser tab title
//    $active_nav  — which nav link to highlight
//                   ('dashboard','add_subject','scores','attendance','grades','reports')
//    $active_subject_id — ID of the currently viewed subject
//                         (highlights it in the dropdown)
// ============================================================
$page_title        = $page_title        ?? 'Classroom CMS';
$active_nav        = $active_nav        ?? '';
$active_subject_id = $active_subject_id ?? 0;
$user_role         = $_SESSION['role']     ?? 'guest';
$user_name         = $_SESSION['username'] ?? '';
$teacher_id        = $_SESSION['user_id']  ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?> — Classroom CMS</title>
  <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroom/assets/css/style.css">
</head>
<body>

<nav class="navbar">

  <!-- Brand -->
  <a class="brand" href="/classroom/<?php echo $user_role; ?>/dashboard.php">
    <span class="brand-dot"></span>
    Classroom CMS
  </a>

  <!-- Nav links -->
  <div class="nav-links">

    <?php if ($user_role === 'teacher'): ?>

      <!-- Dashboard -->
      <a href="/classroom/teacher/dashboard.php"
         class="<?php echo $active_nav==='dashboard'?'active':''; ?>">
        <i class="ti ti-layout-dashboard"></i> Dashboard
      </a>

      <!-- ── Subject-Section dropdown ── -->
      <?php
      // Fetch teacher's active subjects for the dropdown
      require_once __DIR__ . '/../config/db.php';
      $subjects_nav = getTeacherSubjects($conn, $teacher_id);
      $has_subjects = $subjects_nav->num_rows > 0;
      ?>
      <?php if ($has_subjects): ?>
      <div class="nav-dropdown" id="subjectDropdown">
        <button class="nav-dropdown-btn
          <?php echo in_array($active_nav, ['subject_view','scores','attendance_subject','grades_subject']) ? 'active' : ''; ?>"
          onclick="toggleDropdown()">
          <i class="ti ti-books"></i>
          <?php
          // If a subject is active, show its name in the button
          if ($active_subject_id) {
              $cur = $conn->prepare("SELECT subject_code, section FROM subjects WHERE id=?");
              $cur->bind_param("i", $active_subject_id);
              $cur->execute();
              $cur_sub = $cur->get_result()->fetch_assoc();
              echo htmlspecialchars($cur_sub['subject_code'].' — '.$cur_sub['section']);
          } else {
              echo 'My Subjects';
          }
          ?>
          <i class="ti ti-chevron-down" style="font-size:12px;margin-left:2px;"></i>
        </button>
        <div class="nav-dropdown-menu" id="subjectMenu">
          <?php
          $subjects_nav->data_seek(0);
          while ($sub = $subjects_nav->fetch_assoc()):
            $is_active = ((int)$active_subject_id === (int)$sub['id']);
            $type_dot  = match($sub['subject_type']) {
                'General Education'      => '#7aa3ff',
                'Professional Education' => '#4ade80',
                'Major Subject'          => '#fbbf24',
                default                  => '#8b95af',
            };
          ?>
          <a href="/classroom/teacher/subject_view.php?id=<?php echo $sub['id']; ?>"
             class="dropdown-item <?php echo $is_active?'active':''; ?>">
            <span class="dot" style="background:<?php echo $type_dot; ?>;"></span>
            <span class="di-main">
              <?php echo htmlspecialchars($sub['subject_code'].' '.$sub['subject_name']); ?>
            </span>
            <span class="di-sub">
              <?php echo htmlspecialchars($sub['section']); ?>
            </span>
          </a>
          <?php endwhile; ?>
          <div class="dropdown-divider"></div>
          <a href="/classroom/teacher/add_subject.php" class="dropdown-item">
            <i class="ti ti-plus" style="color:var(--accent);"></i>
            <span class="di-main" style="color:var(--accent);">Add New Subject</span>
          </a>
        </div>
      </div>
      <?php endif; ?>

      <!-- Add Subject (always visible) -->
      <a href="/classroom/teacher/add_subject.php"
         class="<?php echo $active_nav==='add_subject'?'active':''; ?>">
        <i class="ti ti-book-plus"></i> Add Subject
      </a>

      <!-- Students -->
      <a href="/classroom/teacher/students.php"
         class="<?php echo $active_nav==='students'?'active':''; ?>">
        <i class="ti ti-users"></i> Students
      </a>

    <?php else: ?>
      <!-- Student nav links -->
      <a href="/classroom/student/dashboard.php"   class="<?php echo $active_nav==='dashboard'  ?'active':''; ?>"><i class="ti ti-home"></i> Home</a>
      <a href="/classroom/student/subjects.php"    class="<?php echo $active_nav==='subjects'   ?'active':''; ?>"><i class="ti ti-books"></i> My Subjects</a>
      <a href="/classroom/student/class_report.php"class="<?php echo $active_nav==='report'     ?'active':''; ?>"><i class="ti ti-report-analytics"></i> Reports</a>
    <?php endif; ?>

  </div>

  <!-- Right side: role badge + username + logout -->
  <div class="nav-right">
    <span class="nav-role"><?php echo htmlspecialchars($user_role); ?></span>
    <span style="font-size:13px;color:var(--text2);">
      <?php echo htmlspecialchars($user_name); ?>
    </span>
    <a href="/classroom/logout.php" class="btn-logout">
      <i class="ti ti-logout"></i> Logout
    </a>
  </div>

</nav>

<script>
function toggleDropdown() {
  const menu = document.getElementById('subjectMenu');
  const btn  = document.querySelector('.nav-dropdown-btn');
  const open = menu.classList.toggle('open');
  btn.classList.toggle('open', open);
}
// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  const dd = document.getElementById('subjectDropdown');
  if (dd && !dd.contains(e.target)) {
    document.getElementById('subjectMenu')?.classList.remove('open');
    document.querySelector('.nav-dropdown-btn')?.classList.remove('open');
  }
});
</script>