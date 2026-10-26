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
use InvalidArgumentException;

class TimesheetService
{
    public function __construct(
        private readonly WorkPeriodCalculator $calculator,
    ) {}

    public function clockIn(
        User $employee,
        int $branchId,
        ?string $notes = null,
        ?string $ip = null,
        ?string $deviceInfo = null
    ): TimesheetEntry {
        return DB::transaction(function () use ($employee, $branchId, $notes, $ip, $deviceInfo) {
            $branch = Branch::findOrFail($branchId);

            if (! $employee->isAssignedToBranch($branch->id)) {
                throw new InvalidArgumentException('Employee is not assigned to this branch.');
            }

            // Lock and verify no active clock-in exists
            $active = TimesheetEntry::where('user_id', $employee->id)
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

            // Look for matching scheduled shift for today
            $shift = Shift::where('user_id', $employee->id)
                ->where('branch_id', $branch->id)
                ->whereDate('work_date', $workDate)
                ->orderBy('starts_at')
                ->first();

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

            $entry = TimesheetEntry::create([
                'user_id' => $employee->id,
                'branch_id' => $branch->id,
                'shift_id' => $shift?->id,
                'work_date' => $workDate,
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

            $this->log($entry, $employee, 'clocked_in', null, $entry->toArray(), $notes);

            return $entry;
        });
    }

    public function clockOut(
        User $employee,
        ?string $notes = null
    ): TimesheetEntry {
        return DB::transaction(function () use ($employee, $notes) {
            $entry = TimesheetEntry::where('user_id', $employee->id)
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
            if ($now->lessThanOrEqualTo($entry->starts_at)) {
                $now = $entry->starts_at->copy()->addMinute();
            }

            $totalMinutes = (int) $entry->starts_at->diffInMinutes($now);
            $old = $entry->only(['status', 'ends_at', 'total_minutes']);

            $employeeNotes = $entry->employee_notes;
            if ($notes) {
                $employeeNotes = $employeeNotes ? "{$employeeNotes} | Out: {$notes}" : $notes;
            }

            $entry->update([
                'ends_at' => $now,
                'total_minutes' => $totalMinutes,
                'status' => TimesheetStatus::PendingApproval,
                'employee_notes' => $employeeNotes,
            ]);

            $this->log($entry, $employee, 'clocked_out', $old, $entry->fresh()->toArray(), $notes);

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(User $employee, array $data, ?TimesheetEntry $existing = null): TimesheetEntry
    {
        return DB::transaction(function () use ($employee, $data, $existing) {
            $entry = $existing ?? new TimesheetEntry;
            $old = $existing?->only(['work_date', 'starts_at', 'ends_at', 'total_minutes', 'status', 'branch_id', 'shift_id']);

            if ($existing && $existing->status !== TimesheetStatus::Rejected) {
                throw new InvalidArgumentException('Only rejected entries can be resubmitted by employees.');
            }

            $branch = Branch::findOrFail($data['branch_id']);
            $period = $this->calculator->fromStartEnd(
                $data['work_date'],
                $data['start_time'],
                $data['end_time'],
                $branch
            );

            $entry->fill([
                'user_id' => $employee->id,
                'branch_id' => $data['branch_id'],
                'shift_id' => $data['shift_id'] ?? null,
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

            $this->log($entry, $employee, $existing ? 'resubmitted' : 'submitted', $old, $entry->fresh()->toArray());

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function adminUpdate(TimesheetEntry $entry, User $admin, array $data): TimesheetEntry
    {
        return DB::transaction(function () use ($entry, $admin, $data) {
            $old = $entry->only(['work_date', 'starts_at', 'ends_at', 'total_minutes', 'status', 'branch_id', 'shift_id']);

            $period = $this->calculator->fromStartEnd(
                $data['work_date'],
                $data['start_time'],
                $data['end_time'],
                $entry->branch
            );

            $substantiveChange = $this->isSubstantiveChange($entry, $period, (int) $data['branch_id'], $data['shift_id'] ?? null);

            $entry->fill([
                'branch_id' => $data['branch_id'],
                'shift_id' => $data['shift_id'] ?? null,
                'work_date' => $period['work_date'],
                'starts_at' => $period['starts_at'],
                'ends_at' => $period['ends_at'],
                'total_minutes' => $period['total_minutes'],
                'employee_notes' => $data['employee_notes'] ?? $entry->employee_notes,
            ]);

            if ($substantiveChange && $entry->status === TimesheetStatus::Approved) {
                $entry->status = TimesheetStatus::PendingApproval;
                $entry->rejection_reason = null;
                $entry->reviewed_by = null;
                $entry->reviewed_at = null;
            }

            $entry->save();

            $this->log($entry, $admin, 'admin_edited', $old, $entry->fresh()->toArray());

            return $entry;
        });
    }

    public function approve(TimesheetEntry $entry, User $admin): TimesheetEntry
    {
        return DB::transaction(function () use ($entry, $admin) {
            if ($entry->status !== TimesheetStatus::PendingApproval) {
                throw new InvalidArgumentException('Only pending entries can be approved.');
            }

            $old = $entry->only(['status']);
            $entry->update([
                'status' => TimesheetStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            $this->log($entry, $admin, 'approved', $old, $entry->fresh()->only(['status']));

            return $entry;
        });
    }

    public function reject(TimesheetEntry $entry, User $admin, string $reason): TimesheetEntry
    {
        return DB::transaction(function () use ($entry, $admin, $reason) {
            if ($entry->status !== TimesheetStatus::PendingApproval) {
                throw new InvalidArgumentException('Only pending entries can be rejected.');
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw new InvalidArgumentException('Rejection reason is required.');
            }

            $old = $entry->only(['status', 'rejection_reason']);
            $entry->update([
                'status' => TimesheetStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $this->log($entry, $admin, 'rejected', $old, $entry->fresh()->only(['status', 'rejection_reason']), $reason);

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function isSubstantiveChange(TimesheetEntry $entry, array $period, int $branchId, ?int $shiftId): bool
    {
        return $entry->branch_id !== $branchId
            || $entry->shift_id !== $shiftId
            || $entry->work_date !== $period['work_date']
            || ! $entry->starts_at->equalTo($period['starts_at'])
            || ! $entry->ends_at->equalTo($period['ends_at'])
            || $entry->total_minutes !== $period['total_minutes'];
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
