<?php
/**
 * EmailService.php
 * Gmail email notifications using PHPMailer (via Composer autoload)
 *
 * SETUP:
 *  1. In your project root run: composer require phpmailer/phpmailer
 *  2. Fill in your Gmail address and App Password below
 *     (Generate App Password at https://myaccount.google.com/apppasswords)
 */

// Load Composer autoloader from known project locations.
$autoloadCandidates = [
  dirname(__DIR__, 3) . '/vendor/autoload.php',
  dirname(__DIR__, 4) . '/vendor/autoload.php',
  dirname(__DIR__, 2) . '/vendor/autoload.php'
];

$vendorAutoload = null;
foreach ($autoloadCandidates as $candidate) {
  if (file_exists($candidate)) {
    $vendorAutoload = $candidate;
    break;
  }
}

if ($vendorAutoload !== null) {
  require_once $vendorAutoload;
} else {
    // PHPMailer not installed yet — log silently and skip email
    error_log('[EmailService] PHPMailer not found. Run: composer require phpmailer/phpmailer');
    // Define a no-op class so the rest of the app won't crash
    if (!class_exists('EmailService')) {
        class EmailService {
            public static function sendNewReportNotification(array $report, array $superAdmins): bool { return false; }
            public static function sendStatusUpdateNotification(array $report, array $recipient): bool { return false; }
        }
    }
    return;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class EmailService
{
  private const DEFAULT_SMTP_HOST = 'smtp.gmail.com';
  private const DEFAULT_SMTP_PORT = 587;
  private const DEFAULT_FROM_NAME = 'School Facility Maintenance System';
  private static array $localSecrets = [];

    /**
     * Build a configured PHPMailer instance.
     */
    private static function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
      $mail->Host       = self::configValue('SFMS_SMTP_HOST', self::DEFAULT_SMTP_HOST);
        $mail->SMTPAuth   = true;
      $mail->Username   = self::configValue('SFMS_SMTP_USERNAME', '');
      $mail->Password   = self::configValue('SFMS_SMTP_PASSWORD', '');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = (int) self::configValue('SFMS_SMTP_PORT', self::DEFAULT_SMTP_PORT);
        $mail->Timeout    = 10;
        $mail->SMTPKeepAlive = false;

      $mail->setFrom(
        self::configValue('SFMS_MAIL_FROM_ADDRESS', $mail->Username),
        self::configValue('SFMS_MAIL_FROM_NAME', self::DEFAULT_FROM_NAME)
      );
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    /**
     * Send "New Maintenance Report Submitted" email to all super admins.
     *
     * @param array $report      Associative array with report details
     * @param array $superAdmins Array of ['email' => ..., 'full_name' => ...] rows
     */
    public static function sendNewReportNotification(array $report, array $superAdmins): bool
    {
        if (empty($superAdmins)) {
            $superAdmins = [];
        }

      $emails = [];
      $seenEmails = [];

      foreach ($superAdmins as $admin) {
        $email = strtolower(trim((string)($admin['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
          continue;
        }

        if (isset($seenEmails[$email])) {
          continue;
        }

        $seenEmails[$email] = true;
        $emails[] = [
          'email' => $email,
          'full_name' => $admin['full_name'] ?? 'Admin'
        ];
      }

      // Always copy the configured SMTP account so this mailbox receives
      // every new-report alert even if DB recipients are missing/misconfigured.
      $smtpAccountEmail = strtolower(trim((string)self::configValue('SFMS_SMTP_USERNAME', '')));
      if ($smtpAccountEmail !== '' && filter_var($smtpAccountEmail, FILTER_VALIDATE_EMAIL) && !isset($seenEmails[$smtpAccountEmail])) {
        $seenEmails[$smtpAccountEmail] = true;
        $emails[] = [
          'email' => $smtpAccountEmail,
          'full_name' => 'Super Admin'
        ];
      }

      if (empty($emails)) {
        return false;
      }

      try {
        $mail = self::createMailer();

        // Send one message to all admins (first as TO, others as BCC) to avoid
        // opening multiple SMTP sessions that slow down report submission.
        $primary = array_shift($emails);
        $mail->addAddress($primary['email'], $primary['full_name']);

        foreach ($emails as $admin) {
          $mail->addBCC($admin['email'], $admin['full_name']);
        }

        $priorityColor = self::getPriorityColor($report['priority'] ?? 'medium');
        $priorityLabel = strtoupper($report['priority'] ?? 'MEDIUM');

        $mail->Subject = '[SFMS] New Maintenance Report: ' . ($report['title'] ?? 'Untitled');
        $mail->Body    = self::buildNewReportEmailHTML($report, $priorityColor, $priorityLabel);
        $mail->AltBody = self::buildNewReportEmailText($report);

        $mail->send();
        error_log('[EmailService] Email sent to ' . $primary['email'] . ' with BCC recipients: ' . count($emails));
        return true;
      } catch (Throwable $e) {
        error_log('[EmailService] Failed to send admin notification email: ' . $e->getMessage());
        return false;
      }
    }

    /**
     * Send a status-update email to a single recipient.
     *
     * @param array $report    Report details (must include 'status', 'title', etc.)
     * @param array $recipient ['email' => ..., 'full_name' => ...]
     */
    public static function sendStatusUpdateNotification(array $report, array $recipient): bool
    {
        if (empty($recipient['email'])) {
            return false;
        }

        try {
            $mail = self::createMailer();
            $mail->addAddress($recipient['email'], $recipient['full_name'] ?? '');

            $statusLabel = ucwords(str_replace('_', ' ', $report['status'] ?? 'updated'));

            $mail->Subject = '[SFMS] Report Status Updated: ' . $statusLabel;
            $mail->Body    = self::buildStatusUpdateEmailHTML($report, $statusLabel);
            $mail->AltBody = "Your maintenance report \"{$report['title']}\" status has been updated to: $statusLabel.";

            $mail->send();
            return true;

        } catch (PHPMailerException $e) {
            error_log('[EmailService] Status update email failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function configValue(string $key, $default = null)
    {
      if (function_exists('env')) {
        $value = env($key, null);
        if ($value !== null && $value !== '') {
          return $value;
        }
      }

      $localSecrets = self::loadLocalSecrets();
      if (array_key_exists($key, $localSecrets) && $localSecrets[$key] !== '') {
        return $localSecrets[$key];
      }

      $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
      if ($value === false || $value === null || $value === '') {
        return $default;
      }

      return $value;
    }

    private static function loadLocalSecrets(): array
    {
      if (!empty(self::$localSecrets)) {
        return self::$localSecrets;
      }

      $projectRoot = dirname(__DIR__, 3);
      $secretFile = $projectRoot . '/.env.mail.local';

      if (!is_file($secretFile)) {
        self::$localSecrets = [];
        return self::$localSecrets;
      }

      $lines = file($secretFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if ($lines === false) {
        self::$localSecrets = [];
        return self::$localSecrets;
      }

      $secrets = [];
      foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if ($trimmedLine === '' || str_starts_with($trimmedLine, '#') || !str_contains($trimmedLine, '=')) {
          continue;
        }

        [$name, $value] = explode('=', $trimmedLine, 2);
        $name = trim($name);
        $value = trim($value);

        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === '\'' && str_ends_with($value, '\'')))) {
          $value = substr($value, 1, -1);
        }

        if ($name !== '') {
          $secrets[$name] = $value;
        }
      }

      self::$localSecrets = $secrets;
      return self::$localSecrets;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private static function getPriorityColor(string $priority): string
    {
        // IT-expert priority-color correction: Critical=Red, High=Orange,
        // Medium=Yellow, Low=Blue (standardized app-wide).
        return match (strtolower($priority)) {
            'critical', 'urgent' => '#dc3545',
            'high'               => '#fd7e14',
            'medium'             => '#eab308',
            'low'                => '#3b82f6',
            default              => '#3b82f6',
        };
    }

    private static function buildNewReportEmailHTML(array $r, string $color, string $label): string
    {
        $title       = htmlspecialchars($r['title']       ?? 'N/A');
        $location    = htmlspecialchars($r['location']    ?? 'N/A');
        $description = htmlspecialchars($r['description'] ?? 'N/A');
        $submittedBy = htmlspecialchars($r['creator_name'] ?? $r['submitted_by'] ?? 'Unknown');
        $reportId    = intval($r['report_id'] ?? 0);
        $appUrl      = defined('APP_URL') ? APP_URL : 'http://localhost/School_Facility_Maintenance_System';

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0;">
  <div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
    <div style="background:#1a3c5e;padding:24px 30px;">
      <h2 style="color:#fff;margin:0;">New Maintenance Report Submitted</h2>
      <p style="color:#a8c0d6;margin:6px 0 0;">School Facility Maintenance System</p>
    </div>
    <div style="padding:30px;">
      <p style="color:#333;">A new maintenance report has been submitted and requires your attention.</p>
      <table style="width:100%;border-collapse:collapse;margin-top:16px;">
        <tr><td style="padding:8px 0;color:#666;width:140px;">Report Title</td><td style="padding:8px 0;font-weight:bold;color:#1a3c5e;">$title</td></tr>
        <tr><td style="padding:8px 0;color:#666;">Location</td><td style="padding:8px 0;color:#333;">$location</td></tr>
        <tr><td style="padding:8px 0;color:#666;">Priority</td><td style="padding:8px 0;"><span style="background:$color;color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:bold;">$label</span></td></tr>
        <tr><td style="padding:8px 0;color:#666;">Submitted By</td><td style="padding:8px 0;color:#333;">$submittedBy</td></tr>
        <tr><td style="padding:8px 0;color:#666;vertical-align:top;">Description</td><td style="padding:8px 0;color:#333;">$description</td></tr>
      </table>
      <div style="margin-top:28px;">
        <a href="$appUrl/frontend/pages/report-detail.php?id=$reportId"
           style="background:#1a3c5e;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">
          View Report →
        </a>
      </div>
    </div>
    <div style="background:#f8f9fa;padding:16px 30px;color:#999;font-size:12px;">
      This is an automated notification from the School Facility Maintenance System.
    </div>
  </div>
</body>
</html>
HTML;
    }

    private static function buildNewReportEmailText(array $r): string
    {
        return sprintf(
            "New Maintenance Report Submitted\n\nTitle: %s\nLocation: %s\nPriority: %s\nSubmitted By: %s\n\nDescription:\n%s\n\nLog in to review the report.",
            $r['title']        ?? 'N/A',
            $r['location']     ?? 'N/A',
            strtoupper($r['priority'] ?? 'MEDIUM'),
            $r['creator_name'] ?? $r['submitted_by'] ?? 'Unknown',
            $r['description']  ?? 'N/A'
        );
    }

    private static function buildStatusUpdateEmailHTML(array $r, string $statusLabel): string
    {
        $title    = htmlspecialchars($r['title']    ?? 'N/A');
        $location = htmlspecialchars($r['location'] ?? 'N/A');
        $reportId = intval($r['report_id'] ?? 0);
        $appUrl   = defined('APP_URL') ? APP_URL : 'http://localhost/School_Facility_Maintenance_System';

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0;">
  <div style="max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);">
    <div style="background:#1a3c5e;padding:24px 30px;">
      <h2 style="color:#fff;margin:0;">Report Status Updated</h2>
    </div>
    <div style="padding:30px;">
      <p>Your report <strong>$title</strong> at <em>$location</em> has been updated.</p>
      <p>New Status: <strong style="color:#1a3c5e;">$statusLabel</strong></p>
      <div style="margin-top:28px;">
        <a href="$appUrl/frontend/pages/report-detail.php?id=$reportId"
           style="background:#1a3c5e;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;">
          View Report →
        </a>
      </div>
    </div>
    <div style="background:#f8f9fa;padding:16px 30px;color:#999;font-size:12px;">
      Automated notification — School Facility Maintenance System
    </div>
  </div>
</body>
</html>
HTML;
    }
}
