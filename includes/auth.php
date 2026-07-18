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
        header("Location: /classroom/login.php");
        exit;
    }
}

function requireRole(string $role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header($_SESSION['role'] === 'teacher'
            ? "Location: /classroom/teacher/dashboard.php"
            : "Location: /classroom/student/dashboard.php");
        exit;
    }
}