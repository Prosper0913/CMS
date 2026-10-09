<?php
// ============================================================
// api/report_issue.php
//
// Public "Report an Issue" form handler (fed from loginv2.php's footer
// modal). No login required — anyone who hits a bug while trying to sign
// in needs to be able to report it without an account. Protected by:
//   - CSRF token (session-bound, same helper used elsewhere in the app)
//   - a honeypot field ("website") real users never see or fill in
//   - a short per-session cooldown so one person can't hammer this
//
// POST (application/x-www-form-urlencoded or multipart):
//   csrf_token, category, message, name (optional), email (optional),
//   website (honeypot — must stay empty), page (optional context string)
//
// Response: JSON { "status": "ok" } or { "status": "error", "message": "..." }
// ============================================================

header('Content-Type: application/json');
session_start();
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';

function fail($msg, $httpCode = 400) {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
if (!csrf_verify()) fail('Your session expired — please reload the page and try again.', 403);

// Honeypot: a real visitor never sees or fills this field (hidden via CSS).
if (trim($_POST['website'] ?? '') !== '') {
    // Silently pretend success so a bot doesn't learn the field is checked.
    echo json_encode(['status' => 'ok']);
    exit;
}

// Simple per-session cooldown (30s) to blunt casual abuse.
$now = time();
$last = $_SESSION['report_issue_last'] ?? 0;
if ($now - $last < 30) {
    fail('Please wait a moment before sending another report.', 429);
}

$category = trim($_POST['category'] ?? 'other');
$allowedCategories = ['login', 'bug', 'account', 'performance', 'other'];
if (!in_array($category, $allowedCategories, true)) $category = 'other';

$message = trim($_POST['message'] ?? '');
if (mb_strlen($message) < 10)  fail('Please describe the issue in a bit more detail (10+ characters).');
if (mb_strlen($message) > 2000) fail('That message is too long — please keep it under 2000 characters.');

$name  = trim($_POST['name'] ?? '');
if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);

$email = trim($_POST['email'] ?? '');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('That doesn\'t look like a valid email address.');
}

$pageContext = trim($_POST['page'] ?? 'loginv2.php');
if (mb_strlen($pageContext) > 200) $pageContext = mb_substr($pageContext, 0, 200);

$sent = send_report_issue_email($category, $message, $name, $email, $pageContext);

$_SESSION['report_issue_last'] = $now;

if (!$sent) {
    // Don't expose SMTP/config failures to the visitor — just tell them
    // it didn't go through so they can retry or use another channel.
    fail('Sorry, your report could not be sent right now. Please try again later.', 500);
}

echo json_encode([
    'status'  => 'ok',
    'message' => 'Thanks — your report was sent to the system administrator.',
]);