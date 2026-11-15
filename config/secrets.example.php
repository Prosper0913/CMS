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
// 'tls' = STARTTLS (ports 587 / 2525, default), 'ssl' = implicit TLS (port 465).
// NOTE: DigitalOcean blocks outbound ports 25/465/587 on most droplets. If the
// server cannot reach smtp.gmail.com, use a provider that offers port 2525
// (Brevo, Mailgun, SendGrid, Mailjet ...) and put its host/user/key above.
define('SMTP_SECURE', 'tls');
// Only needed when SMTP_USER is not itself an email address (most relay providers):
// define('SMTP_FROM_EMAIL', 'noreply@yourdomain.com');

// Emergency valve: set to false ONLY if the school email (SMTP) is down and
// people cannot receive codes. First-login setup and contact changes then
// skip the emailed code. Leave true normally.
// define('REQUIRE_EMAIL_VERIFICATION', true);
