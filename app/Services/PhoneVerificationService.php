<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Phone Number OTP verification — mirrors EmailVerificationService so the
 * Account Settings "Phone Number" field can prove the number is actually
 * reachable (via a 6-digit SMS code) before it is trusted and saved to
 * users.phone, the same way the Email Address field already requires a
 * code before trusting a new email.
 *
 * Unlike EmailVerificationService::initiateEmailVerification() (which
 * always returns success even if the OTP email never actually sends —
 * EmailService itself no-ops on a misconfigured SMTP, but email delivery
 * isn't the core deliverable of that flow, display is secondary), this
 * service treats a failed SmsService::send() as a hard failure: sending
 * the code IS the whole point here, so if the SMS genuinely never went
 * out, the user needs to be told immediately rather than being left
 * staring at an OTP box that will never receive a code.
 */
class PhoneVerificationService
{
    const OTP_VALIDITY_MINUTES = 5;
    const MAX_OTP_ATTEMPTS = 3;
    const RESEND_COOLDOWN_MINUTES = 5;

    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Generate a 6-digit OTP code
     */
    public function generateOTP(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Same format UserController::updateProfile() already accepts for
     * phone — digits, spaces, and + ( ) - , 7-30 characters.
     */
    public function isValidPhoneFormat(string $phone): bool
    {
        return (bool) preg_match('/^[0-9+()\-\s]{7,30}$/', $phone);
    }

    /**
     * Initiate phone verification - generate OTP and send it via SMS.
     * Returns: ['success' => bool, 'message' => string]
     */
    public function initiatePhoneVerification(int $userId, string $phone): array
    {
        $phone = trim($phone);

        if (!$this->isValidPhoneFormat($phone)) {
            return ['success' => false, 'message' => 'Phone number may only contain digits, spaces, and + ( ) - , and must be 7-30 characters'];
        }

        // Rate limiting - max 1 OTP per minute per phone number.
        $recentOTP = DB::table('phone_verifications')
            ->where('user_id', $userId)
            ->where('phone', $phone)
            ->where('last_otp_sent_at', '>', Carbon::now()->subMinutes(1))
            ->exists();

        if ($recentOTP) {
            return ['success' => false, 'message' => 'Please wait before requesting another code'];
        }

        $otp = $this->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(self::OTP_VALIDITY_MINUTES);

        // Send the SMS BEFORE writing the OTP to the database: if it never
        // goes out, there is no point starting (or restarting) a
        // verification window the user has no way to complete.
        $message = "Your SFMS phone verification code is {$otp}. It expires in " . self::OTP_VALIDITY_MINUTES . " minutes. Never share this code with anyone.";
        $sent = $this->smsService->send($phone, $message);

        if (!$sent) {
            return [
                'success' => false,
                'message' => 'Failed to send the verification code to this number. Double-check the number, or try again in a moment.',
            ];
        }

        try {
            $verification = DB::table('phone_verifications')
                ->where('user_id', $userId)
                ->where('phone', $phone)
                ->first();

            if ($verification) {
                DB::table('phone_verifications')
                    ->where('id', $verification->id)
                    ->update([
                        'otp_code' => $otp,
                        'otp_attempts' => 0,
                        'otp_expires_at' => $expiresAt,
                        'last_otp_sent_at' => Carbon::now(),
                        'is_verified' => false,
                        'updated_at' => Carbon::now(),
                    ]);
            } else {
                DB::table('phone_verifications')->insert([
                    'user_id' => $userId,
                    'phone' => $phone,
                    'otp_code' => $otp,
                    'otp_attempts' => 0,
                    'otp_expires_at' => $expiresAt,
                    'last_otp_sent_at' => Carbon::now(),
                    'is_verified' => false,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            return [
                'success' => true,
                'message' => 'Verification code sent via SMS',
            ];
        } catch (\Exception $e) {
            error_log('Phone verification initiation error: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Failed to start verification. Please try again.'];
        }
    }

    /**
     * Verify OTP code and, on success, save the phone number to users.phone.
     * Returns: ['success' => bool, 'message' => string]
     */
    public function verifyOTP(int $userId, string $phone, string $otp): array
    {
        $phone = trim($phone);

        if (!preg_match('/^\d{6}$/', $otp)) {
            return ['success' => false, 'message' => 'Invalid code format'];
        }

        $verification = DB::table('phone_verifications')
            ->where('user_id', $userId)
            ->where('phone', $phone)
            ->where('is_verified', false)
            ->first();

        if (!$verification) {
            return ['success' => false, 'message' => 'No pending verification for this phone number'];
        }

        if (Carbon::now()->isAfter($verification->otp_expires_at)) {
            return ['success' => false, 'message' => 'Code has expired. Please request a new one.'];
        }

        if ($verification->otp_attempts >= self::MAX_OTP_ATTEMPTS) {
            return ['success' => false, 'message' => 'Too many failed attempts. Please request a new code.'];
        }

        if ($verification->otp_code !== $otp) {
            DB::table('phone_verifications')
                ->where('id', $verification->id)
                ->increment('otp_attempts');

            $remaining = self::MAX_OTP_ATTEMPTS - $verification->otp_attempts - 1;

            return [
                'success' => false,
                'message' => $remaining > 0
                    ? "Incorrect code. {$remaining} attempts remaining."
                    : 'Too many failed attempts. Please request a new code.',
            ];
        }

        try {
            DB::beginTransaction();

            DB::table('phone_verifications')
                ->where('id', $verification->id)
                ->update([
                    'is_verified' => true,
                    'updated_at' => Carbon::now(),
                ]);

            DB::table('users')
                ->where('user_id', $userId)
                ->update([
                    'phone' => $phone,
                    'updated_at' => Carbon::now(),
                ]);

            DB::commit();

            return ['success' => true, 'message' => 'Phone number verified successfully'];
        } catch (\Exception $e) {
            DB::rollBack();
            error_log('Phone verification completion error: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Failed to complete verification. Please try again.'];
        }
    }

    /**
     * Resend OTP code via SMS.
     * Returns: ['success' => bool, 'message' => string]
     */
    public function resendOtp(int $userId, string $phone): array
    {
        $phone = trim($phone);

        if (!$this->isValidPhoneFormat($phone)) {
            return ['success' => false, 'message' => 'Invalid phone number format'];
        }

        $verification = DB::table('phone_verifications')
            ->where('user_id', $userId)
            ->where('phone', $phone)
            ->where('is_verified', false)
            ->first();

        if (!$verification) {
            return ['success' => false, 'message' => 'No pending verification for this phone number'];
        }

        $lastSentTime = $verification->last_otp_sent_at
            ? Carbon::parse($verification->last_otp_sent_at)
            : null;

        if ($lastSentTime && Carbon::now()->diffInMinutes($lastSentTime) < self::RESEND_COOLDOWN_MINUTES) {
            $minutesRemaining = self::RESEND_COOLDOWN_MINUTES - Carbon::now()->diffInMinutes($lastSentTime);

            return [
                'success' => false,
                'message' => "Please wait {$minutesRemaining} minute(s) before resending",
            ];
        }

        $otp = $this->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(self::OTP_VALIDITY_MINUTES);

        $message = "Your SFMS phone verification code is {$otp}. It expires in " . self::OTP_VALIDITY_MINUTES . " minutes. Never share this code with anyone.";
        $sent = $this->smsService->send($phone, $message);

        if (!$sent) {
            return [
                'success' => false,
                'message' => 'Failed to send the verification code to this number. Please try again in a moment.',
            ];
        }

        try {
            DB::table('phone_verifications')
                ->where('id', $verification->id)
                ->update([
                    'otp_code' => $otp,
                    'otp_attempts' => 0,
                    'otp_expires_at' => $expiresAt,
                    'last_otp_sent_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            return ['success' => true, 'message' => 'New verification code sent via SMS'];
        } catch (\Exception $e) {
            error_log('Phone OTP resend error: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Failed to resend code. Please try again.'];
        }
    }

    /**
     * Get current verification status for a user's phone number.
     */
    public function getVerificationStatus(int $userId, string $phone): ?array
    {
        $verification = DB::table('phone_verifications')
            ->where('user_id', $userId)
            ->where('phone', $phone)
            ->first();

        if (!$verification) {
            return null;
        }

        return [
            'phone' => $verification->phone,
            'is_verified' => (bool) $verification->is_verified,
            'is_expired' => Carbon::now()->isAfter($verification->otp_expires_at),
            'minutes_remaining' => Carbon::now()->diffInMinutes(
                Carbon::parse($verification->otp_expires_at),
                false
            ),
            'attempts_remaining' => self::MAX_OTP_ATTEMPTS - $verification->otp_attempts,
        ];
    }
}
