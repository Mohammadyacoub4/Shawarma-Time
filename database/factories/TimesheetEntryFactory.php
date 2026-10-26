<?php

namespace Database\Factories;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimesheetEntry>
 */
class TimesheetEntryFactory extends Factory
{
    protected $model = TimesheetEntry::class;

    public function definition(): array
    {
        $workDate = now(BranchTime::DEFAULT_TIMEZONE)->toDateString();
        $start = BranchTime::combineLocalDateAndTime($workDate, '10:00');
        $end = BranchTime::combineLocalDateAndTime($workDate, '18:00');

        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'shift_id' => null,
            'work_date' => $workDate,
            'starts_at' => BranchTime::toUtc($start),
            'ends_at' => BranchTime::toUtc($end),
            'total_minutes' => 480,
            'status' => TimesheetStatus::PendingApproval,
            'submitted_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => TimesheetStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => TimesheetStatus::Rejected,
            'rejection_reason' => 'Hours do not match schedule.',
        ]);
    }
}
