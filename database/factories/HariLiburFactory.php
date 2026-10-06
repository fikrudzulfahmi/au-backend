<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HariLibur;
use App\Models\TahunPelajaran;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HariLibur> */
class HariLiburFactory extends Factory
{
    protected $model = HariLibur::class;

    public function definition(): array
    {
        $tanggal = fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d');

        return [
            'tahun_pelajaran_id' => TahunPelajaran::factory(),
            'tanggal_mulai' => $tanggal,
            'tanggal_selesai' => $tanggal,
            'keterangan' => fake()->randomElement(['Hari Kemerdekaan', 'Cuti Bersama', 'Libur Semester', 'Hari Raya']),
        ];
    }
}
