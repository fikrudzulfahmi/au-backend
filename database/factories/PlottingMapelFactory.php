<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlottingMapel> */
class PlottingMapelFactory extends Factory
{
    protected $model = PlottingMapel::class;

    public function definition(): array
    {
        return [
            'semester_id' => Semester::factory(),
            'pegawai_id' => Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU])->id,
            'mapel_id' => Mapel::factory(),
            'kelas_id' => Kelas::factory(),
            'jp_per_minggu' => fake()->numberBetween(2, 6),
        ];
    }
}
