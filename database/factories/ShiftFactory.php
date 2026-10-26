<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        $branch = Branch::factory();
        $workDate = now(BranchTime::DEFAULT_TIMEZONE)->addDays(fake()->numberBetween(0, 6))->toDateString();
        $start = BranchTime::combineLocalDateAndTime($workDate, '09:00');
        $end = BranchTime::combineLocalDateAndTime($workDate, '17:00');

        return [
            'branch_id' => $branch,
            'user_id' => User::factory(),
            'schedule_week_id' => ScheduleWeek::factory()->for($branch),
            'work_date' => $workDate,
            'starts_at' => BranchTime::toUtc($start),
            'ends_at' => BranchTime::toUtc($end),
        ];
    }
}
