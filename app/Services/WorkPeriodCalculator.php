<?php

namespace App\Services;

use App\Models\Branch;
use App\Support\BranchTime;
use Carbon\Carbon;
use InvalidArgumentException;

class WorkPeriodCalculator
{
    /**
     * @return array{starts_at: Carbon, ends_at: Carbon, total_minutes: int, work_date: string}
     */
    public function fromStartEnd(string $workDate, string $startTime, string $endTime, ?Branch $branch = null): array
    {
        $this->assertQuarterHour($startTime);
        $this->assertQuarterHour($endTime);

        $startLocal = BranchTime::combineLocalDateAndTime($workDate, $startTime, $branch);
        $endLocal = BranchTime::combineLocalDateAndTime($workDate, $endTime, $branch);

        if ($endLocal->lessThanOrEqualTo($startLocal)) {
            $endLocal = $endLocal->addDay();
        }

        $minutes = (int) $startLocal->diffInMinutes($endLocal);
        if ($minutes <= 0) {
            throw new InvalidArgumentException('Invalid work period.');
        }

        return [
            'starts_at' => BranchTime::toUtc($startLocal),
            'ends_at' => BranchTime::toUtc($endLocal),
            'total_minutes' => $minutes,
            'work_date' => BranchTime::workDateFromStartsAt(BranchTime::toUtc($startLocal), $branch),
        ];
    }

    public function assertQuarterHour(string $time): void
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            throw new InvalidArgumentException('Time must be in H:i format.');
        }

        $minutes = (int) $m[2];
        if ($minutes % 15 !== 0) {
            throw new InvalidArgumentException('Times must use 15-minute increments.');
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
