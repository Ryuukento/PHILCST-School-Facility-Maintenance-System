<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Administrator Need Change SMS alert — thin wrapper around the Semaphore
 * SMS API (https://semaphore.co).
 *
 * Deliberately mirrors the "no-op when unconfigured / log-and-swallow any
 * failure" style already used by NotificationService::notify() and
 * public/backend/services/EmailService.php elsewhere in this app, so a
 * missing API key, a missing recipient phone number, or a down SMS provider
 * can NEVER block report creation/update or either of the other two
 * notification channels (in-system, email). send() never throws.
 *
 * Credentials come ONLY from config/services.php (env()-backed, see
 * SEMAPHORE_API_KEY / SEMAPHORE_SENDER_NAME / SEMAPHORE_API_URL in
 * .env.example). No real key, sender name, or other secret is ever
 * hardcoded here, and failures are logged without including the API key or
 * full request payload.
 */
class SmsService
{
    /**
     * Sends a single SMS via Semaphore to $phoneNumber. Returns true only on
     * a confirmed-successful API response; returns false (and logs why,
     * never throws) for every other outcome — no config, no phone on file,
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

        $apiKey = (string) config('services.semaphore.api_key');
        if ($apiKey === '') {
            Log::warning('[SmsService] Skipped: SEMAPHORE_API_KEY is not configured.');

            return false;
        }

        $apiUrl = (string) config('services.semaphore.api_url');
        $senderName = (string) config('services.semaphore.sender_name');

        try {
            $payload = [
                'apikey' => $apiKey,
                'number' => $phoneNumber,
                'message' => $message,
            ];
            if ($senderName !== '') {
                $payload['sendername'] = $senderName;
            }

            $response = Http::asForm()->timeout(10)->post($apiUrl, $payload);

            if (!$response->successful()) {
                // Deliberately NOT logging $payload — it contains the API key.
                Log::warning('[SmsService] Semaphore API responded with a non-success status.', [
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
