<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PhoneVerificationService;
use App\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhoneVerificationController extends Controller
{
    use ApiResponder;

    protected PhoneVerificationService $service;

    public function __construct(PhoneVerificationService $service)
    {
        $this->service = $service;
    }

    /**
     * Initiate phone verification - send OTP via SMS
     * POST /api/phone-verification/initiate
     */
    public function initiate(Request $request)
    {
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object) $_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        $validated = $request->validate([
            'phone' => 'required|string|max:30',
        ]);

        $result = $this->service->initiatePhoneVerification(
            (int) $user->user_id,
            $validated['phone']
        );

        if (!$result['success']) {
            return $this->fail($result['message']);
        }

        return $this->ok($result['message'], [
            'phone' => $validated['phone'],
            'expires_in_minutes' => PhoneVerificationService::OTP_VALIDITY_MINUTES,
        ]);
    }

    /**
     * Verify OTP code
     * POST /api/phone-verification/verify-otp
     */
    public function verifyOtp(Request $request)
    {
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object) $_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        $validated = $request->validate([
            'phone' => 'required|string|max:30',
            'otp' => 'required|string|max:6',
        ]);

        $result = $this->service->verifyOTP(
            (int) $user->user_id,
            $validated['phone'],
            $validated['otp']
        );

        if (!$result['success']) {
            return $this->fail($result['message']);
        }

        // Keep the native PHP session in sync immediately so the rest of
        // this request/page (and any legacy page read before the next
        // full page load) sees the newly-verified number right away.
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['phone'] = $validated['phone'];
        }
        if (isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
            $_SESSION['auth_user']['phone'] = $validated['phone'];
        }
        $request->session()->put('user.phone', $validated['phone']);
        $request->session()->put('auth_user.phone', $validated['phone']);

        return $this->ok($result['message'], [
            'phone' => $validated['phone'],
        ]);
    }

    /**
     * Resend OTP code
     * POST /api/phone-verification/resend-otp
     */
    public function resendOtp(Request $request)
    {
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object) $_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        $validated = $request->validate([
            'phone' => 'required|string|max:30',
        ]);

        $result = $this->service->resendOtp(
            (int) $user->user_id,
            $validated['phone']
        );

        if (!$result['success']) {
            return $this->fail($result['message']);
        }

        return $this->ok($result['message'], [
            'phone' => $validated['phone'],
            'expires_in_minutes' => PhoneVerificationService::OTP_VALIDITY_MINUTES,
        ]);
    }

    /**
     * Get phone verification status
     * GET /api/phone-verification/status
     */
    public function getStatus(Request $request)
    {
        $user = Auth::user() ?? (isset($_SESSION['auth_user']) ? (object) $_SESSION['auth_user'] : null);
        if (!$user || !isset($user->user_id)) {
            return $this->fail('Unauthorized', 401);
        }

        $phone = $request->query('phone');
        if (!$phone) {
            return $this->fail('Phone parameter required');
        }

        $status = $this->service->getVerificationStatus(
            (int) $user->user_id,
            $phone
        );

        if (!$status) {
            return $this->fail('No verification found for this phone number');
        }

        return $this->ok('Verification status', $status);
    }
}
