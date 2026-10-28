<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::updateOrCreate(
            ['slug' => 'richmond'],
            [
                'name' => 'Shawarma Time — Richmond',
                'city' => 'Richmond, BC',
                'timezone' => 'Canada/Pacific',
            ]
        );

        Branch::updateOrCreate(
            ['slug' => 'vancouver'],
            [
                'name' => 'Shawarma Time — Vancouver',
                'city' => 'Vancouver, BC',
                'timezone' => 'Canada/Pacific',
            ]
        );
    }
}
