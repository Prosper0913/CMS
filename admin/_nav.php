<?php
// ============================================================
//  admin/_nav.php  —  Shared admin navbar
//  Included by every admin/*.php page. Expects $active_nav to
//  be set beforehand ('dashboard' | 'teachers' | 'students').
// ============================================================
$active_nav = $active_nav ?? '';
?>
 <nav class="navbar"> <!--style="height:56px;background:var(--bg);border-bottom:1px solid var(--border); 
  display:flex;align-items:center;padding:0 28px;gap:4px;position:sticky;top:0;z-index:100;">-->
  <a class="brand" href="/classroomv2/admin/dashboard.php"
    style="font-family:var(--font-head);font-size:15px;font-weight:700;color:var(--text);
    text-decoration:none;display:flex;align-items:center;gap:8px;flex-shrink:0;margin-right:8px;">
<img src="/classroomv2/assets/images/TCM logo (2).png" alt="TCM logo" width="32" height="32">
    Classroom Management System
  </a>
  <div style="width:1px;height:20px;background:var(--border2);margin:0 6px;"></div>
  <a href="/classroomv2/admin/dashboard.php"
    style="font-size:13px;font-weight:500;text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;
    <?php echo $active_nav==='dashboard' ? 'background:var(--bg3);color:var(--text);' : 'color:var(--text2);'; ?>"
    class="nav-link"><i class="ti ti-layout-dashboard"></i> Dashboard</a>
  <a href="/classroomv2/admin/teachers.php"
    style="font-size:13px;font-weight:500;text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;
    <?php echo $active_nav==='teachers' ? 'background:var(--bg3);color:var(--text);' : 'color:var(--text2);'; ?>"
    class="nav-link"><i class="ti ti-user-star"></i> Teachers</a>
  <a href="/classroomv2/admin/sections.php"
    style="font-size:13px;font-weight:500;text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;
    <?php echo $active_nav==='sections' ? 'background:var(--bg3);color:var(--text);' : 'color:var(--text2);'; ?>"
    class="nav-link"><i class="ti ti-building-community"></i> Sections</a>
  <a href="/classroomv2/admin/students.php"
    style="font-size:13px;font-weight:500;text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;
    <?php echo $active_nav==='students' ? 'background:var(--bg3);color:var(--text);' : 'color:var(--text2);'; ?>"
    class="nav-link"><i class="ti ti-users"></i> Students</a>
  <a href="/classroomv2/admin/import_students.php"
    style="font-size:13px;font-weight:500;text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;
    <?php echo $active_nav==='import' ? 'background:var(--bg3);color:var(--text);' : 'color:var(--text2);'; ?>"
    class="nav-link"><i class="ti ti-file-import"></i> Import</a>
  <a href="/classroomv2/admin/api_keys.php"
    style="font-size:13px;font-weight:500;text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;
    <?php echo $active_nav==='api_keys' ? 'background:var(--bg3);color:var(--text);' : 'color:var(--text2);'; ?>"
    class="nav-link"><i class="ti ti-key"></i> API Keys</a>
  <a href="/classroomv2/admin/section_requests.php"
    style="font-size:13px;font-weight:500;text-decoration:none;
    padding:5px 11px;border-radius:8px;display:flex;align-items:center;gap:5px;
    <?php echo $active_nav==='section_requests' ? 'background:var(--bg3);color:var(--text);' : 'color:var(--text2);'; ?>"
    class="nav-link"><i class="ti ti-hand-stop"></i> Section Requests</a>
  <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
    <span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;
      padding:3px 9px;border-radius:99px;background:rgba(239,68,68,.12);color:var(--red);
      border:1px solid rgba(239,68,68,.25);">Admin</span>
    <span style="font-size:13px;color:var(--text2);"><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></span>
    <a href="/classroomv2/logout.php"
      style="font-size:12px;padding:5px 12px;border-radius:8px;background:transparent;
      border:1px solid var(--border2);color:var(--text2);cursor:pointer;text-decoration:none;
      display:inline-flex;align-items:center;gap:5px;">
      <i class="ti ti-logout"></i> Logout
    </a>
  </div>
</nav>
