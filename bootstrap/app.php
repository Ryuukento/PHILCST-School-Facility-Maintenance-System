<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Middleware\SyncLegacyPhpSession;

// Notification SyntaxError investigation (2026-08-09): Apache on Windows
// runs a single mpm_winnt process with many worker threads (confirmed via
// apache/logs/error.log — "Child: Starting 150 worker threads"), all
// sharing one OS-level environment table. Laravel's default Dotenv loader
// writes .env values via putenv(), which is process-wide, not per-request
// — so concurrent requests on different threads can transiently race and
// see a blank/default environment (APP_ENV defaulting to "production"
// with no APP_KEY). This was confirmed in storage/logs/laravel.log as
// repeated "production.ERROR: No application encryption key has been
// specified" entries (22 occurrences across 12 days), including one at
// the exact same second as the reported notification.js SyntaxError.
// Disabling putenv (Laravel's own documented fix for this exact class of
// race under persistent/threaded SAPIs, e.g. Octane) makes env resolution
// go through an in-process repository instead of the shared OS table,
// eliminating the race. Must run before the app is booted/env is loaded.
Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'backend/api/*',
        ]);

        $middleware->appendToGroup('web', SyncLegacyPhpSession::class);

        // Laravel's global TrimStrings middleware only exempts its own
        // default field names ('current_password', 'password',
        // 'password_confirmation') from trimming. UserController::
        // updateProfile() (the Account Settings "change password" form)
        // uses the non-standard field name 'new_password', so without this
        // it was silently trimmed here — before the controller ever saw
        // it — while AuthController::login() compares the untrimmed
        // 'password' input via Hash::check(). A new password containing a
        // leading/trailing space would hash correctly on save but then
        // never match again at login. Exempting 'new_password' here keeps
        // exactly what the user typed intact end-to-end.
        $middleware->trimStrings(except: [
            'new_password',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            // HTTP errors keep their own status (unknown route 404, wrong
            // method 405, abort(403), throttling 429, ...). They used to fall
            // through to the generic 500 below, so a mistyped API URL looked
            // like a server crash.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();

                // Laravel turns a missing route-model-bound record into a
                // NotFoundHttpException before this callback runs (so the
                // ModelNotFoundException branch above is only reached by
                // explicit findOrFail() calls); keep the same clean message
                // instead of exposing the model class name.
                if ($e->getPrevious() instanceof ModelNotFoundException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Resource not found.',
                    ], Response::HTTP_NOT_FOUND);
                }

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() !== ''
                        ? $e->getMessage()
                        : (Response::$statusTexts[$status] ?? 'Request failed.'),
                ], $status, $e->getHeaders());
            }

            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Server error.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
