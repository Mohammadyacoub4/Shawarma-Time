<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ScheduleWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleWeek>
 */
class ScheduleWeekFactory extends Factory
{
    protected $model = ScheduleWeek::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'week_start' => now('America/Vancouver')->startOfWeek()->toDateString(),
            'is_published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
