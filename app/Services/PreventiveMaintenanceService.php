<?php

namespace App\Services;

use App\Models\MaintenanceReport;
use App\Models\PreventiveMaintenanceHistory;
use App\Models\PreventiveMaintenanceTask;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreventiveMaintenanceService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        // Repair reports raised from a "Needs Repair" inspection go through
        // the normal report creation path (same audit log + Head
        // notification as any other report) instead of a second insert.
        private readonly ReportService $reportService
    ) {
    }

    /**
     * RBAC per the confirmed plan: Maintenance Staff may VIEW every task
     * (index is never scoped), but may only EDIT or COMPLETE a task whose
     * assigned_user_id is their own user_id — an unassigned task cannot be
     * completed by any staff member. Head Maintenance (maintenance_admin)
     * owns the plan and may manage any task. Mirrors
     * DispatchAuthorizationService's role of being the single place this
     * identity check lives.
     *
     * 2026-09-27: the Administrator (super_admin) is view/monitor only for
     * Preventive Maintenance — PM is performed by Head Maintenance and Staff,
     * so the Administrator no longer edits or completes tasks.
     */
    public function canManageTask(array $authUser, PreventiveMaintenanceTask $task): bool
    {
        $role = (string) ($authUser['role'] ?? '');
        if ($role === 'maintenance_admin') {
            return true;
        }

        if ($role === 'maintenance_staff') {
            return (int) $task->assigned_user_id === (int) ($authUser['user_id'] ?? 0);
        }

        return false;
    }

    /**
     * RBAC update (2026-09-30, by request): completing a task — logging that
     * the work was actually done — is opened up to ANY Maintenance Staff,
     * not just whoever the task happens to be assigned_user_id to. Plans are
     * visible to the whole team (index is never scoped) and, in practice,
     * assignment was frequently left blank, silently hiding the Complete
     * action from everyone. Editing a plan's own details (title, frequency,
     * schedule, etc. via canManageTask() above) intentionally stays
     * assigned-only / Head-Maintenance-only — only the completion action
     * itself is widened here. repairReport() shares this same permission
     * (per its own doc comment) because raising a repair report is just a
     * follow-up to an already-recorded completion.
     */
    public function canCompleteTask(array $authUser, PreventiveMaintenanceTask $task): bool
    {
        $role = (string) ($authUser['role'] ?? '');

        return $role === 'maintenance_admin' || $role === 'maintenance_staff';
    }

    /**
     * Assigning PM tasks to staff is Head Maintenance's responsibility.
     */
    public function canAssignTasks(array $authUser): bool
    {
        return (string) ($authUser['role'] ?? '') === 'maintenance_admin';
    }

    /**
     * Head Maintenance assigns (or unassigns, with null) one or more tasks
     * in one step. Each task goes through updateTask(), so the per-task
     * activity log and the "you have been assigned" notification behave
     * exactly as they do for a single edit.
     *
     * @param  array<int, int>  $taskIds
     * @return int number of tasks updated
     */
    public function assignTasks(array $taskIds, ?int $assigneeId, ?int $actorUserId = null): int
    {
        if ($assigneeId !== null) {
            $isActiveStaff = User::query()
                ->where('user_id', $assigneeId)
                ->where('role', 'maintenance_staff')
                ->where('status', 'active')
                ->exists();
            if (!$isActiveStaff) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => 'Preventive maintenance tasks can only be assigned to active Maintenance Staff.',
                ]);
            }
        }

        $tasks = PreventiveMaintenanceTask::query()->whereIn('id', array_unique(array_map('intval', $taskIds)))->get();
        foreach ($tasks as $task) {
            $this->updateTask($task, ['assigned_user_id' => $assigneeId], $actorUserId);
        }

        return $tasks->count();
    }

    /**
     * Computes the next due date from a completion date + frequency.
     * Uses addMonthsNoOverflow so a Jan 31 completion on a monthly cadence
     * lands on Feb 28/29 instead of overflowing into March — the safest
     * generic rule for all four frequencies, including the annually case
     * (12 months), which also correctly keeps Feb 29 -> Feb 28 on non-leap
     * years instead of throwing/overflowing.
     */
    public function calculateNextDueDate(string $frequency, Carbon|string $from): Carbon
    {
        $base = $from instanceof Carbon ? $from->copy() : Carbon::parse($from);

        $months = match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annually' => 6,
            'annually' => 12,
            default => throw ValidationException::withMessages([
                'frequency' => 'Unknown frequency: ' . $frequency,
            ]),
        };

        return $base->addMonthsNoOverflow($months);
    }

    public function summary(): array
    {
        $active = PreventiveMaintenanceTask::query()->where('is_active', true);

        $tasks = (clone $active)->get(['id', 'next_due_date']);
        $counts = ['upcoming' => 0, 'due_soon' => 0, 'due' => 0, 'overdue' => 0, 'unscheduled' => 0];
        foreach ($tasks as $task) {
            $counts[$task->status] = ($counts[$task->status] ?? 0) + 1;
        }

        $today = Carbon::today();
        $dueThisMonth = (clone $active)
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])
            ->count();
        $dueThisWeek = (clone $active)
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])
            ->count();

        $completedThisMonth = PreventiveMaintenanceHistory::query()
            ->whereBetween('completed_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->count();

        return [
            'total_items' => (clone $active)->count(),
            'active_plans' => (clone $active)->count(),
            'due_this_month' => $dueThisMonth,
            'due_this_week' => $dueThisWeek,
            'due_soon' => $counts['due_soon'],
            'due' => $counts['due'],
            'overdue' => $counts['overdue'],
            'upcoming' => $counts['upcoming'],
            'unscheduled' => $counts['unscheduled'],
            'completed_this_month' => $completedThisMonth,
        ];
    }

    /**
     * Finds the next calendar month (from the manual-derived scheduled_months
     * list) on/after $from, wrapping into next year if every marked month
     * this year has already passed. $inclusive=true allows $from's own month
     * to count (used when a task has never been completed yet — this month's
     * mark is still upcoming); $inclusive=false requires strictly a later
     * month (used right after logging a completion, so the same month isn't
     * immediately re-selected as its own next occurrence).
     *
     * Day is fixed at the 1st of the resulting month — the manual's table
     * only carries month-level resolution, never a specific day.
     */
    public function calculateNextDueDateFromMonths(array $months, Carbon $from, bool $inclusive = true): Carbon
    {
        $sorted = collect($months)->map(fn ($m) => (int) $m)->filter(fn ($m) => $m >= 1 && $m <= 12)->unique()->sort()->values();
        if ($sorted->isEmpty()) {
            throw ValidationException::withMessages([
                'scheduled_months' => 'At least one valid month (1-12) is required.',
            ]);
        }

        $currentMonth = (int) $from->month;
        $next = $sorted->first(fn ($m) => $inclusive ? $m >= $currentMonth : $m > $currentMonth);
        $year = (int) $from->year;
        if ($next === null) {
            $next = $sorted->first();
            $year++;
        }

        return Carbon::create($year, $next, 1)->startOfDay();
    }

    public function createTask(array $data, ?int $actorUserId = null): PreventiveMaintenanceTask
    {
        return DB::transaction(function () use ($data, $actorUserId): PreventiveMaintenanceTask {
            $lastCompleted = $data['last_completed_date'] ?? null;
            $nextDue = $data['next_due_date'] ?? null;
            $scheduledMonths = !empty($data['scheduled_months']) ? array_values($data['scheduled_months']) : null;

            // If a last-completed date is supplied but no explicit next-due
            // date, derive it from the manual's fixed scheduled_months when
            // present (the school's actual X-mark pattern), otherwise from
            // the generic frequency interval — so the record starts
            // consistent rather than requiring the caller to compute it
            // client-side.
            if ($nextDue === null && $scheduledMonths !== null) {
                $base = $lastCompleted !== null ? Carbon::parse($lastCompleted) : Carbon::today();
                $nextDue = $this->calculateNextDueDateFromMonths($scheduledMonths, $base, $lastCompleted === null)->toDateString();
            } elseif ($nextDue === null && $lastCompleted !== null) {
                $nextDue = $this->calculateNextDueDate($data['frequency'], $lastCompleted)->toDateString();
            }

            $task = PreventiveMaintenanceTask::query()->create([
                'category' => $data['category'],
                'title' => $data['title'],
                'item_id' => $data['item_id'] ?? null,
                'building_id' => $data['building_id'] ?? null,
                'floor_id' => $data['floor_id'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'location_name' => $data['location_name'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'assigned_user_id' => $data['assigned_user_id'] ?? null,
                'frequency' => $data['frequency'],
                'scheduled_months' => $scheduledMonths,
                'last_completed_date' => $lastCompleted,
                'next_due_date' => $nextDue,
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'CREATE_PREVENTIVE_MAINTENANCE_TASK',
                    'module' => 'preventive_maintenance',
                    'entity_type' => 'preventive_maintenance_task',
                    'entity_id' => $task->id,
                    'details' => 'Created preventive maintenance task "' . $task->title . '".',
                    'meta' => ['category' => $task->category, 'frequency' => $task->frequency],
                ]);
            }

            $this->notifyAssignment($task, null, $actorUserId);

            return $task->fresh();
        });
    }

    public function updateTask(PreventiveMaintenanceTask $task, array $data, ?int $actorUserId = null): PreventiveMaintenanceTask
    {
        return DB::transaction(function () use ($task, $data, $actorUserId): PreventiveMaintenanceTask {
            $locked = PreventiveMaintenanceTask::query()->whereKey($task->id)->lockForUpdate()->first();
            if (!$locked) {
                throw ValidationException::withMessages(['id' => 'Preventive maintenance task not found.']);
            }

            $previousAssignee = $locked->assigned_user_id !== null ? (int) $locked->assigned_user_id : null;

            // 'category'/'title'/'frequency' are simple overwrite-if-present
            // fields. The location/assignment fields below are nullable by
            // design (e.g. "unassign this task"), so they use
            // array_key_exists rather than isset/?? — a caller explicitly
            // sending null must be able to clear the field, not have it
            // silently ignored.
            foreach (['category', 'title', 'frequency'] as $simple) {
                if (array_key_exists($simple, $data) && $data[$simple] !== null) {
                    $locked->{$simple} = $data[$simple];
                }
            }

            foreach (['item_id', 'building_id', 'floor_id', 'room_id', 'location_name', 'department_id', 'assigned_user_id', 'next_due_date', 'notes'] as $nullable) {
                if (array_key_exists($nullable, $data)) {
                    $locked->{$nullable} = $data[$nullable];
                }
            }

            if (array_key_exists('scheduled_months', $data)) {
                $locked->scheduled_months = !empty($data['scheduled_months']) ? array_values($data['scheduled_months']) : null;

                // Only auto-recompute next_due_date from the new month list
                // when the caller didn't also send an explicit next_due_date
                // in this same request — an explicit value always wins.
                if (!array_key_exists('next_due_date', $data) && $locked->scheduled_months !== null) {
                    $base = $locked->last_completed_date ?? Carbon::today();
                    $locked->next_due_date = $this->calculateNextDueDateFromMonths(
                        $locked->scheduled_months,
                        $base instanceof Carbon ? $base : Carbon::parse($base),
                        $locked->last_completed_date === null
                    )->toDateString();
                }
            }

            $locked->updated_by = $actorUserId;
            $locked->save();

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'UPDATE_PREVENTIVE_MAINTENANCE_TASK',
                    'module' => 'preventive_maintenance',
                    'entity_type' => 'preventive_maintenance_task',
                    'entity_id' => $locked->id,
                    'details' => 'Updated preventive maintenance task "' . $locked->title . '".',
                    'meta' => ['fields' => array_keys($data)],
                ]);
            }

            $newAssignee = $locked->assigned_user_id !== null ? (int) $locked->assigned_user_id : null;
            if ($newAssignee !== $previousAssignee) {
                $this->notifyAssignment($locked, $previousAssignee, $actorUserId);
            }

            return $locked->fresh();
        });
    }

    /**
     * Archive (soft-hide) rather than delete — no hard-delete path exists
     * anywhere in this service, per the task's explicit "no destructive
     * delete" instruction. Toggling is_active back to true un-archives it;
     * history rows are never touched by this method.
     */
    public function setActive(PreventiveMaintenanceTask $task, bool $active, ?int $actorUserId = null): PreventiveMaintenanceTask
    {
        $task->update([
            'is_active' => $active,
            'updated_by' => $actorUserId,
        ]);

        if ($actorUserId) {
            $this->activityLogService->logFromSession([
                'user_id' => $actorUserId,
                'action' => $active ? 'REACTIVATE_PREVENTIVE_MAINTENANCE_TASK' : 'ARCHIVE_PREVENTIVE_MAINTENANCE_TASK',
                'module' => 'preventive_maintenance',
                'entity_type' => 'preventive_maintenance_task',
                'entity_id' => $task->id,
                'details' => ($active ? 'Reactivated' : 'Archived') . ' preventive maintenance task "' . $task->title . '".',
            ]);
        }

        return $task->fresh();
    }

    /**
     * Marks a task's current cycle complete: writes an append-only history
     * row, then rolls the task forward (last_completed_date + next_due_date)
     * from the frequency. Never overwrites or deletes a prior history row.
     *
     * @return array{task: PreventiveMaintenanceTask, history: PreventiveMaintenanceHistory}
     */
    public function completeTask(
        PreventiveMaintenanceTask $task,
        array $data,
        ?UploadedFile $proof = null,
        ?int $actorUserId = null
    ): array {
        return DB::transaction(function () use ($task, $data, $proof, $actorUserId): array {
            $locked = PreventiveMaintenanceTask::query()->whereKey($task->id)->lockForUpdate()->first();
            if (!$locked) {
                throw ValidationException::withMessages(['id' => 'Preventive maintenance task not found.']);
            }

            $completedDate = Carbon::parse($data['completed_date'] ?? Carbon::today()->toDateString());
            $nextDue = !empty($locked->scheduled_months)
                ? $this->calculateNextDueDateFromMonths($locked->scheduled_months, $completedDate, false)
                : $this->calculateNextDueDate($locked->frequency, $completedDate);

            $proofPath = $proof !== null ? $this->storeCompletionProof($proof) : null;

            $history = PreventiveMaintenanceHistory::query()->create([
                'preventive_maintenance_task_id' => $locked->id,
                'completed_date' => $completedDate->toDateString(),
                'performed_by' => $data['performed_by'] ?? $locked->assigned_user_id,
                'recorded_by' => $actorUserId,
                'notes' => $data['notes'] ?? null,
                'findings' => $data['findings'] ?? null,
                'action_taken' => $data['action_taken'] ?? null,
                'condition_result' => $data['condition_result'] ?? null,
                'completion_proof_path' => $proofPath,
                'next_due_date_snapshot' => $nextDue->toDateString(),
            ]);

            $locked->update([
                'last_completed_date' => $completedDate->toDateString(),
                'next_due_date' => $nextDue->toDateString(),
                'updated_by' => $actorUserId,
            ]);

            if ($actorUserId) {
                $this->activityLogService->logFromSession([
                    'user_id' => $actorUserId,
                    'action' => 'COMPLETE_PREVENTIVE_MAINTENANCE_TASK',
                    'module' => 'preventive_maintenance',
                    'entity_type' => 'preventive_maintenance_task',
                    'entity_id' => $locked->id,
                    'details' => 'Recorded completion for "' . $locked->title . '". Next due ' . $nextDue->toDateString() . '.',
                    'meta' => [
                        'history_id' => $history->id,
                        'next_due_date' => $nextDue->toDateString(),
                        'condition_result' => $data['condition_result'] ?? null,
                    ],
                ]);
            }

            // Notify whoever created/manages the task (if different from the
            // person completing it) that the cycle was completed and rolled
            // forward — mirrors Dispatch's "notify the party who isn't the
            // actor" pattern.
            $notifyTarget = (int) ($locked->created_by ?? 0);
            if ($notifyTarget > 0 && $notifyTarget !== $actorUserId) {
                $this->notificationService->notify(
                    $notifyTarget,
                    'Preventive Maintenance Completed',
                    '"' . $locked->title . '" was marked completed. Next due ' . $nextDue->toDateString() . '.',
                    'preventive_maintenance_task',
                    $locked->id
                );
            }

            return [
                'task' => $locked->fresh(),
                'history' => $history,
            ];
        });
    }

    /**
     * Raises the repair report for a "Needs Repair" inspection and links it
     * to that history row. The report is created through
     * ReportService::createGeneralReport() exactly like a report filed from
     * the Create Report page (status "submitted", unassigned, CREATE_REPORT
     * audit entry, Head notified), so the repair then follows the normal
     * report workflow.
     *
     * One report per inspection: the history row is locked and re-checked so
     * a double submit cannot raise two reports.
     *
     * @param  array{priority?: string|null, problem_type?: string|null}  $data
     */
    public function createRepairReport(PreventiveMaintenanceHistory $history, array $data, array $authUser): MaintenanceReport
    {
        return DB::transaction(function () use ($history, $data, $authUser): MaintenanceReport {
            $locked = PreventiveMaintenanceHistory::query()->whereKey($history->id)->lockForUpdate()->first();
            if (!$locked) {
                throw ValidationException::withMessages(['id' => 'Inspection record not found.']);
            }
            if ($locked->condition_result !== 'needs_repair') {
                throw ValidationException::withMessages([
                    'condition_result' => 'A repair report can only be raised for an inspection marked "Needs Repair".',
                ]);
            }
            if ($locked->maintenance_report_id !== null) {
                throw ValidationException::withMessages([
                    'maintenance_report_id' => 'A repair report was already created for this inspection (Report #' . $locked->maintenance_report_id . ').',
                ]);
            }

            $task = PreventiveMaintenanceTask::query()->withTrashed()->findOrFail($locked->preventive_maintenance_task_id);
            [$problemType, $problemTypeOther] = $this->resolveReportProblemType($task, $data['problem_type'] ?? null);
            $location = $task->location_label ?: null;
            $completedOn = $locked->completed_date instanceof Carbon
                ? $locked->completed_date->toDateString()
                : (string) $locked->completed_date;

            $description = trim((string) $locked->findings);
            if ($description === '') {
                $description = 'Equipment found in need of repair during preventive maintenance.';
            }
            if (!empty($locked->action_taken)) {
                $description .= "\n\nAction taken during inspection: " . trim((string) $locked->action_taken);
            }
            $description .= "\n\nRaised from the Preventive Maintenance inspection of " . $task->category
                . ($location ? ' (' . $location . ')' : '') . ' on ' . $completedOn . '.';

            $report = $this->reportService->createGeneralReport([
                'title' => mb_substr('PM Finding: ' . $task->category . ($location ? ' — ' . $location : ''), 0, 255),
                'description' => $description,
                'problem_type' => $problemType,
                'problem_type_other' => $problemTypeOther,
                'location' => $location,
                'priority' => $data['priority'] ?? 'medium',
                'department_id' => $task->department_id ?? ($authUser['department_id'] ?? null),
            ], $authUser, false, (string) ($authUser['full_name'] ?? 'Maintenance personnel'));

            $locked->forceFill(['maintenance_report_id' => $report->report_id])->save();

            $this->activityLogService->logFromSession([
                'user_id' => (int) ($authUser['user_id'] ?? 0),
                'user_role' => $authUser['role'] ?? null,
                'action' => 'CREATE_PREVENTIVE_MAINTENANCE_REPAIR_REPORT',
                'module' => 'preventive_maintenance',
                'entity_type' => 'preventive_maintenance_task',
                'entity_id' => $task->id,
                'details' => 'Raised repair report #' . $report->report_id . ' from the "Needs Repair" inspection of "' . $task->title . '".',
                'meta' => ['history_id' => $locked->id, 'report_id' => $report->report_id],
            ], $authUser);

            return $report;
        });
    }

    /**
     * Problem Type for a PM repair report: the caller's choice when it is in
     * the approved vocabulary, otherwise the equipment's configured default,
     * otherwise "Other" with the equipment name as the specified type.
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolveReportProblemType(PreventiveMaintenanceTask $task, ?string $requested): array
    {
        $allowed = array_column((array) config('maintenance_reports.problem_types', []), 'value');
        $otherValue = (string) config('maintenance_reports.problem_type_other_value', 'Other');

        $type = $requested !== null && in_array($requested, $allowed, true)
            ? $requested
            : (config('preventive_maintenance.report_problem_types.' . $task->category) ?? $otherValue);

        if (!in_array($type, $allowed, true)) {
            $type = $otherValue;
        }

        return [$type, $type === $otherValue ? mb_substr($task->category, 0, 100) : null];
    }

    private function notifyAssignment(PreventiveMaintenanceTask $task, ?int $previousAssignee, ?int $actorUserId): void
    {
        $assignee = $task->assigned_user_id !== null ? (int) $task->assigned_user_id : null;
        if ($assignee === null || $assignee === $previousAssignee || $assignee === $actorUserId) {
            return;
        }

        $this->notificationService->notify(
            $assignee,
            'Preventive Maintenance Task Assigned',
            'You have been assigned to "' . $task->title . '".',
            'preventive_maintenance_task',
            $task->id
        );
    }

    /**
     * Same validation rules and directory-per-feature convention as
     * DamageReportService::storeImage(), just scoped to its own uploads
     * subfolder so the two features never collide on filenames.
     */
    private function storeCompletionProof(UploadedFile $file): string
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt, true)) {
            throw ValidationException::withMessages([
                'completion_proof' => 'Only JPG, PNG, WEBP, or GIF images are allowed.',
            ]);
        }

        $targetDir = public_path('frontend/uploads/pm-completion-proofs');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw ValidationException::withMessages([
                'completion_proof' => 'Unable to prepare upload directory.',
            ]);
        }

        $fileName = 'pm-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $file->move($targetDir, $fileName);

        return '/frontend/uploads/pm-completion-proofs/' . $fileName;
    }
}
