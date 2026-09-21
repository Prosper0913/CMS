<?php
// ============================================================
//  includes/csrf.php  —  Tiny CSRF helper (session-based token)
//
//  In a form:      echo csrf_field();     (prints the hidden input)
//  In the handler: if (!csrf_verify()) { ...reject... }
// ============================================================

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}
