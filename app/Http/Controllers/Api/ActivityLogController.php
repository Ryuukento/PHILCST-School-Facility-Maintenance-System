<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ApiResponder;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $query = ActivityLog::query()
            ->with('user:user_id,full_name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('q')) {
            $search = strtolower(trim($request->string('q')->toString()));
            $query->where(function ($builder) use ($search): void {
                $builder->whereRaw('LOWER(COALESCE(action, \'\')) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(COALESCE(module, \'\')) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(COALESCE(details, \'\')) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('user', function ($userQuery) use ($search): void {
                        $userQuery->whereRaw('LOWER(full_name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                    });
            });
        }

        if ($request->filled('action')) {
            $query->where('action', strtoupper(trim($request->string('action')->toString())));
        }

        if ($request->filled('module')) {
            $query->where('module', strtolower(trim($request->string('module')->toString())));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to')->toString());
        }

        $perPage = max(1, min(100, $request->integer('per_page', 20)));
        $logs = $query->paginate($perPage);

        return $this->ok('Activity logs retrieved', [
            'logs' => $logs,
        ]);
    }

    public function show(ActivityLog $activityLog)
    {
        $activityLog->load('user:user_id,full_name,email,role,status');

        return $this->ok('Activity log retrieved', [
            'log' => $activityLog,
        ]);
    }

    public function options()
    {
        return $this->ok('Activity log filter options retrieved', [
            'actions' => ActivityLog::query()
                ->whereNotNull('action')
                ->where('action', '<>', '')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->values(),
            'modules' => ActivityLog::query()
                ->whereNotNull('module')
                ->where('module', '<>', '')
                ->distinct()
                ->orderBy('module')
                ->pluck('module')
                ->values(),
        ]);
    }
}
