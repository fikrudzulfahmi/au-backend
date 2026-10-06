<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Jurnal;
use App\Models\PlottingMapel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Jurnal>
 *
 * semester_id, pegawai_id, dan kelas_id SENGAJA diturunkan dari plotting mapel
 * yang dipakai (lewat closure, yang menerima atribut yang sudah dievaluasi).
 * Bila masing-masing dibuat dengan factory sendiri, datanya tidak konsisten:
 * jurnal bisa menunjuk guru yang tidak mengajar kelas itu.
 */
class JurnalFactory extends Factory
{
    protected $model = Jurnal::class;

    public function definition(): array
    {
        return [
            'plotting_mapel_id' => PlottingMapel::factory(),
            'semester_id' => fn (array $a): ?int => PlottingMapel::find($a['plotting_mapel_id'])?->semester_id,
            'pegawai_id' => fn (array $a): ?int => PlottingMapel::find($a['plotting_mapel_id'])?->pegawai_id,
            'kelas_id' => fn (array $a): ?int => PlottingMapel::find($a['plotting_mapel_id'])?->kelas_id,

            'tanggal' => now()->toDateString(),
            'jam_ke_mulai' => 1,
            'jam_ke_selesai' => 2,
            'materi' => 'Materi uji '.fake()->numberBetween(1, 99),
            'kegiatan' => 'Kegiatan pembelajaran uji.',
            'catatan' => null,
        ];
    }

    /** Rentang jam ke untuk sesi gabungan (FR-JRN-01). */
    public function jamKe(int $mulai, int $selesai): static
    {
        return $this->state(fn (): array => [
            'jam_ke_mulai' => $mulai,
            'jam_ke_selesai' => $selesai,
        ]);
    }

    public function padaTanggal(string $tanggal): static
    {
        return $this->state(fn (): array => ['tanggal' => $tanggal]);
    }

    public function denganPlotting(PlottingMapel $plottingMapel): static
    {
        return $this->state(fn (): array => [
            'plotting_mapel_id' => $plottingMapel->id,
            'semester_id' => $plottingMapel->semester_id,
            'pegawai_id' => $plottingMapel->pegawai_id,
            'kelas_id' => $plottingMapel->kelas_id,
        ]);
    }
}
