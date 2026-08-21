<?php
// classroom/index.php — redirect to login or dashboard
session_start();
if (isset($_SESSION['role'])) {
    header("Location: " . ($_SESSION['role']==='teacher'
        ? '/classroomv2/teacher/dashboard.php'
        : '/classroomv2/student/dashboard.php'));
} else {
    header("Location: /classroomv2/login.php");
}
exit;