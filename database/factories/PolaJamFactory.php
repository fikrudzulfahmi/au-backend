<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PolaJam;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PolaJam> */
class PolaJamFactory extends Factory
{
    protected $model = PolaJam::class;

    public function definition(): array
    {
        return [
            'semester_id' => Semester::factory(),
            'nama' => 'Pola '.fake()->unique()->numberBetween(1, 9999),
        ];
    }
}
