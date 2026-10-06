<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\TahunPelajaran;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Kelas> */
class KelasFactory extends Factory
{
    protected $model = Kelas::class;

    public function definition(): array
    {
        $tingkat = fake()->randomElement(Kelas::TINGKAT);

        return [
            'tahun_pelajaran_id' => TahunPelajaran::factory(),
            'nama' => $tingkat.' '.strtoupper(fake()->unique()->lexify('???')).' '.fake()->numberBetween(1, 3),
            'tingkat' => $tingkat,
            'jurusan_id' => Jurusan::factory(),
            'wali_kelas_id' => null,
            'is_active' => true,
        ];
    }

    public function tingkat(string $tingkat): static
    {
        return $this->state(fn (): array => ['tingkat' => $tingkat]);
    }
}
