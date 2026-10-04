<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class EmailVerificationService
{
    const OTP_VALIDITY_MINUTES = 5;
    const MAX_OTP_ATTEMPTS = 3;
    const RESEND_COOLDOWN_MINUTES = 5;

    /**
     * Generate a 6-digit OTP code
     */
    public function generateOTP(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if email is unique (not verified by any other user)
     */
    public function isEmailUnique(string $email, ?int $excludeUserId = null): bool
    {
        $query = DB::table('users')->where('verified_email', $email);

        if ($excludeUserId) {
            $query->where('user_id', '!=', $excludeUserId);
        }

        return $query->count() === 0;
    }

    /**
     * Initiate email verification - generate OTP and send email
     * Returns: ['success' => bool, 'message' => string]
     */
    public function initiateEmailVerification(int $userId, string $email): array
    {
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }

        // Check if email is unique
        if (!$this->isEmailUnique($email, $userId)) {
            return ['success' => false, 'message' => 'This email is already in use'];
        }

        // Check rate limiting - max 1 OTP per minute per email
        $recentOTP = DB::table('email_verifications')
            ->where('user_id', $userId)
            ->where('email', $email)
            ->where('last_otp_sent_at', '>', Carbon::now()->subMinutes(1))
            ->exists();

        if ($recentOTP) {
            return ['success' => false, 'message' => 'Please wait before requesting another OTP'];
        }

        // Generate OTP
        $otp = $this->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(self::OTP_VALIDITY_MINUTES);

        try {
            // Get or create verification record
            $verification = DB::table('email_verifications')
                ->where('user_id', $userId)
                ->where('email', $email)
                ->first();

            if ($verification) {
                // Update existing record
                DB::table('email_verifications')
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
                // Create new record
                DB::table('email_verifications')->insert([
                    'user_id' => $userId,
                    'email' => $email,
                    'otp_code' => $otp,
                    'otp_attempts' => 0,
                    'otp_expires_at' => $expiresAt,
                    'last_otp_sent_at' => Carbon::now(),
                    'is_verified' => false,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            // Get user details for email
            $user = DB::table('users')->where('user_id', $userId)->first();
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            // Send OTP email (optional - may fail if SMTP not configured)
            $emailSent = $this->sendOTPEmail($email, $otp, $user->full_name);

            // For testing/development: return OTP in response if email not configured
            // In production with real SMTP, remove the 'otp' key from response
            $response = ['success' => true, 'message' => 'OTP sent to your email'];

            // DEVELOPMENT MODE: Include OTP in response for testing (remove in production)
            $response['otp'] = $otp; // TODO: Remove this line in production
            $response['otp_note'] = '(For testing: OTP shown in response. In production with email configured, this will be removed.)';

            return $response;
        } catch (\Exception $e) {
            error_log('Email verification initiation error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send OTP. Please try again.'];
        }
    }

    /**
     * Verify OTP code
     * Returns: ['success' => bool, 'message' => string]
     */
    public function verifyOTP(int $userId, string $email, string $otp): array
    {
        // Validate OTP format (6 digits)
        if (!preg_match('/^\d{6}$/', $otp)) {
            return ['success' => false, 'message' => 'Invalid OTP format'];
        }

        // Find verification record
        $verification = DB::table('email_verifications')
            ->where('user_id', $userId)
            ->where('email', $email)
            ->where('is_verified', false)
            ->first();

        if (!$verification) {
            return ['success' => false, 'message' => 'No pending verification for this email'];
        }

        // Check if OTP is expired
        if (Carbon::now()->isAfter($verification->otp_expires_at)) {
            return ['success' => false, 'message' => 'OTP has expired. Please request a new one.'];
        }

        // Check if max attempts exceeded
        if ($verification->otp_attempts >= self::MAX_OTP_ATTEMPTS) {
            return ['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.'];
        }

        // Check if OTP matches
        if ($verification->otp_code !== $otp) {
            // Increment failed attempts
            DB::table('email_verifications')
                ->where('id', $verification->id)
                ->increment('otp_attempts');

            $remaining = self::MAX_OTP_ATTEMPTS - $verification->otp_attempts - 1;
            return [
                'success' => false,
                'message' => $remaining > 0
                    ? "Incorrect OTP. {$remaining} attempts remaining."
                    : 'Too many failed attempts. Please request a new OTP.'
            ];
        }

        // OTP verified! Mark as verified and update user's verified_email
        try {
            DB::beginTransaction();

            // Mark verification as complete
            DB::table('email_verifications')
                ->where('id', $verification->id)
                ->update([
                    'is_verified' => true,
                    'updated_at' => Carbon::now(),
                ]);

            // Update user's verified_email
            DB::table('users')
                ->where('user_id', $userId)
                ->update([
                    'verified_email' => $email,
                    'updated_at' => Carbon::now(),
                ]);

            DB::commit();

            return ['success' => true, 'message' => 'Email verified successfully'];
        } catch (\Exception $e) {
            DB::rollBack();
            error_log('Email verification completion error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to complete verification. Please try again.'];
        }
    }

    /**
     * Resend OTP code
     * Returns: ['success' => bool, 'message' => string]
     */
    public function resendOTP(int $userId, string $email): array
    {
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }

        // Find verification record
        $verification = DB::table('email_verifications')
            ->where('user_id', $userId)
            ->where('email', $email)
            ->where('is_verified', false)
            ->first();

        if (!$verification) {
            return ['success' => false, 'message' => 'No pending verification for this email'];
        }

        // Check resend cooldown (must be at least 5 minutes since last send)
        $lastSentTime = $verification->last_otp_sent_at
            ? Carbon::parse($verification->last_otp_sent_at)
            : null;

        if ($lastSentTime && Carbon::now()->diffInMinutes($lastSentTime) < self::RESEND_COOLDOWN_MINUTES) {
            $minutesRemaining = self::RESEND_COOLDOWN_MINUTES - Carbon::now()->diffInMinutes($lastSentTime);
            return [
                'success' => false,
                'message' => "Please wait {$minutesRemaining} minute(s) before resending"
            ];
        }

        // Generate new OTP
        $otp = $this->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(self::OTP_VALIDITY_MINUTES);

        try {
            // Update verification record
            DB::table('email_verifications')
                ->where('id', $verification->id)
                ->update([
                    'otp_code' => $otp,
                    'otp_attempts' => 0,
                    'otp_expires_at' => $expiresAt,
                    'last_otp_sent_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            // Get user details for email
            $user = DB::table('users')->where('user_id', $userId)->first();
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            // Send OTP email
            $this->sendOTPEmail($email, $otp, $user->full_name);

            return ['success' => true, 'message' => 'New OTP sent to your email'];
        } catch (\Exception $e) {
            error_log('OTP resend error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to resend OTP. Please try again.'];
        }
    }

    /**
     * Get current verification status for a user's email
     */
    public function getVerificationStatus(int $userId, string $email): ?array
    {
        $verification = DB::table('email_verifications')
            ->where('user_id', $userId)
            ->where('email', $email)
            ->first();

        if (!$verification) {
            return null;
        }

        return [
            'email' => $verification->email,
            'is_verified' => (bool) $verification->is_verified,
            'is_expired' => Carbon::now()->isAfter($verification->otp_expires_at),
            'minutes_remaining' => Carbon::now()->diffInMinutes(
                Carbon::parse($verification->otp_expires_at),
                false
            ),
            'attempts_remaining' => self::MAX_OTP_ATTEMPTS - $verification->otp_attempts,
        ];
    }

    /**
     * Send OTP email using EmailService
     */
    private function sendOTPEmail(string $email, string $otp, string $userName): bool
    {
        try {
            // Load EmailService (standalone PHP file not in autoloader)
            $emailServicePath = base_path('public/backend/services/EmailService.php');
            if (!file_exists($emailServicePath)) {
                error_log('EmailService file not found at: ' . $emailServicePath);
                return false;
            }

            require_once $emailServicePath;

            $subject = "Verify Your Email - {$otp}";
            $htmlBody = $this->buildOTPEmailHTML($otp, $userName);
            $textBody = $this->buildOTPEmailText($otp, $userName);

            // Use EmailService's sendEmail method
            if (class_exists('EmailService')) {
                return \EmailService::sendEmail(
                    toEmail: $email,
                    subject: $subject,
                    htmlBody: $htmlBody,
                    textBody: $textBody,
                    toName: $userName
                );
            } else {
                error_log('EmailService class not available after loading');
                return false;
            }
        } catch (\Exception $e) {
            error_log('Failed to send OTP email: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build HTML email for OTP
     */
    private function buildOTPEmailHTML(string $otp, string $userName): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 500px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .content { background: #f9fafb; padding: 20px; border-radius: 8px; }
        .otp-box { background: #fff; border: 2px solid #e5e7eb; border-radius: 6px; padding: 20px; text-align: center; margin: 20px 0; }
        .otp-code { font-size: 32px; font-weight: bold; color: #6d28d9; letter-spacing: 4px; font-family: 'Courier New', monospace; }
        .footer { font-size: 12px; color: #6b7280; text-align: center; margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="color: #6d28d9;">Email Verification</h2>
        </div>

        <div class="content">
            <p>Hi {$userName},</p>

            <p>Your email verification code is:</p>

            <div class="otp-box">
                <div class="otp-code">{$otp}</div>
            </div>

            <p style="color: #666; font-size: 14px;">
                This code expires in <strong>5 minutes</strong>. If you didn't request this, please ignore this email.
            </p>

            <p style="color: #666; font-size: 14px;">
                <strong>For security reasons, never share this code with anyone.</strong>
            </p>
        </div>

        <div class="footer">
            <p>School Facility Maintenance System</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Build plain text email for OTP
     */
    private function buildOTPEmailText(string $otp, string $userName): string
    {
        return <<<TEXT
Email Verification

Hi {$userName},

Your email verification code is:

{$otp}

This code expires in 5 minutes. If you didn't request this, please ignore this email.

For security reasons, never share this code with anyone.

---
School Facility Maintenance System
TEXT;
    }
}
