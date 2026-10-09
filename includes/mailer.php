<?php
// ============================================================
//  includes/mailer.php
//  Thin wrapper around PHPMailer for sending the password-reset
//  OTP email. Credentials live in config/secrets.php (gitignored).
// ============================================================

require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send a one-time password-reset code by email.
 *
 * @return bool true on success. On failure, the reason is written to the
 *              PHP error log (never shown to the person, so an attacker
 *              can't use send failures to probe which addresses exist).
 */
function send_otp_email(string $toEmail, string $toName, string $code, int $expiryMinutes): bool
{
    $secretsFile = __DIR__ . '/../config/secrets.php';
    if (!file_exists($secretsFile)) {
        error_log('[mailer] config/secrets.php is missing — copy config/secrets.example.php and fill in SMTP credentials.');
        return false;
    }
    require_once $secretsFile;
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        error_log('[mailer] config/secrets.php is missing one of SMTP_HOST / SMTP_USER / SMTP_PASS.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT ?? 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 12; // don't hang the request if SMTP is unreachable

        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Classroom CMS';
        $mail->setFrom(SMTP_USER, $fromName);
        $mail->addAddress($toEmail, $toName);

        $mail->Subject = "Your Classroom CMS verification code";
        $mail->isHTML(true);
        $safeName = htmlspecialchars($toName !== '' ? $toName : 'there', ENT_QUOTES);
        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;max-width:420px;margin:0 auto;\">
              <p>Hi {$safeName},</p>
              <p>Use this code to reset your Classroom CMS password:</p>
              <p style=\"font-size:32px;font-weight:bold;letter-spacing:6px;
                         background:#f1f5f3;padding:14px 10px;text-align:center;
                         border-radius:8px;color:#1e5f4e;\">{$code}</p>
              <p>This code expires in {$expiryMinutes} minutes and can only be used once.</p>
              <p style=\"color:#777;font-size:12px;\">If you didn't request this, you can safely ignore this
              email — your password will not be changed.</p>
            </div>";
        $mail->AltBody = "Your Classroom CMS verification code is: {$code}\n"
            . "It expires in {$expiryMinutes} minutes and can only be used once.\n"
            . "If you didn't request this, you can safely ignore this email.";

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[mailer] send_otp_email failed: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('[mailer] send_otp_email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send a "Report an Issue" submission from the public login page to the
 * school's System administrator. Uses the same SMTP account as the OTP
 * mailer. Reply-To is set to the reporter's own email (if given) so an
 * admin can just hit "reply" in their mail client.
 *
 * @return bool true on success. On failure, the reason is written to the
 *              PHP error log; the caller shows a generic message either way.
 */
function send_report_issue_email(
    string $category,
    string $message,
    string $reporterName,
    string $reporterEmail,
    string $pageContext
): bool {
    $secretsFile = __DIR__ . '/../config/secrets.php';
    if (!file_exists($secretsFile)) {
        error_log('[mailer] config/secrets.php is missing — copy config/secrets.example.php and fill in SMTP credentials.');
        return false;
    }
    require_once $secretsFile;
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        error_log('[mailer] config/secrets.php is missing one of SMTP_HOST / SMTP_USER / SMTP_PASS.');
        return false;
    }

    // Where issue reports land. Defaults to the same inbox the OTP mailer
    // sends from; override by adding define('REPORT_ISSUE_EMAIL', '...')
    // to config/secrets.php if the school wants a different inbox.
    $toEmail = defined('REPORT_ISSUE_EMAIL') ? REPORT_ISSUE_EMAIL : SMTP_USER;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT ?? 587;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 12;

        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Classroom CMS';
        $mail->setFrom(SMTP_USER, $fromName);
        $mail->addAddress($toEmail);
        if ($reporterEmail !== '') {
            $mail->addReplyTo($reporterEmail, $reporterName !== '' ? $reporterName : $reporterEmail);
        }

        $mail->Subject = "[CMS Issue Report] {$category}";
        $mail->isHTML(true);
        $safeName    = htmlspecialchars($reporterName !== '' ? $reporterName : 'Anonymous', ENT_QUOTES);
        $safeEmail   = htmlspecialchars($reporterEmail !== '' ? $reporterEmail : 'not provided', ENT_QUOTES);
        $safeCat     = htmlspecialchars($category, ENT_QUOTES);
        $safeMsg     = nl2br(htmlspecialchars($message, ENT_QUOTES));
        $safeContext = htmlspecialchars($pageContext, ENT_QUOTES);
        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;max-width:560px;margin:0 auto;\">
              <p><strong>Category:</strong> {$safeCat}</p>
              <p><strong>From:</strong> {$safeName} ({$safeEmail})</p>
              <p><strong>Page:</strong> {$safeContext}</p>
              <p><strong>Message:</strong></p>
              <p style=\"background:#f1f5f3;padding:12px 14px;border-radius:8px;\">{$safeMsg}</p>
            </div>";
        $mail->AltBody = "Category: {$category}\nFrom: {$reporterName} ({$reporterEmail})\nPage: {$pageContext}\n\n{$message}";

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[mailer] send_report_issue_email failed: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('[mailer] send_report_issue_email failed: ' . $e->getMessage());
        return false;
    }
}