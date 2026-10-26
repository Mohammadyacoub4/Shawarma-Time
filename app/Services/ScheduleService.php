<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\User;
use App\Support\BranchTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

            $period = $this->calculator->fromStartEnd(
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
                // Find if shift already exists for this employee, branch, and work_date
                $existingShift = Shift::where('branch_id', $branch->id)
                    ->where('user_id', $employee->id)
                    ->where('work_date', $dateString)
                    ->first();

                $shiftData = [
                    'branch_id' => $branch->id,
                    'user_id' => $employee->id,
                    'work_date' => $dateString,
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'grace_minutes' => $data['grace_minutes'] ?? $branch->grace_minutes ?? 15,
                    'notes' => $data['notes'] ?? null,
                    'internal_notes' => $data['internal_notes'] ?? null,
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
            ->get();

        if ($prevShifts->isEmpty()) {
            throw new InvalidArgumentException('لا توجد مناوبات مسجلة في الأسبوع السابق لنسخها / No shifts found in previous week.');
        }

        return DB::transaction(function () use ($branch, $prevShifts, $targetWeekStart, $admin) {
            $copiedCount = 0;

            foreach ($prevShifts as $oldShift) {
                // Day difference from previous week start
                $dayOffset = Carbon::parse($oldShift->work_date->toDateString(), BranchTime::timezoneFor($branch))->diffInDays($oldShift->starts_at->copy()->startOfWeek());
                $newWorkDate = $targetWeekStart->copy()->addDays($oldShift->work_date->dayOfWeekIso - 1)->toDateString();

                $startTime = $oldShift->starts_at->timezone(BranchTime::timezoneFor($branch))->format('H:i');
                $endTime = $oldShift->ends_at->timezone(BranchTime::timezoneFor($branch))->format('H:i');

                // Check if shift already exists
                $existing = Shift::where('branch_id', $branch->id)
                    ->where('user_id', $oldShift->user_id)
                    ->where('work_date', $newWorkDate)
                    ->first();

                $this->upsertShift([
                    'branch_id' => $branch->id,
                    'user_id' => $oldShift->user_id,
                    'work_date' => $newWorkDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'grace_minutes' => $oldShift->grace_minutes,
                    'notes' => $oldShift->notes,
                    'internal_notes' => $oldShift->internal_notes,
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

        return Shift::where('branch_id', $branch->id)
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->delete();
    }

    public function deleteShift(Shift $shift): void
    {
        $shift->delete();
    }
}
