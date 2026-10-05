<?php
// ============================================================
//  terms.php — Terms and Conditions
//  Public page: readable whether signed in or not.
// ============================================================
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Terms &amp; Conditions — Classroom CMS</title>
  <link rel="icon" type="image/png" href="assets/images/TCM logo (2).png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-legal">

<?php $active_legal = 'terms'; include __DIR__ . '/includes/legal_nav.php'; ?>

<div class="legal-wrap">
  <h1>Terms &amp; Conditions</h1>
  <p class="legal-updated">Last updated: October 5, 2026</p>

  <div class="legal-card">

    <h2>1. Acceptance of Terms</h2>
    <p>
      By signing in and using this Classroom Management System ("the System"), you agree to
      these Terms &amp; Conditions and to our <a href="/classroomv2/privacy.php">Privacy Policy</a>.
      If you don't agree, please don't use the System and let your school administrator know.
    </p>

    <h2>2. Accounts</h2>
    <ul>
      <li>Accounts are created by a teacher or administrator — there is no public sign-up.</li>
      <li>You're responsible for keeping your password confidential and for all activity under your account.</li>
      <li>Tell your teacher or admin right away if you believe your account has been accessed without your permission.</li>
      <li>You agree to provide accurate information (e.g. contact details) when you choose to add it.</li>
    </ul>

    <h2>3. Acceptable Use</h2>
    <p>You agree not to:</p>
    <ul>
      <li>Share your login credentials with anyone else, or use another person's account</li>
      <li>Attempt to access records, grades, or attendance that aren't your own (or, for teachers, that aren't for your own sections/subjects)</li>
      <li>Upload files or data you don't have the right to upload, or that contain malicious code</li>
      <li>Attempt to bypass, probe, or disrupt the System's security, including biometric attendance hardware</li>
      <li>Use the System for anything unlawful or outside its intended academic purpose</li>
    </ul>

    <h2>4. Role Responsibilities</h2>
    <h3>Students</h3>
    <p>Use the System to view your own grades, attendance, subjects, and notifications, and to keep your contact information and password up to date.</p>
    <h3>Teachers</h3>
    <p>
      Use the System to manage your own sections and subjects, record attendance and grades
      accurately, and only add, view, or remove students you are actually responsible for
      teaching.
    </p>
    <h3>Administrators</h3>
    <p>
      Administer accounts, assist with password recovery, and review the audit log responsibly
      and only for legitimate administrative purposes.
    </p>

    <h2>5. Academic Records</h2>
    <p>
      Grades and attendance entered into the System reflect what each teacher records for their
      own classes. The System is a classroom-management tool; where your school maintains a
      separate official record (e.g. a registrar's system), that official record governs in case
      of any discrepancy.
    </p>

    <h2>6. Biometric Attendance</h2>
    <p>
      Where enabled, fingerprint-based attendance is optional at your school's discretion and is
      used solely to verify attendance, as described in our
      <a href="/classroomv2/privacy.php">Privacy Policy</a>. Tampering with or attempting to spoof
      biometric attendance devices is not permitted.
    </p>

    <h2>7. Availability &amp; Changes</h2>
    <p>
      The System is provided on an "as available" basis. Features may be added, changed, or
      removed, and the System may occasionally be unavailable for maintenance. We don't guarantee
      uninterrupted or error-free operation.
    </p>

    <h2>8. Suspension &amp; Termination</h2>
    <p>
      An administrator may suspend or remove access for violation of these Terms, graduation or
      departure from the school, or at the school's discretion. Students who are fully removed
      from every section and subject may have their account and associated records deleted.
    </p>

    <h2>9. Limitation of Liability</h2>
    <p>
      The System is provided "as is," without warranties of any kind. To the fullest extent
      permitted by law, the School and developers of the System are not liable for indirect,
      incidental, or consequential damages arising from its use, including data loss from factors
      outside our reasonable control.
    </p>

    <h2>10. Governing Law</h2>
    <p>
      These Terms are governed by the laws of the Republic of the Philippines, including the
      Data Privacy Act of 2012 (R.A. 10173) where applicable.
    </p>

    <h2>11. Changes to These Terms</h2>
    <p>
      We may update these Terms from time to time. Continued use of the System after a change
      means you accept the updated Terms. The "Last updated" date above reflects the latest
      revision.
    </p>

    <h2>12. Contact</h2>
    <p>Questions about these Terms should be directed to your school's System administrator.</p>

  </div>
</div>

<?php include __DIR__ . '/includes/cookie_notice.php'; ?>
</body>
</html>
