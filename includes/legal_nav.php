<?php
// ============================================================
//  includes/legal_nav.php
//  Shared top bar for the public legal pages (privacy.php,
//  terms.php, cookie_policy.php). Works whether the visitor is
//  logged in or not — "Back" goes to their dashboard if they have
//  a session, otherwise to the sign-in page.
//  Expects $active_legal to be set by the including page to one
//  of: 'privacy', 'terms', 'cookies'.
// ============================================================
$back_url = '/classroomv2/login.php';
if (!empty($_SESSION['role'])) {
    $back_url = match ($_SESSION['role']) {
        'teacher' => '/classroomv2/teacher/dashboard.php',
        'admin'   => '/classroomv2/admin/dashboard.php',
        default   => '/classroomv2/student/dashboard.php',
    };
}
$active_legal = $active_legal ?? '';
?>
<div class="legal-topbar">
  <a href="<?php echo $back_url; ?>" class="legal-brand">
    <img src="/classroomv2/assets/images/TCM logo (2).png" alt="TCM logo">
    <span>Classroom Management System</span>
  </a>
  <nav class="legal-toplinks">
    <a href="/classroomv2/privacy.php" class="<?php echo $active_legal === 'privacy' ? 'is-active' : ''; ?>">Privacy Policy</a>
    <a href="/classroomv2/terms.php" class="<?php echo $active_legal === 'terms' ? 'is-active' : ''; ?>">Terms &amp; Conditions</a>
    <a href="/classroomv2/cookie_policy.php" class="<?php echo $active_legal === 'cookies' ? 'is-active' : ''; ?>">Cookie Policy</a>
  </nav>
  <a href="<?php echo $back_url; ?>" class="legal-back"><i class="ti ti-arrow-left"></i> Back</a>
</div>
