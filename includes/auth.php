<?php
// ============================================================
//  includes/auth.php  —  Session guard (unchanged from v1)
//
//  Usage at top of every protected page:
//    require_once '../includes/auth.php';
//    requireRole('teacher');   // or 'student'
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /classroomv2/login.php");
        exit;
    }
}

function requireRole(string $role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        $redirect = match ($_SESSION['role']) {
            'teacher' => "/classroomv2/teacher/dashboard.php",
            'student' => "/classroomv2/student/dashboard.php",
            'admin'   => "/classroomv2/admin/dashboard.php",
            default   => "/classroomv2/login.php",
        };
        header("Location: $redirect");
        exit;
    }
}