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

// ── Integration keys (shared secrets with the other systems) ─────
// Generate each one with:   openssl rand -hex 32
// Each must match the value configured on the receiving system.
define('GUIDANCE_SYNC_KEY',    'put-a-64-char-hex-key-here');  // sent to Guidance (X-Sync-Key)
define('OCES_SYNC_KEY',        'put-a-64-char-hex-key-here');  // sent to OCES (X-Sync-Key)
define('TOOLTRACK_RECEIVE_KEY','put-a-64-char-hex-key-here');  // sent to Tooltrack (X-Tooltrack-Key)
define('REFERRAL_RECEIVE_KEY', 'put-a-64-char-hex-key-here');  // Guidance -> CMS api/receive_referral_flag.php
