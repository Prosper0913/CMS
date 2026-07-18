<?php
// ============================================================
//  student/subjects.php
//  Lists all enrolled subjects. Clicking one opens
//  student/subject_detail.php for that subject.
//  This page is essentially the student's grade report per subject.
// ============================================================
require_once '../includes/auth.php';
requireRole('student');
require_once '../config/db.php';

$sid = $_SESSION['student_id'];

// Fetch enrolled subjects with full grade breakdown
$res = $conn->prepare(
    "SELECT sub.*,
            COALESCE(g.final_grade,0)       AS final_grade,
            COALESCE(g.letter_grade,'N/A')  AS letter_grade,
            COALESCE(g.exam_avg,0)          AS exam_avg,
            COALESCE(g.written_avg,0)       AS written_avg,
            COALESCE(g.performance_avg,0)   AS perf_avg,
            COALESCE(g.attendance_rate,0)   AS att_rate,
            COALESCE(g.performance_component,0) AS perf_component
     FROM subject_enrollments e
     JOIN subjects sub ON sub.id=e.subject_id
     LEFT JOIN subject_grades g ON g.subject_id=e.subject_id AND g.student_id=e.student_id
     WHERE e.student_id=? AND sub.is_active=1
     ORDER BY sub.subject_name ASC"
);
$res->bind_param("s",$sid);
$res->execute();
$subs = $res->get_result();

$type_colors = [
    'General Education'      => '#7aa3ff',
    'Professional Education' => '#34d399',
    'Major Subject'          => '#fbbf24',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>My Subjects — Classroom CMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="/classroom/assets/style.css">

</head>
<body class="page-student-subjects">

<nav class="navbar">
  <a class="brand" href="/classroom/student/dashboard.php"><img src="/classroom/assets/images/TCM logo (2).png" alt="Classroom Management System" width="32" height="32"></span>Classroom Management System</a>
  <div class="nav-sep"></div>
  <a href="/classroom/student/dashboard.php" class="nav-link"><i class="ti ti-home"></i> Home</a>
  <a href="/classroom/student/subjects.php"  class="nav-link active"><i class="ti ti-books"></i> My Subjects</a>
  <div class="nav-right">
    <span class="nav-role">Student</span>
    <span style="font-size:13px;color:var(--text2);"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
    <a href="/classroom/logout.php" class="btn-logout"><i class="ti ti-logout"></i> Logout</a>
  </div>
</nav>

<div class="page-wrap">
  <div class="page-header">
    <h1><i class="ti ti-books" style="color:var(--accent)"></i> My Subjects</h1>
    <p>Your enrolled subjects and grade breakdown for the current semester.</p>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Subject</th>
            <th>Type</th>
            <th>Exam Avg</th>
            <th>Written Avg</th>
            <th>Performance</th>
            <th>Attendance</th>
            <th>Final Grade</th>
            <th>Letter</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($subs->num_rows === 0): ?>
          <tr><td colspan="9">
            <div class="empty-state">
              <i class="ti ti-books"></i>
              <p>You are not enrolled in any subjects yet.</p>
            </div>
          </td></tr>
          <?php endif; ?>

          <?php while ($sub = $subs->fetch_assoc()):
            $fg   = (float)$sub['final_grade'];
            $pass = $fg >= 75;
            $tc   = $type_colors[$sub['subject_type']] ?? '#7aa3ff';
            $letter_badge = match(true){
              $fg>=85=>'badge-green',$fg>=75=>'badge-blue',
              $fg>=70=>'badge-yellow',$fg>0=>'badge-red',default=>''
            };
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="type-dot" style="background:<?php echo $tc; ?>;box-shadow:0 0 5px <?php echo $tc; ?>33;"></span>
                <div>
                  <div style="font-weight:600;"><?php echo htmlspecialchars($sub['subject_name']); ?></div>
                  <div class="td-mono"><?php echo htmlspecialchars($sub['subject_code']); ?> · <?php echo htmlspecialchars($sub['section']); ?></div>
                </div>
              </div>
            </td>
            <td>
              <span style="font-size:11px;padding:2px 8px;border-radius:99px;border:1px solid <?php echo $tc; ?>44;color:<?php echo $tc; ?>;background:<?php echo $tc; ?>15;">
                <?php echo $sub['subject_type']; ?>
              </span>
            </td>
            <?php
            $bars = [
              [(float)$sub['exam_avg'],'#7aa3ff'],
              [(float)$sub['written_avg'],'#34d399'],
              [(float)$sub['perf_avg'],'#fbbf24'],
              [(float)$sub['att_rate'],'#a78bfa'],
            ];
            foreach($bars as [$val,$color]):
              $v=(float)$val;
            ?>
            <td>
              <div class="score-bar-wrap">
                <div class="score-bar-track">
                  <div class="score-bar-fill" style="width:<?php echo min($v,100);?>%;background:<?php echo $color;?>;opacity:.8;"></div>
                </div>
                <span style="font-size:12px;min-width:36px;text-align:right;color:<?php echo $v>=75?'var(--green)':($v>0?'var(--red)':'var(--text3)');?>;">
                  <?php echo $v>0?number_format($v,1).'%':'—'; ?>
                </span>
              </div>
            </td>
            <?php endforeach; ?>
            <td>
              <span style="font-family:var(--font-head);font-size:17px;font-weight:700;color:<?php echo $fg>=75?'var(--green)':($fg>0?'var(--red)':'var(--text3)');?>;">
                <?php echo $fg>0?number_format($fg,2).'%':'—'; ?>
              </span>
            </td>
            <td>
              <?php if ($fg>0): ?>
              <span class="badge <?php echo $letter_badge;?>" style="font-size:12px;padding:3px 10px;">
                <?php echo $sub['letter_grade']; ?>
              </span>
              <?php else: ?>
              <span style="font-size:11px;color:var(--text3);">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/classroom/student/subject_detail.php?id=<?php echo $sub['id']; ?>" class="btn-view">
                <i class="ti ti-eye"></i> View
              </a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</body>
</html>
