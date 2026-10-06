<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JamKerja;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JamKerja>
 *
 * UQ (jenis_pegawai, hari) membuat pembuatan massal harus menyebut hari secara
 * eksplisit — lihat JamKerjaSeeder yang mengisi satu pekan penuh.
 */
class JamKerjaFactory extends Factory
{
    protected $model = JamKerja::class;

    public function definition(): array
    {
        return [
            'jenis_pegawai' => JamKerja::JENIS_GURU,
            'hari' => 1,
            'is_hari_kerja' => true,
            'buka_presensi' => '06:30:00',
            'jam_masuk' => '07:00:00',
            'jam_pulang' => '15:00:00',
        ];
    }

    /** Hari bukan hari kerja: jam tidak diisi. */
    public function libur(): static
    {
        return $this->state(fn (): array => [
            'is_hari_kerja' => false,
            'buka_presensi' => null,
            'jam_masuk' => null,
            'jam_pulang' => null,
        ]);
    }
}
