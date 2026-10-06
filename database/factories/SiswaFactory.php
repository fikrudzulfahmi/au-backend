<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Siswa;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Siswa> */
class SiswaFactory extends Factory
{
    protected $model = Siswa::class;

    public function definition(): array
    {
        return [
            'nis' => fake()->unique()->numerify('###'.'#####'),
            'nisn' => fake()->unique()->numerify('##########'),
            'nama' => fake()->name(),
            'jenis_kelamin' => fake()->randomElement(['L', 'P']),
            'tempat_lahir' => fake()->city(),
            'tanggal_lahir' => fake()->dateTimeBetween('-19 years', '-14 years')->format('Y-m-d'),
            'tahun_masuk' => (int) fake()->numberBetween(2020, 2026),
            'status' => Siswa::STATUS_AKTIF,
        ];
    }

    public function lulus(): static
    {
        return $this->state(fn (): array => [
            'status' => Siswa::STATUS_LULUS,
            'tanggal_status' => now()->toDateString(),
            'tahun_lulus' => (int) now()->year,
        ]);
    }
}
