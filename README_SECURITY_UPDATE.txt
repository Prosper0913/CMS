TCM CMS - security update (supersedes tcm-hardening.zip)

BEFORE copying files: run migrations/security_hardening.sql on the live DB
(adds users.must_change_password + users.email_verified_at, fixes otp_codes.purpose,
adds a login-throttle index). Login reads must_change_password.

What's inside
- includes/csrf.php + includes/auth.php : central CSRF check on every POST of every logged-in page,
  token auto-added to forms/fetch, delete links carry a token, hardened session cookie
- first_login.php : forced new password + verified Gmail on first sign-in
- includes/contact_verify.php + settings pages (teacher/admin/student): email code required to change email/phone
- includes/login_throttle.php + login.php / loginv2.php : failed-login limits, session id regeneration
- includes/otp.php, includes/mailer.php : code helpers for the above
- teacher/students.php, teacher/import_students.php, admin/teachers.php : new/reset accounts get must_change_password=1
- otp_verify.php, reset_password.php : clear the flag when the user sets their own password
- teacher/subject_view.php : token on delete links and the two hidden delete forms
- api/rfid_scan.php, api/rfid_enroll.php : require the CSRF header
- config/secrets.example.php : REQUIRE_EMAIL_VERIFICATION valve (emergency only)
