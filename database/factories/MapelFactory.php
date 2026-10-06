<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mapel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mapel> */
class MapelFactory extends Factory
{
    protected $model = Mapel::class;

    public function definition(): array
    {
        return [
            'kode' => strtoupper(fake()->unique()->lexify('????')),
            'nama' => fake()->words(2, true),
            'kelompok' => Mapel::KELOMPOK_UMUM,
            'jurusan_id' => null,
            'is_active' => true,
        ];
    }
}
