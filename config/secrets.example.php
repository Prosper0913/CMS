<?php
// ============================================================
//  config/secrets.example.php
//  Copy this file to config/secrets.php and fill in real values.
//  config/secrets.php is listed in .gitignore and is never committed.
// ============================================================

// Gmail SMTP — used to send one-time password-reset codes.
// SMTP_PASS must be a 16-character Gmail "App Password"
// (Google Account -> Security -> 2-Step Verification -> App passwords),
// NOT your normal Gmail account password.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'xxxx xxxx xxxx xxxx');
define('SMTP_FROM_NAME', 'Classroom CMS');
