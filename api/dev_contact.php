<?php
// ============================================================
// api/dev_contact.php
//
// Handler for the login page's "Contact the developer" box
// (Get in touch = partnership inquiry, Comments = general feedback).
// Public — no login required. Protected the same way as report_issue.php:
//   - CSRF token, - honeypot field ("website"), - 30s per-session cooldown
//
// POST: csrf_token, type (partnership|comment), message, name, email,
//       website (honeypot — must stay empty)
// Response: JSON { "status": "ok" } or { "status": "error", "message": "..." }
// The message is emailed to the developer (see send_dev_contact_email()).
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

// Honeypot: real visitors never see or fill this field.
if (trim($_POST['website'] ?? '') !== '') {
    echo json_encode(['status' => 'ok']);
    exit;
}

$now  = time();
$last = $_SESSION['dev_contact_last'] ?? 0;
if ($now - $last < 30) fail('Please wait a moment before sending another message.', 429);

$type = trim($_POST['type'] ?? '');
if (!in_array($type, ['partnership', 'comment'], true)) fail('Please choose a valid option.');

$message = trim($_POST['message'] ?? '');
if (mb_strlen($message) < 10)   fail('Please write a little more (10+ characters).');
if (mb_strlen($message) > 2000) fail('That message is too long — please keep it under 2000 characters.');

$name = trim($_POST['name'] ?? '');
if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);

$email = trim($_POST['email'] ?? '');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail("That doesn't look like a valid email address.");
// A partnership inquiry needs a way to reply; a comment can be anonymous.
if ($type === 'partnership' && $email === '') fail('Please add your email so the developer can reply.');

$sent = send_dev_contact_email($type, $message, $name, $email);
$_SESSION['dev_contact_last'] = $now;

if (!$sent) fail('Sorry, your message could not be sent right now. Please try again later.', 500);

echo json_encode([
    'status'  => 'ok',
    'message' => $type === 'partnership'
        ? 'Thanks! Your message was sent. The developer will get back to you by email.'
        : 'Thanks for your comment! It was sent to the developer.',
]);