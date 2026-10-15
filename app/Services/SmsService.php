<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Administrator Need Change SMS alert — thin wrapper around the IPROG SMS
 * API (https://sms.iprogtech.com/api/v1/documentation).
 *
 * Deliberately mirrors the "no-op when unconfigured / log-and-swallow any
 * failure" style already used by NotificationService::notify() and
 * public/backend/services/EmailService.php elsewhere in this app, so a
 * missing API token, a missing recipient phone number, or a down SMS
 * provider can NEVER block report creation/update or either of the other
 * two notification channels (in-system, email). send() never throws.
 *
 * Credentials come ONLY from config/services.php (env()-backed, see
 * IPROG_SMS_API_TOKEN / IPROG_SMS_API_URL / IPROG_SMS_PROVIDER in
 * .env.example). No real token or other secret is ever hardcoded here, and
 * failures are logged without including the API token or full request
 * payload.
 *
 * 2026-10-13 — replaced Semaphore (never actually provisioned with a real
 * key) with IPROG, the provider actually purchased/used.
 */
class SmsService
{
    /**
     * Sends a single SMS via IPROG to $phoneNumber. Returns true only on a
     * confirmed-successful API response; returns false (and logs why, never
     * throws) for every other outcome — no config, no phone on file,
     * HTTP/network failure, or a non-success API response.
     */
    public function send(?string $phoneNumber, string $message): bool
    {
        $phoneNumber = trim((string) $phoneNumber);
        if ($phoneNumber === '') {
            // Not an error — the recipient simply has no phone number saved
            // yet (users.phone is nullable). The in-system and email legs
            // are sent independently of this and are unaffected.
            Log::info('[SmsService] Skipped: recipient has no phone number on file.');

            return false;
        }

        $apiToken = (string) config('services.iprog.api_token');
        if ($apiToken === '') {
            Log::warning('[SmsService] Skipped: IPROG_SMS_API_TOKEN is not configured.');

            return false;
        }

        $apiUrl = (string) config('services.iprog.api_url');
        $smsProvider = config('services.iprog.sms_provider', 0);

        try {
            // IPROG's documented contract accepts these as query/form params
            // (its own docs show them appended to the URL); sending as a
            // JSON body (as its docs also show) works the same way and keeps
            // the token out of any URL that might get logged by a proxy.
            $payload = [
                'api_token' => $apiToken,
                'phone_number' => $phoneNumber,
                'message' => $message,
                'sms_provider' => $smsProvider,
            ];

            $response = Http::asForm()->timeout(10)->post($apiUrl, $payload);

            $body = $response->json();
            $messageId = is_array($body) ? ($body['message_id'] ?? null) : null;

            // 2026-10-15 — traced a live-only failure to exactly this check:
            // an HTTP 2xx status alone is not reliable proof the request
            // ever reached IPROG. Something between this server and
            // sms.iprogtech.com (most likely a hosting-side outbound
            // firewall/proxy) was returning a bare 200 for a silently
            // blocked request, and $response->successful() treated that as
            // a real send — initiatePhoneVerification() and the New Report
            // SMS alert both reported "sent" while IPROG's own account
            // history showed nothing for either attempt. A genuine IPROG
            // success always includes a message_id in the JSON body (see
            // https://sms.iprogtech.com/api/v1/documentation and IPROG's
            // own recent_sms account log); require that explicitly instead
            // of trusting the status code alone.
            if (!$response->successful() || empty($messageId)) {
                // Deliberately NOT logging $payload — it contains the API token.
                // The response body is safe to log: IPROG's response never
                // echoes the token back.
                Log::warning('[SmsService] IPROG API did not return a recognizable success response.', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);

                return false;
            }

            Log::info('[SmsService] Sent.', ['message_id' => $messageId]);

            return true;
        } catch (Throwable $e) {
            Log::warning('[SmsService] Failed to send SMS.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
