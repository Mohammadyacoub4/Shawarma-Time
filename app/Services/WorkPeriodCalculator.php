<?php

namespace App\Services;

use App\Models\Branch;
use App\Support\BranchTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class WorkPeriodCalculator
{
    /**
     * Calculate period for actual timesheet entries (minute-level precision).
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, total_minutes: int, work_date: string}
     * @throws ValidationException
     */
    public function fromTimesheetStartEnd(string $workDate, string $startTime, string $endTime, ?Branch $branch = null): array
    {
        return $this->calculatePeriod($workDate, $startTime, $endTime, $branch, enforceQuarterHour: false);
    }

    /**
     * Calculate period for scheduled shifts (strictly enforcing 15-minute increments).
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, total_minutes: int, work_date: string}
     * @throws ValidationException
     */
    public function fromShiftStartEnd(string $workDate, string $startTime, string $endTime, ?Branch $branch = null): array
    {
        return $this->calculatePeriod($workDate, $startTime, $endTime, $branch, enforceQuarterHour: true);
    }

    /**
     * Calculate work period from start and end time strings.
     * Preserves backwards compatibility with any callers expecting quarter-hour enforcement by default.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, total_minutes: int, work_date: string}
     * @throws ValidationException
     */
    public function fromStartEnd(string $workDate, string $startTime, string $endTime, ?Branch $branch = null, bool $enforceQuarterHour = true): array
    {
        return $this->calculatePeriod($workDate, $startTime, $endTime, $branch, $enforceQuarterHour);
    }

    /**
     * Core calculation engine.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, total_minutes: int, work_date: string}
     * @throws ValidationException
     */
    public function calculatePeriod(string $workDate, string $startTime, string $endTime, ?Branch $branch = null, bool $enforceQuarterHour = false): array
    {
        if ($enforceQuarterHour) {
            $this->assertQuarterHour($startTime, 'start_time');
            $this->assertQuarterHour($endTime, 'end_time');
        } else {
            $this->assertValidTimeFormat($startTime, 'start_time');
            $this->assertValidTimeFormat($endTime, 'end_time');
        }

        // Disallow equal start and end time
        if ($startTime === $endTime) {
            throw ValidationException::withMessages([
                'end_time' => ['وقت البداية ووقت النهاية لا يمكن أن يكونا متطابقين / Start time and end time cannot be equal.'],
            ]);
        }

        $startLocal = BranchTime::combineLocalDateAndTime($workDate, $startTime, $branch, 'start_time');

        // Check if end_time represents an overnight shift.
        // If end_time is less than start_time (e.g. 22:00 to 06:00), end falls on the next day.
        $startDateCarbon = BranchTime::parseLocalDate($workDate, $branch);
        $endDateStr = $workDate;

        if (strcmp($endTime, $startTime) < 0) {
            $endDateStr = $startDateCarbon->copy()->addDay()->toDateString();
        }

        $endLocal = BranchTime::combineLocalDateAndTime($endDateStr, $endTime, $branch, 'end_time');

        // If endLocal is still <= startLocal (could theoretically happen with daylight saving transition), advance day
        if ($endLocal->lessThanOrEqualTo($startLocal)) {
            $endDateStr = Carbon::parse($endDateStr, BranchTime::timezoneFor($branch))->addDay()->toDateString();
            $endLocal = BranchTime::combineLocalDateAndTime($endDateStr, $endTime, $branch, 'end_time');
        }

        $startUtc = BranchTime::toUtc($startLocal);
        $endUtc = BranchTime::toUtc($endLocal);

        $minutes = (int) $startUtc->diffInMinutes($endUtc);
        if ($minutes <= 0) {
            throw ValidationException::withMessages([
                'end_time' => ['فترة الدوام غير صالحة / Invalid work period.'],
            ]);
        }

        return [
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
            'total_minutes' => $minutes,
            'work_date' => BranchTime::workDateFromStartsAt($startUtc, $branch),
        ];
    }

    public function assertValidTimeFormat(string $time, string $fieldName = 'time'): void
    {
        if (! preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::withMessages([
                $fieldName => ['يجب أن يكون الوقت بصيغة H:i / Time must be in H:i format.'],
            ]);
        }
    }

    public function assertQuarterHour(string $time, string $fieldName = 'time'): void
    {
        $this->assertValidTimeFormat($time, $fieldName);

        preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m);
        $minutes = (int) $m[2];
        if ($minutes % 15 !== 0) {
            throw ValidationException::withMessages([
                $fieldName => ['يجب أن تكون الأوقات بمضاعفات 15 دقيقة / Times must use 15-minute increments.'],
            ]);
        }
    }

    public function formatHours(int $totalMinutes): string
    {
        $hours = intdiv($totalMinutes, 60);
        $mins = $totalMinutes % 60;

        return sprintf('%d:%02d', $hours, $mins);
    }

    public function decimalHours(int $totalMinutes): float
    {
        return round($totalMinutes / 60, 2);
    }
}
