<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pegawai;
use App\Models\PenugasanPiket;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PenugasanPiket> */
class PenugasanPiketFactory extends Factory
{
    protected $model = PenugasanPiket::class;

    public function definition(): array
    {
        return [
            'semester_id' => Semester::factory(),
            'pegawai_id' => Pegawai::factory()->state(['jenis_pegawai' => Pegawai::JENIS_GURU]),
            'hari' => fake()->numberBetween(1, 6),
            'catatan' => null,
            'is_active' => true,
        ];
    }

    public function padaHari(int $hari): static
    {
        return $this->state(fn (): array => ['hari' => $hari]);
    }

    public function untukSemester(Semester $semester): static
    {
        return $this->state(fn (): array => ['semester_id' => $semester->id]);
    }

    public function oleh(Pegawai $pegawai): static
    {
        return $this->state(fn (): array => ['pegawai_id' => $pegawai->id]);
    }
}
