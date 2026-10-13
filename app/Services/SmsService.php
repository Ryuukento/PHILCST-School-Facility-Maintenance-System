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

            if (!$response->successful()) {
                // Deliberately NOT logging $payload — it contains the API token.
                Log::warning('[SmsService] IPROG API responded with a non-success status.', [
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('[SmsService] Failed to send SMS.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
