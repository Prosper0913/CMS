<?php
// classroom/index.php — redirect to login or dashboard
session_start();
if (isset($_SESSION['role'])) {
    header("Location: " . ($_SESSION['role']==='teacher'
        ? '/classroom/teacher/dashboard.php'
        : '/classroom/student/dashboard.php'));
} else {
    header("Location: /classroom/login.php");
}
exit;