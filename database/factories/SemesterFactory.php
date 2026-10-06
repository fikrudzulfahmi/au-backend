<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Semester;
use App\Models\TahunPelajaran;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Semester> */
class SemesterFactory extends Factory
{
    protected $model = Semester::class;

    public function definition(): array
    {
        return [
            'tahun_pelajaran_id' => TahunPelajaran::factory(),
            'jenis' => 'ganjil',
            'tanggal_mulai' => now()->startOfYear()->toDateString(),
            'tanggal_selesai' => now()->endOfYear()->toDateString(),
            'is_active' => false,
        ];
    }

    public function ganjil(): static
    {
        return $this->state(fn (): array => ['jenis' => 'ganjil']);
    }

    public function genap(): static
    {
        return $this->state(fn (): array => ['jenis' => 'genap']);
    }

    public function aktif(): static
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }
}
