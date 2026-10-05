<?php
// ============================================================
//  privacy.php — Privacy Policy
//  Public page: readable whether signed in or not.
// ============================================================
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Privacy Policy — Classroom CMS</title>
  <link rel="icon" type="image/png" href="assets/images/TCM logo (2).png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.0.0/dist/tabler-icons.min.css">
  <link rel="stylesheet" href="/classroomv2/assets/style.css">
</head>
<body class="page-legal">

<?php $active_legal = 'privacy'; include __DIR__ . '/includes/legal_nav.php'; ?>

<div class="legal-wrap">
  <h1>Privacy Policy</h1>
  <p class="legal-updated">Last updated: October 5, 2026</p>

  <div class="legal-card">

    <h2>1. Overview</h2>
    <p>
      This Classroom Management System ("the System") is used by students, teachers, and
      administrators to manage subjects, sections, grades, attendance, and related school
      records. This policy explains what information the System collects, how it's used,
      who can see it, and how it's protected. Accounts are created by a teacher or
      administrator — there is no public self-registration.
    </p>

    <h2>2. Information We Collect</h2>
    <h3>Account &amp; contact information</h3>
    <ul>
      <li>Name, username / Student ID, and role (student, teacher, or admin)</li>
      <li>Your password, which is never stored in plain text — only a one-way (hashed) version is kept</li>
      <li>Gmail / email address and contact number, if you choose to add them in Settings</li>
    </ul>

    <h3>Academic records</h3>
    <ul>
      <li>Grades, scores, and component weights for each subject</li>
      <li>Attendance records per subject and date</li>
      <li>Section and subject enrollment (which classes you're part of)</li>
      <li>Notifications about your own academic activity (e.g. a new grade posted, an absence streak)</li>
    </ul>

    <h3>Biometric data (fingerprint attendance)</h3>
    <p>
      If your teacher or school enables fingerprint-based attendance, a fingerprint scanner
      captures your fingerprint and the System extracts a <strong>minutiae template</strong> —
      a mathematical map of ridge points — rather than storing a picture of your fingerprint.
      That template is what later scans are compared against to mark attendance.
    </p>
    <div class="legal-note">
      <strong>Biometric data is sensitive personal information.</strong> It is collected only
      for attendance verification, is never used for any other purpose, is never shared outside
      the System, and is removed if you're removed from the class or request its deletion
      through your school admin.
    </div>

    <h3>Security &amp; activity logs</h3>
    <ul>
      <li>Login history: date/time, success or failure, approximate device/browser/OS, and IP address</li>
      <li>A record of security-relevant account activity (e.g. password changes, profile updates)</li>
      <li>An administrative audit log of actions taken in the System (e.g. imports, record changes)</li>
    </ul>

    <h3>Files you or your teacher upload</h3>
    <p>
      CSV/Excel files used to bulk-import students are read to create or update student and
      enrollment records; the System does not keep a public copy of the uploaded file itself.
    </p>

    <h3>Cookies</h3>
    <p>
      The System uses a single session cookie to keep you signed in. See our
      <a href="/classroomv2/cookie_policy.php">Cookie Policy</a> for details — we don't use
      advertising or analytics-tracking cookies.
    </p>

    <h2>3. How We Use This Information</h2>
    <ul>
      <li>To authenticate you and keep you securely signed in</li>
      <li>To record, calculate, and display grades and attendance</li>
      <li>To let teachers and admins manage their own sections, subjects, and student rosters</li>
      <li>To notify you about activity on your own account</li>
      <li>To detect suspicious logins and keep accounts secure (login history, audit log)</li>
      <li>To bulk-create or update student records when a teacher imports a class list</li>
    </ul>

    <h2>4. Who Can See Your Information</h2>
    <p>Access is limited by role and is not public:</p>
    <ul>
      <li><strong>Students</strong> can see only their own grades, attendance, and account activity.</li>
      <li><strong>Teachers</strong> can see records for students enrolled in their own subjects/sections only.</li>
      <li><strong>Admins</strong> can see accounts and records across the School for administrative purposes (e.g. password resets, audit review).</li>
    </ul>
    <p>We do not sell personal information, and we do not share it with outside companies for marketing.</p>

    <h2>5. How Long We Keep It</h2>
    <p>
      Information is kept while your account is active and linked to a section or subject.
      If a student is fully removed from every section and subject by their teacher, their
      underlying account and records may be deleted outright. Security logs are kept to a
      reasonable, limited history to support account-security review.
    </p>

    <h2>6. How We Protect It</h2>
    <ul>
      <li>Passwords are one-way hashed, never stored or displayed in plain text</li>
      <li>Forms are protected against cross-site request forgery (CSRF)</li>
      <li>Sensitive actions are recorded in a security audit log</li>
      <li>Fingerprint data is stored as a non-reversible minutiae template, not a raw image</li>
    </ul>

    <h2>7. Your Choices &amp; Rights</h2>
    <ul>
      <li>You can review and update your contact information and password any time in <strong>Settings</strong>.</li>
      <li>You can review your own login history and recent account activity in <strong>Settings</strong>.</li>
      <li>To correct, export, or delete other information about you, contact your teacher or your school's administrator.</li>
    </ul>
    <p>
      If the Data Privacy Act of 2012 (Republic Act No. 10173) applies to you, you may also have
      the right to be informed, to access, to object, to erasure or blocking, to data portability,
      and to damages for violations of your rights as a data subject.
    </p>

    <h2>8. Children's Privacy</h2>
    <p>
      Many students using this System are minors. Student accounts are created and overseen by
      the school, not by the student directly, and data is used only for the academic purposes
      described above. A parent or guardian with questions about a student's data should contact
      the school administrator.
    </p>

    <h2>9. Changes to This Policy</h2>
    <p>
      We may update this policy as the System changes. The "Last updated" date at the top will
      reflect the most recent revision.
    </p>

    <h2>10. Contact</h2>
    <p>
      Questions about this policy or your data should be directed to your school's System
      administrator.
    </p>

  </div>
</div>

<?php include __DIR__ . '/includes/cookie_notice.php'; ?>
</body>
</html>
