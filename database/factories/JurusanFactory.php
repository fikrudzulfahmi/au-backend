<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Jurusan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Jurusan> */
class JurusanFactory extends Factory
{
    protected $model = Jurusan::class;

    public function definition(): array
    {
        return [
            'kode' => strtoupper(fake()->unique()->lexify('???')),
            'nama' => 'Kompetensi Keahlian '.fake()->word(),
        ];
    }
}
