<?php
// ============================================================
//  includes/auth.php  —  Session guard (unchanged from v1)
//
//  Usage at top of every protected page:
//    require_once '../includes/auth.php';
//    requireRole('teacher');   // or 'student'
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    // Hardened session cookie: HTTPS-only, not readable by JS, not sent cross-site.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Central CSRF protection: every POST to a page that includes this file must
// carry the session token (added automatically to forms / fetch by the script
// that csrf_inject_buffer() puts in the page <head>).
require_once __DIR__ . '/csrf.php';
csrf_enforce();
ob_start('csrf_inject_buffer');

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /classroomv2/login.php");
        exit;
    }
    // First sign-in with an admin/teacher-issued password: nothing else is
    // reachable until a personal password and a verified Gmail are set.
    if (!empty($_SESSION['must_change_password'])
        && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'first_login.php') {
        header("Location: /classroomv2/first_login.php");
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