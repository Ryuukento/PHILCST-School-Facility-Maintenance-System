<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Load EmailService (standalone PHP file not in autoloader)
require_once base_path('public/backend/services/EmailService.php');

class EmailVerificationController extends Controller
{
    use ApiResponder;

    protected EmailVerificationService $service;

    public function __construct(EmailVerificationService $service)
    {
        $this->service = $service;
    }

    /**
     * Initiate email verification - send OTP to email
     * POST /api/email-verification/initiate
     */
    public function initiate(Request $request)
    {
        // Get authenticated user
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object)$_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        // Validate request
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        // Call service
        $result = $this->service->initiateEmailVerification(
            (int)$user->user_id,
            $validated['email']
        );

        if (!$result['success']) {
            return $this->fail($result['message']);
        }

        return $this->ok($result['message'], [
            'email' => $validated['email'],
            'expires_in_minutes' => EmailVerificationService::OTP_VALIDITY_MINUTES,
        ]);
    }

    /**
     * Verify OTP code
     * POST /api/email-verification/verify-otp
     */
    public function verifyOtp(Request $request)
    {
        // Get authenticated user
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object)$_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        // Validate request
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'otp' => 'required|string|max:6',
        ]);

        // Call service
        $result = $this->service->verifyOTP(
            (int)$user->user_id,
            $validated['email'],
            $validated['otp']
        );

        if (!$result['success']) {
            return $this->fail($result['message']);
        }

        return $this->ok($result['message'], [
            'verified_email' => $validated['email'],
        ]);
    }

    /**
     * Resend OTP code
     * POST /api/email-verification/resend-otp
     */
    public function resendOtp(Request $request)
    {
        // Get authenticated user
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object)$_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        // Validate request
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        // Call service
        $result = $this->service->resendOtp(
            (int)$user->user_id,
            $validated['email']
        );

        if (!$result['success']) {
            return $this->fail($result['message']);
        }

        return $this->ok($result['message'], [
            'email' => $validated['email'],
            'expires_in_minutes' => EmailVerificationService::OTP_VALIDITY_MINUTES,
        ]);
    }

    /**
     * Get email verification status
     * GET /api/email-verification/status
     */
    public function getStatus(Request $request)
    {
        // Get authenticated user
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object)$_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        // Get email from query parameter
        $email = $request->query('email');
        if (!$email) {
            return $this->fail('Email parameter required');
        }

        $status = $this->service->getVerificationStatus(
            (int)$user->user_id,
            $email
        );

        if (!$status) {
            return $this->fail('No verification found for this email');
        }

        return $this->ok('Verification status', $status);
    }
}
