<?php
// classroom/logout.php
session_start();

// Record the sign-out before the session (and who they were) is destroyed.
// Never let a logging problem stop someone from signing out.
if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/config/db.php';
        require_once __DIR__ . '/includes/audit.php';
        audit_log($conn, 'logout', 'success', [
            'user_id'  => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? null,
            'role'     => $_SESSION['role'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[logout] audit log skipped: ' . $e->getMessage());
    }
}

session_unset();
session_destroy();
header("Location: /classroomv2/login.php");
exit;