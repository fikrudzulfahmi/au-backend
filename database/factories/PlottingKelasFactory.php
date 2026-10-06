<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Kelas;
use App\Models\PlottingKelas;
use App\Models\Siswa;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlottingKelas> */
class PlottingKelasFactory extends Factory
{
    protected $model = PlottingKelas::class;

    public function definition(): array
    {
        // Satu kelas saja, dan tahun pelajarannya diambil dari kelas itu, agar
        // (tahun_pelajaran_id, kelas_id) pasti konsisten — bukan dua kelas berbeda.
        $kelas = Kelas::factory()->create();

        return [
            'tahun_pelajaran_id' => $kelas->tahun_pelajaran_id,
            'siswa_id' => Siswa::factory(),
            'kelas_id' => $kelas->id,
            'status_akhir' => PlottingKelas::BERJALAN,
        ];
    }
}
