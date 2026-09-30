<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityLogService
{
    public function log(array $payload, ?Request $request = null): ?ActivityLog
    {
        $action = strtoupper(trim((string) ($payload['action'] ?? '')));
        if ($action === '') {
            return null;
        }

        $authUser = $this->resolveAuthUser($request);
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : (int) ($authUser['user_id'] ?? 0);
        $userRole = trim((string) ($payload['user_role'] ?? $authUser['role'] ?? ''));
        if ($userRole === '' && $userId > 0) {
            $userRole = (string) (User::query()->where('user_id', $userId)->value('role') ?? '');
        }
        $module = strtolower(trim((string) ($payload['module'] ?? $payload['entity_type'] ?? 'system')));
        $entityType = $payload['entity_type'] ?? null;
        $entityId = isset($payload['entity_id']) ? (int) $payload['entity_id'] : null;
        $description = $this->sanitizeDescription((string) ($payload['details'] ?? $payload['description'] ?? ''));
        $meta = $payload['meta'] ?? $payload['meta_json'] ?? null;
        $dedupeSeconds = max(0, (int) ($payload['dedupe_window_seconds'] ?? 3));

        $attributes = [
            'user_id' => $userId > 0 ? $userId : null,
            'user_role' => $userRole !== '' ? $userRole : null,
            'action' => $action,
            'module' => $module !== '' ? $module : 'system',
            'entity_type' => $entityType !== null ? (string) $entityType : null,
            'entity_id' => $entityId > 0 ? $entityId : null,
            'details' => $description !== '' ? $description : null,
            'ip_address' => $payload['ip_address'] ?? $request?->ip(),
            'user_agent' => $payload['user_agent'] ?? $request?->userAgent(),
            'meta_json' => is_array($meta) && !empty($meta) ? $meta : null,
        ];

        $attributes['dedupe_key'] = $this->makeDedupeKey($attributes);

        if ($dedupeSeconds > 0) {
            $existing = ActivityLog::query()
                ->where('dedupe_key', $attributes['dedupe_key'])
                ->where('created_at', '>=', Carbon::now()->subSeconds($dedupeSeconds))
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return ActivityLog::query()->create($attributes);
    }

    public function logFromSession(array $payload, array $authUser = []): ?ActivityLog
    {
        $request = request();
        if ($request instanceof Request) {
            return $this->log(array_merge($payload, [
                'user_id' => $payload['user_id'] ?? ($authUser['user_id'] ?? null),
                'user_role' => $payload['user_role'] ?? ($authUser['role'] ?? null),
            ]), $request);
        }

        return $this->log(array_merge($payload, [
            'user_id' => $payload['user_id'] ?? ($authUser['user_id'] ?? null),
            'user_role' => $payload['user_role'] ?? ($authUser['role'] ?? null),
        ]));
    }

    private function resolveAuthUser(?Request $request): array
    {
        if (!$request) {
            return [];
        }

        try {
            $authUser = $request->session()->get('auth_user') ?? $request->session()->get('user') ?? [];
        } catch (\Throwable) {
            return [];
        }

        return is_array($authUser) ? $authUser : [];
    }

    private function sanitizeDescription(string $description): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? '');
        if ($normalized === '') {
            return '';
        }

        return preg_replace('/(password|token|secret)\s*[:=]\s*\S+/i', '$1=[redacted]', $normalized) ?? $normalized;
    }

    private function makeDedupeKey(array $attributes): string
    {
        return sha1(json_encode([
            'user_id' => $attributes['user_id'] ?? null,
            'user_role' => $attributes['user_role'] ?? null,
            'action' => $attributes['action'] ?? null,
            'module' => $attributes['module'] ?? null,
            'entity_type' => $attributes['entity_type'] ?? null,
            'entity_id' => $attributes['entity_id'] ?? null,
            'details' => $attributes['details'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
