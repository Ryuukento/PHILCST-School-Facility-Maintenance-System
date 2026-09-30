<?php
/**
 * Background task: send new report email notification.
 * Receives a base64-encoded JSON payload via argv[1].
 */

require_once dirname(__DIR__) . '/config/settings.php';
require_once dirname(__DIR__) . '/services/EmailService.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$encoded = $argv[1] ?? '';
if ($encoded === '') {
    exit(1);
}

$decoded = base64_decode($encoded, true);
if ($decoded === false) {
    exit(1);
}

$data = json_decode($decoded, true);
if (!is_array($data)) {
    exit(1);
}

$payload = $data['payload'] ?? null;
$recipients = $data['recipients'] ?? null;

if (!is_array($payload) || !is_array($recipients) || empty($recipients)) {
    exit(1);
}

EmailService::sendNewReportNotification($payload, $recipients);
exit(0);
