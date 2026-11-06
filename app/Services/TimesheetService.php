<?php

namespace App\Services;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetAuditLog;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TimesheetService
{
    public function __construct(
        private readonly WorkPeriodCalculator $calculator,
        private readonly TimesheetOverlapValidator $overlapValidator,
    ) {}

    public function clockIn(
        User $employee,
        int $branchId,
        ?string $notes = null,
        ?string $ip = null,
        ?string $deviceInfo = null
    ): TimesheetEntry {
        return DB::transaction(function () use ($employee, $branchId, $notes, $ip, $deviceInfo) {
            // STEP 2: Always acquire User row lock first
            $lockedUser = User::where('id', $employee->id)->lockForUpdate()->firstOrFail();

            if (! $lockedUser->isEmployee()) {
                throw new InvalidArgumentException('Only employees can clock in.');
            }

            $branch = Branch::findOrFail($branchId);

            if (! $lockedUser->isAssignedToBranch($branch->id)) {
                throw new InvalidArgumentException('Employee is not assigned to this branch.');
            }

            // Lock and verify no active clock-in exists
            $active = TimesheetEntry::where('user_id', $lockedUser->id)
                ->where(function ($q) {
                    $q->whereNull('ends_at')
                      ->orWhere('status', TimesheetStatus::ClockedIn);
                })
                ->lockForUpdate()
                ->first();

            if ($active) {
                throw new InvalidArgumentException('You are already clocked in. Please clock out first.');
            }

            $now = now();
            $tz = BranchTime::timezoneFor($branch);
            $localNow = $now->copy()->timezone($tz);
            $workDate = $localNow->toDateString();

            // Look for matching scheduled shift within allowed early/ongoing window
            // Allowed window: now >= starts_at - 60 minutes (starts_at <= now + 60 minutes) AND now <= ends_at
            // Shift must not have any existing timesheet entry (including rejected)
            $nowUtcString = $now->copy()->utc()->format('Y-m-d H:i:s');
            $maxStartsAtString = $now->copy()->utc()->addMinutes(60)->format('Y-m-d H:i:s');

            $shift = Shift::where('user_id', $lockedUser->id)
                ->where('branch_id', $branch->id)
                ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
                ->whereDoesntHave('timesheetEntries')
                ->where('starts_at', '<=', $maxStartsAtString)
                ->where('ends_at', '>=', $nowUtcString)
                ->orderBy('starts_at')
                ->first();

            // Validate that clock-in start time doesn't already overlap with an approved or pending timesheet
            // For a clock-in start instant, candidate ends_at is at least now() + 1 second
            $candidateEndUtc = $now->copy()->addSecond();
            $this->overlapValidator->validate(
                userId: $lockedUser->id,
                startsAtUtc: $now,
                endsAtUtc: $candidateEndUtc,
                ignoreEntryId: null,
                shiftId: null
            );

            $isLate = false;
            $lateMinutes = 0;

            if ($shift) {
                $graceMinutes = $shift->grace_minutes ?? $branch->grace_minutes ?? 15;
                $cutoff = $shift->starts_at->copy()->addMinutes($graceMinutes);

                if ($now->greaterThan($cutoff)) {
                    $isLate = true;
                    $lateMinutes = max(1, (int) $shift->starts_at->diffInMinutes($now));
                }
            }

            $entryWorkDate = $shift ? $shift->work_date->toDateString() : $workDate;

            $entry = TimesheetEntry::create([
                'user_id' => $lockedUser->id,
                'branch_id' => $branch->id,
                'shift_id' => $shift?->id,
                'work_date' => $entryWorkDate,
                'starts_at' => $now,
                'ends_at' => null,
                'total_minutes' => 0,
                'is_late' => $isLate,
                'late_minutes' => $lateMinutes,
                'status' => TimesheetStatus::ClockedIn,
                'submitted_at' => $now,
                'employee_notes' => $notes,
                'clock_in_ip' => $ip,
                'device_info' => $deviceInfo,
            ]);

            $this->log($entry, $lockedUser, 'clocked_in', null, $entry->toArray(), $notes);

            return $entry;
        });
    }

    public function clockOut(
        User $employee,
        ?string $notes = null
    ): TimesheetEntry {
        return DB::transaction(function () use ($employee, $notes) {
            // STEP 2: Lock User row first, then TimesheetEntry row
            $lockedUser = User::where('id', $employee->id)->lockForUpdate()->firstOrFail();

            if (! $lockedUser->isEmployee()) {
                throw new InvalidArgumentException('Only employees can clock out.');
            }

            $entry = TimesheetEntry::where('user_id', $lockedUser->id)
                ->where(function ($q) {
                    $q->whereNull('ends_at')
                      ->orWhere('status', TimesheetStatus::ClockedIn);
                })
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                throw new InvalidArgumentException('No active clock-in session found.');
            }

            $now = now();
            if ($now->lessThan($entry->starts_at)) {
                $now = $entry->starts_at->copy();
            }

            // STEP 3: Validate completed interval against any other entries
            $this->overlapValidator->validate(
                userId: $lockedUser->id,
                startsAtUtc: $entry->starts_at,
                endsAtUtc: $now,
                ignoreEntryId: $entry->id,
                shiftId: null
            );

            $totalMinutes = (int) $entry->starts_at->diffInMinutes($now);
            $old = $entry->only(['status', 'ends_at', 'total_minutes']);

            $employeeNotes = $entry->employee_notes;
            if ($notes) {
                $employeeNotes = $employeeNotes ? "{$employeeNotes} | Out: {$notes}" : $notes;
            }

            // Prevent zero-minute completed entries from entering approval workflow without silently rounding up
            $status = $totalMinutes > 0 ? TimesheetStatus::PendingApproval : TimesheetStatus::Rejected;
            $rejectionReason = $totalMinutes > 0 ? null : 'Zero-minute attendance session / فترة دوام مدتها صفر دقيقة.';

            $entry->update([
                'ends_at' => $now,
                'total_minutes' => $totalMinutes,
                'status' => $status,
                'rejection_reason' => $rejectionReason,
                'employee_notes' => $employeeNotes,
            ]);

            $this->log($entry, $lockedUser, 'clocked_out', $old, $entry->fresh()->toArray(), $notes);

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @throws ValidationException
     */
    public function submit(User $employee, array $data, ?TimesheetEntry $existing = null): TimesheetEntry
    {
        return DB::transaction(function () use ($employee, $data, $existing) {
            // STEP 2: Always acquire User row lock first, then TimesheetEntry lock
            $lockedUser = User::where('id', $employee->id)->lockForUpdate()->firstOrFail();

            if (! $lockedUser->isEmployee()) {
                throw new InvalidArgumentException('Only employees can submit timesheets.');
            }

            $entry = null;
            $old = null;

            if ($existing) {
                $entry = TimesheetEntry::where('id', $existing->id)->lockForUpdate()->firstOrFail();

                // Re-read and validate current state after locking
                if ($entry->user_id !== $lockedUser->id) {
                    throw new InvalidArgumentException('Unauthorized timesheet ownership.');
                }

                if ($entry->status !== TimesheetStatus::Rejected) {
                    throw new InvalidArgumentException('Only rejected entries can be resubmitted by employees.');
                }

                $old = $entry->only(['work_date', 'starts_at', 'ends_at', 'total_minutes', 'status', 'branch_id', 'shift_id']);
            } else {
                $entry = new TimesheetEntry;
            }

            $branch = Branch::findOrFail($data['branch_id']);

            if (! $lockedUser->isAssignedToBranch($branch->id)) {
                throw new InvalidArgumentException('Employee is not assigned to this branch.');
            }

            $period = $this->calculator->fromTimesheetStartEnd(
                $data['work_date'],
                $data['start_time'],
                $data['end_time'],
                $branch
            );

            // Future-date validation in branch timezone
            $todayLocal = now(BranchTime::timezoneFor($branch))->toDateString();
            if ($period['work_date'] > $todayLocal) {
                throw ValidationException::withMessages([
                    'work_date' => ['تاريخ العمل لا يمكن أن يكون في المستقبل / The work date cannot be in the future.'],
                ]);
            }

            $shiftId = ! empty($data['shift_id']) ? (int) $data['shift_id'] : null;

            if ($shiftId !== null) {
                $shift = Shift::with('scheduleWeek')->findOrFail($shiftId);

                if ($shift->user_id !== $lockedUser->id) {
                    throw new InvalidArgumentException('Shift does not belong to the employee.');
                }

                if ($shift->branch_id !== $branch->id) {
                    throw new InvalidArgumentException('Shift does not belong to the selected branch.');
                }

                if ($shift->schedule_week_id && ! $shift->scheduleWeek?->is_published) {
                    throw ValidationException::withMessages([
                        'shift_id' => ['لا يمكن تقديم سجل دوام لوردية غير منشورة / Cannot submit a timesheet for an unpublished shift.'],
                    ]);
                }

                if ($shift->work_date->toDateString() !== $period['work_date']) {
                    throw ValidationException::withMessages([
                        'work_date' => ['تاريخ العمل يجب أن يتطابق مع تاريخ الوردية المجدولة / The work date must match the scheduled shift date.'],
                    ]);
                }
            }

            // STEP 3: Overlap validation across all branches and duplicate shift check
            $this->overlapValidator->validate(
                userId: $lockedUser->id,
                startsAtUtc: $period['starts_at'],
                endsAtUtc: $period['ends_at'],
                ignoreEntryId: $entry->id ?: null,
                shiftId: $shiftId
            );

            $entry->fill([
                'user_id' => $lockedUser->id,
                'branch_id' => $data['branch_id'],
                'shift_id' => $shiftId,
                'work_date' => $period['work_date'],
                'starts_at' => $period['starts_at'],
                'ends_at' => $period['ends_at'],
                'total_minutes' => $period['total_minutes'],
                'status' => TimesheetStatus::PendingApproval,
                'submitted_at' => now(),
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'employee_notes' => $data['employee_notes'] ?? null,
            ]);
            $entry->save();

            $this->log($entry, $lockedUser, $existing ? 'resubmitted' : 'submitted', $old, $entry->fresh()->toArray());

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @throws ValidationException
     */
    public function adminUpdate(TimesheetEntry $entry, User $admin, array $data): TimesheetEntry
    {
        return DB::transaction(function () use ($entry, $admin, $data) {
            // STEP 2: Admin operations must identify employee, lock User first, then lock entry
            // Find target employee id first
            $employeeId = $entry->user_id;

            $lockedUser = User::where('id', $employeeId)->lockForUpdate()->firstOrFail();
            $lockedEntry = TimesheetEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            $old = $lockedEntry->only(['work_date', 'starts_at', 'ends_at', 'total_minutes', 'status', 'branch_id', 'shift_id', 'employee_notes', 'reviewed_by', 'reviewed_at']);

            $targetBranchId = (int) $data['branch_id'];
            $targetBranch = Branch::findOrFail($targetBranchId);

            $period = $this->calculator->fromTimesheetStartEnd(
                $data['work_date'],
                $data['start_time'],
                $data['end_time'],
                $targetBranch
            );

            $shiftId = ! empty($data['shift_id']) ? (int) $data['shift_id'] : null;

            if ($shiftId !== null) {
                $shift = Shift::findOrFail($shiftId);

                if ($shift->user_id !== $lockedUser->id) {
                    throw new InvalidArgumentException('Shift does not belong to the timesheet employee.');
                }

                if ($shift->branch_id !== $targetBranchId) {
                    throw new InvalidArgumentException('Shift does not belong to the selected branch.');
                }

                if ($shift->work_date->toDateString() !== $period['work_date']) {
                    throw ValidationException::withMessages([
                        'work_date' => ['تاريخ العمل يجب أن يتطابق مع تاريخ الوردية المجدولة / The work date must match the scheduled shift date.'],
                    ]);
                }
            }

            $substantiveChange = $this->isSubstantiveChange($lockedEntry, $period, $targetBranchId, $shiftId);

            if ($substantiveChange) {
                // STEP 3 & STEP 7: Check overlap excluding the edited entry only when substantive changes occur
                $this->overlapValidator->validate(
                    userId: $lockedUser->id,
                    startsAtUtc: $period['starts_at'],
                    endsAtUtc: $period['ends_at'],
                    ignoreEntryId: $lockedEntry->id,
                    shiftId: $shiftId
                );

                $lockedEntry->fill([
                    'branch_id' => $targetBranchId,
                    'shift_id' => $shiftId,
                    'work_date' => $period['work_date'],
                    'starts_at' => $period['starts_at'],
                    'ends_at' => $period['ends_at'],
                    'total_minutes' => $period['total_minutes'],
                    'employee_notes' => $data['employee_notes'] ?? $lockedEntry->employee_notes,
                ]);

                // STEP 7: Reset approval status only on substantive changes or if currently clocked_in
                if ($lockedEntry->status === TimesheetStatus::ClockedIn
                    || $lockedEntry->status === TimesheetStatus::Approved) {
                    $lockedEntry->status = TimesheetStatus::PendingApproval;
                    $lockedEntry->rejection_reason = null;
                    $lockedEntry->reviewed_by = null;
                    $lockedEntry->reviewed_at = null;
                }
            } else {
                // Non-substantive update (e.g. notes or metadata only):
                // Preserve exact stored timestamps (seconds), duration, and approval metadata.
                if (isset($data['employee_notes'])) {
                    $lockedEntry->employee_notes = $data['employee_notes'];
                }
            }

            $lockedEntry->save();

            $action = $substantiveChange ? 'admin_time_adjusted' : 'admin_metadata_updated';
            $adminNotes = ! empty($data['admin_notes']) ? (string) $data['admin_notes'] : null;

            $this->log($lockedEntry, $admin, $action, $old, $lockedEntry->fresh()->toArray(), $adminNotes);

            return $lockedEntry;
        });
    }

    public function approve(TimesheetEntry $entry, User $admin): TimesheetEntry
    {
        return DB::transaction(function () use ($entry, $admin) {
            // STEP 2: Lock User row first, then TimesheetEntry row
            $lockedUser = User::where('id', $entry->user_id)->lockForUpdate()->firstOrFail();
            $lockedEntry = TimesheetEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            // Re-read and validate current state after locking
            if ($lockedEntry->status !== TimesheetStatus::PendingApproval) {
                throw new InvalidArgumentException('Only pending entries can be approved.');
            }

            if ($lockedEntry->ends_at === null || $lockedEntry->total_minutes <= 0) {
                throw new InvalidArgumentException('Cannot approve a timesheet entry without a valid duration (total minutes must be greater than zero).');
            }

            // Verify before approving that this entry doesn't overlap with another approved entry
            $this->overlapValidator->validate(
                userId: $lockedUser->id,
                startsAtUtc: $lockedEntry->starts_at,
                endsAtUtc: $lockedEntry->ends_at,
                ignoreEntryId: $lockedEntry->id,
                shiftId: $lockedEntry->shift_id
            );

            $old = $lockedEntry->only(['status']);
            $lockedEntry->update([
                'status' => TimesheetStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $this->log($lockedEntry, $admin, 'approved', $old, $lockedEntry->fresh()->only(['status']));

            return $lockedEntry;
        });
    }

    public function reject(TimesheetEntry $entry, User $admin, string $reason): TimesheetEntry
    {
        return DB::transaction(function () use ($entry, $admin, $reason) {
            // STEP 2: Lock User row first, then TimesheetEntry row
            $lockedUser = User::where('id', $entry->user_id)->lockForUpdate()->firstOrFail();
            $lockedEntry = TimesheetEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            // Re-read and validate current state after locking
            if ($lockedEntry->status !== TimesheetStatus::PendingApproval) {
                throw new InvalidArgumentException('Only pending entries can be rejected.');
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw new InvalidArgumentException('Rejection reason is required.');
            }

            $old = $lockedEntry->only(['status', 'rejection_reason']);
            $lockedEntry->update([
                'status' => TimesheetStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $this->log($lockedEntry, $admin, 'rejected', $old, $lockedEntry->fresh()->only(['status', 'rejection_reason']), $reason);

            return $lockedEntry;
        });
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function isSubstantiveChange(TimesheetEntry $entry, array $period, int $branchId, ?int $shiftId): bool
    {
        if ($entry->branch_id !== $branchId || $entry->shift_id !== $shiftId) {
            return true;
        }

        if ($entry->work_date->toDateString() !== $period['work_date']) {
            return true;
        }

        $branch = $entry->branch ?? Branch::find($branchId);
        $tz = BranchTime::timezoneFor($branch);

        $entryStartMinute = $entry->starts_at?->copy()->timezone($tz)->format('Y-m-d H:i');
        $periodStartMinute = $period['starts_at']?->copy()->timezone($tz)->format('Y-m-d H:i');

        if ($entryStartMinute !== $periodStartMinute) {
            return true;
        }

        $entryEndMinute = $entry->ends_at?->copy()->timezone($tz)->format('Y-m-d H:i');
        $periodEndMinute = $period['ends_at']?->copy()->timezone($tz)->format('Y-m-d H:i');

        if ($entryEndMinute !== $periodEndMinute) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function log(
        TimesheetEntry $entry,
        User $performer,
        string $action,
        ?array $old,
        ?array $new,
        ?string $notes = null
    ): void {
        TimesheetAuditLog::create([
            'timesheet_entry_id' => $entry->id,
            'performed_by' => $performer->id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'notes' => $notes,
        ]);
    }
}
