<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Jadwal;
use App\Models\PlottingMapel;
use App\Models\PolaJam;
use App\Models\PolaJamHari;
use App\Models\SlotJam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Jadwal>
 *
 * Membangun rangkaian yang sah: plotting mapel → pola jam + slot pelajaran untuk
 * hari 1 → jadwal. Sengaja tidak memakai id tetap, karena itu menghasilkan data
 * yang melanggar relasi maupun aturan BR-06/BR-07/BR-08.
 */
class JadwalFactory extends Factory
{
    protected $model = Jadwal::class;

    public function definition(): array
    {
        $plotting = PlottingMapel::factory()->create();

        $pola = PolaJam::factory()->create(['semester_id' => $plotting->semester_id]);

        PolaJamHari::create([
            'pola_jam_id' => $pola->id,
            'semester_id' => $plotting->semester_id,
            'hari' => 1,
        ]);

        $slot = SlotJam::factory()->create(['pola_jam_id' => $pola->id]);

        return [
            'semester_id' => $plotting->semester_id,
            'hari' => 1,
            'slot_jam_id' => $slot->id,
            'plotting_mapel_id' => $plotting->id,
            'pegawai_id' => $plotting->pegawai_id,
            'kelas_id' => $plotting->kelas_id,
        ];
    }
}
