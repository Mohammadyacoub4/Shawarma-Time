<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ScheduleService
{
    public function __construct(
        private readonly WorkPeriodCalculator $calculator,
        private readonly ShiftOverlapValidator $overlapValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertShift(array $data, ?Shift $shift = null): Shift
    {
        return DB::transaction(function () use ($data, $shift) {
            $branch = Branch::findOrFail($data['branch_id']);
            $employee = User::findOrFail($data['user_id']);

            if (! $employee->isAssignedToBranch($branch->id)) {
                throw new InvalidArgumentException('Employee is not assigned to this branch.');
            }

            $period = $this->calculator->fromShiftStartEnd(
                $data['work_date'],
                $data['start_time'],
                $data['end_time'],
                $branch
            );

            if ($this->overlapValidator->hasOverlap(
                $employee->id,
                $period['starts_at'],
                $period['ends_at'],
                $shift?->id
            )) {
                throw new InvalidArgumentException('This shift overlaps another shift for the same employee.');
            }

            $weekStart = BranchTime::weekStart($period['work_date'], $branch);
            $scheduleWeek = $this->findOrCreateWeek($branch, $weekStart);

            $model = $shift ?? new Shift;
            $model->fill([
                'branch_id' => $branch->id,
                'user_id' => $employee->id,
                'schedule_week_id' => $scheduleWeek->id,
                'work_date' => $period['work_date'],
                'starts_at' => $period['starts_at'],
                'ends_at' => $period['ends_at'],
                'grace_minutes' => $data['grace_minutes'] ?? $shift?->grace_minutes ?? $branch->grace_minutes ?? 15,
                'notes' => $data['notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
            ]);
            $model->save();

            return $model;
        });
    }

    /**
     * Bulk create/update shifts for an employee across multiple days of the week.
     *
     * @param  array<string, mixed>  $data
     * @return array<Shift>
     */
    public function batchUpsertShifts(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $branch = Branch::findOrFail($data['branch_id']);
            $employee = User::findOrFail($data['user_id']);

            if (! $employee->isAssignedToBranch($branch->id)) {
                throw new InvalidArgumentException('Employee is not assigned to this branch.');
            }

            $savedShifts = [];
            $days = (array) ($data['days'] ?? []);

            foreach ($days as $dateString) {
                $period = $this->calculator->fromShiftStartEnd(
                    $dateString,
                    $data['start_time'],
                    $data['end_time'],
                    $branch
                );

                // Find if shift already exists for this employee, branch, work_date, and exact time window
                $existingShift = Shift::where('branch_id', $branch->id)
                    ->where('user_id', $employee->id)
                    ->whereDate('work_date', $period['work_date'])
                    ->where('starts_at', $period['starts_at'])
                    ->where('ends_at', $period['ends_at'])
                    ->first();

                $shiftData = [
                    'branch_id' => $branch->id,
                    'user_id' => $employee->id,
                    'work_date' => $period['work_date'],
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'grace_minutes' => $data['grace_minutes'] ?? $existingShift?->grace_minutes ?? $branch->grace_minutes ?? 15,
                    'notes' => array_key_exists('notes', $data) && $data['notes'] !== null ? $data['notes'] : ($existingShift?->notes),
                    'internal_notes' => array_key_exists('internal_notes', $data) && $data['internal_notes'] !== null ? $data['internal_notes'] : ($existingShift?->internal_notes),
                ];

                $savedShifts[] = $this->upsertShift($shiftData, $existingShift);
            }

            return $savedShifts;
        });
    }

    /**
     * Copy all shifts from the previous week to the target week.
     */
    public function copyPreviousWeek(Branch $branch, string $targetWeekStartDate, User $admin): int
    {
        $tz = BranchTime::timezoneFor($branch);
        $targetWeekStart = BranchTime::weekStart($targetWeekStartDate, $branch);
        $prevWeekStart = $targetWeekStart->copy()->subDays(7);
        $prevWeekEnd = BranchTime::weekEnd($prevWeekStart);

        $prevShifts = Shift::where('branch_id', $branch->id)
            ->whereBetween('work_date', [$prevWeekStart->toDateString(), $prevWeekEnd->toDateString()])
            ->orderBy('work_date')
            ->orderBy('starts_at')
            ->get();

        if ($prevShifts->isEmpty()) {
            throw new InvalidArgumentException('لا توجد مناوبات مسجلة في الأسبوع السابق لنسخها / No shifts found in previous week.');
        }

        return DB::transaction(function () use ($branch, $prevShifts, $targetWeekStart, $admin) {
            $copiedCount = 0;

            foreach ($prevShifts as $oldShift) {
                $newWorkDate = $targetWeekStart->copy()->addDays($oldShift->work_date->dayOfWeekIso - 1)->toDateString();

                $startTime = $oldShift->starts_at->timezone(BranchTime::timezoneFor($branch))->format('H:i');
                $endTime = $oldShift->ends_at->timezone(BranchTime::timezoneFor($branch))->format('H:i');

                $period = $this->calculator->fromShiftStartEnd(
                    $newWorkDate,
                    $startTime,
                    $endTime,
                    $branch
                );

                // Find if shift already exists for this exact employee, branch, work_date, and time window
                $existing = Shift::where('branch_id', $branch->id)
                    ->where('user_id', $oldShift->user_id)
                    ->whereDate('work_date', $period['work_date'])
                    ->where('starts_at', $period['starts_at'])
                    ->where('ends_at', $period['ends_at'])
                    ->first();

                $this->upsertShift([
                    'branch_id' => $branch->id,
                    'user_id' => $oldShift->user_id,
                    'work_date' => $period['work_date'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'grace_minutes' => $oldShift->grace_minutes ?? $existing?->grace_minutes ?? $branch->grace_minutes ?? 15,
                    'notes' => $oldShift->notes ?? $existing?->notes,
                    'internal_notes' => $oldShift->internal_notes ?? $existing?->internal_notes,
                ], $existing);

                $copiedCount++;
            }

            return $copiedCount;
        });
    }

    public function publishWeek(Branch $branch, string $weekStartDate, User $admin): ScheduleWeek
    {
        $tz = BranchTime::timezoneFor($branch);
        $weekStart = BranchTime::weekStart($weekStartDate, $branch);
        $weekEnd = BranchTime::weekEnd(Carbon::parse($weekStart->toDateString(), $tz));

        return DB::transaction(function () use ($branch, $weekStart, $weekEnd, $admin) {
            $week = $this->findOrCreateWeek($branch, $weekStart);

            $week->update([
                'is_published' => true,
                'published_at' => now(),
                'published_by' => $admin->id,
            ]);

            // Ensure all shifts belonging to this branch within this week are linked to this published ScheduleWeek
            Shift::where('branch_id', $branch->id)
                ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->update(['schedule_week_id' => $week->id]);

            return $week;
        });
    }

    public function unpublishWeek(Branch $branch, string $weekStartDate): ScheduleWeek
    {
        $weekStart = BranchTime::weekStart($weekStartDate, $branch);

        return DB::transaction(function () use ($branch, $weekStart) {
            $week = $this->findOrCreateWeek($branch, $weekStart);

            $week->update([
                'is_published' => false,
                'published_at' => null,
                'published_by' => null,
            ]);

            return $week;
        });
    }

    public function findOrCreateWeek(Branch $branch, CarbonInterface|string $weekStart): ScheduleWeek
    {
        $dateStr = $weekStart instanceof CarbonInterface ? $weekStart->toDateString() : Carbon::parse($weekStart)->toDateString();
        $week = ScheduleWeek::where('branch_id', $branch->id)
            ->where(function ($q) use ($dateStr) {
                $q->whereDate('week_start', $dateStr)
                  ->orWhere('week_start', 'like', $dateStr.'%');
            })
            ->first();

        if (! $week) {
            $week = ScheduleWeek::create([
                'branch_id' => $branch->id,
                'week_start' => $dateStr,
                'is_published' => false,
            ]);
        }

        return $week;
    }

    public function clearWeek(Branch $branch, string $weekStartDate): int
    {
        $tz = BranchTime::timezoneFor($branch);
        $weekStart = BranchTime::weekStart($weekStartDate, $branch);
        $weekEnd = BranchTime::weekEnd(Carbon::parse($weekStart->toDateString(), $tz));

        return DB::transaction(function () use ($branch, $weekStart, $weekEnd) {
            $shiftIds = Shift::where('branch_id', $branch->id)
                ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->lockForUpdate()
                ->pluck('id');

            if ($shiftIds->isEmpty()) {
                return 0;
            }

            $hasTimesheets = TimesheetEntry::whereIn('shift_id', $shiftIds)->exists();

            if ($hasTimesheets) {
                throw new DomainException('Cannot clear week: one or more shifts have associated timesheet records.');
            }

            return Shift::whereIn('id', $shiftIds)->delete();
        });
    }

    public function deleteShift(Shift $shift): void
    {
        $shift->delete();
    }
}
