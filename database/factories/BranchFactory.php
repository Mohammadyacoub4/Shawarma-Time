<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => "Shawarma Time — {$name}",
            'slug' => Str::slug($name),
            'city' => "{$name}, BC",
            'timezone' => 'America/Vancouver',
        ];
    }
}
