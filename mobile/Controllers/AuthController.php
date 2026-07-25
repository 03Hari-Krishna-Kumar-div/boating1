<?php

namespace App\Mobile\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FailedLogin;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private ActivityLogService $activityLogService) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $this->ensureIsNotRateLimited($request);

        if (!Auth::attempt($request->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey($request));

            FailedLogin::create([
                'email' => $request->email,
                'ip_address' => $request->ip(),
                'attempted_at' => now(),
            ]);

            $this->activityLogService->log('login_failed', null, null, null,
                "Mobile login failed for {$request->email}");

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Account is disabled. Contact administrator.',
            ], 423);
        }

        RateLimiter::clear($this->throttleKey($request));

        $user->update(['last_activity_at' => now()]);

        $this->activityLogService->log('login_success', $user, null, null,
            'Mobile user logged in successfully');

        $token = $user->createToken('mobile-app', ['*'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_admin' => $user->isAdmin(),
                'is_worker' => $user->isWorker(),
                'is_active' => $user->is_active,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $this->activityLogService->log('logout', $user, null, null,
                'Mobile user logged out');
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_admin' => $user->isAdmin(),
                'is_worker' => $user->isWorker(),
                'is_active' => $user->is_active,
                'last_activity_at' => $user->last_activity_at?->format('Y-m-d\TH:i:s.u\Z'),
            ],
        ]);
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        if (!RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());
    }
}
