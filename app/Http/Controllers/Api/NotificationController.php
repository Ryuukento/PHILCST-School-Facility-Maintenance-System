<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    use ApiResponder;

    /** Cached once per process: actual PK column name */
    private static ?string $pkColumn    = null;
    private static ?bool   $hasReportId = null;

    private function pk(): string
    {
        if (self::$pkColumn === null) {
            self::$pkColumn = Schema::hasColumn('notifications', 'notification_id')
                ? 'notification_id'
                : 'id';
        }
        return self::$pkColumn;
    }

    private function hasReportId(): bool
    {
        if (self::$hasReportId === null) {
            self::$hasReportId = Schema::hasColumn('notifications', 'report_id');
        }
        return self::$hasReportId;
    }

    private function userId(Request $request): int
    {
        $u = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        return (int) ($u['user_id'] ?? 0);
    }

    private function baseQuery(int $userId)
    {
        $pk = $this->pk();

        if ($this->hasReportId()) {
            return DB::table('notifications as n')
                ->select([
                    'n.*',
                    DB::raw("n.{$pk} AS notification_id"),
                    'r.title as report_title',
                    'r.priority',
                    'n.report_id',
                ])
                ->leftJoin('maintenance_reports as r', 'n.report_id', '=', 'r.report_id')
                ->where('n.user_id', $userId);
        }

        return DB::table('notifications as n')
            ->select([
                'n.*',
                DB::raw("n.{$pk} AS notification_id"),
                DB::raw('NULL AS report_title'),
                DB::raw('NULL AS priority'),
                DB::raw('NULL AS report_id'),
            ])
            ->where('n.user_id', $userId);
    }

    /**
     * GET /api/notifications
     * All notifications + unread_count  (replaces legacy ?action=getAll)
     * Response: { success, data: { notifications: [...], unread_count: N } }
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return $this->fail('Unauthorized', 401);
        }

        $limit  = max(1, min(200, (int) $request->query('limit',  50)));
        $offset = max(0,          (int) $request->query('offset',  0));

        $notifications = $this->baseQuery($userId)
            ->orderByDesc('n.created_at')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $unreadCount = DB::table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->count();

        return $this->ok('Notifications retrieved', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * GET /api/notifications/unread
     * Recent unread notifications + count  (replaces legacy ?action=getUnread)
     * Response: { success, data: { notifications: [...], count: N } }
     */
    public function unread(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return $this->fail('Unauthorized', 401);
        }

        $limit = max(1, min(50, (int) $request->query('limit', 10)));

        $totalUnread = DB::table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->count();

        $notifications = $this->baseQuery($userId)
            ->where('n.is_read', 0)
            ->orderByDesc('n.created_at')
            ->limit($limit)
            ->get();

        return $this->ok('Unread notifications retrieved', [
            'notifications' => $notifications,
            'count'         => $totalUnread,   // real total, not just page size
        ]);
    }

    /**
     * POST /api/notifications/{id}/read
     * Mark one notification as read  (replaces legacy ?action=markAsRead)
     * Response: { success, message: 'Marked as read' }
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return $this->fail('Unauthorized', 401);
        }

        DB::table('notifications')
            ->where($this->pk(), $id)
            ->where('user_id', $userId)
            ->update(['is_read' => 1]);

        return $this->ok('Marked as read');
    }

    /**
     * POST /api/notifications/read-all
     * Mark all of current user's unread notifications as read
     * Response: { success, message: 'All marked as read' }
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return $this->fail('Unauthorized', 401);
        }

        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return $this->ok('All marked as read');
    }

    /**
     * DELETE /api/notifications/{id}
     * Delete a notification owned by the current user
     * Response: { success, message: 'Notification deleted' }
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return $this->fail('Unauthorized', 401);
        }

        $deleted = DB::table('notifications')
            ->where($this->pk(), $id)
            ->where('user_id', $userId)
            ->delete();

        if (!$deleted) {
            return $this->fail('Notification not found', 404);
        }

        return $this->ok('Notification deleted');
    }
}
