<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\PlottingMapel;
use App\Models\PolaJamHari;
use App\Models\Semester;
use App\Models\SlotJam;
use App\Models\TahunPelajaran;
use Illuminate\Database\Seeder;

/**
 * 10 — jadwal contoh TANPA bentrok.
 *
 * Cara penyusunannya sengaja dipilih agar bentrok tidak mungkin terjadi:
 * setiap mapel diampu tepat satu guru, dan pada (hari, slot) yang sama setiap kelas
 * mendapat mapel yang BERBEDA (indeks diputar sebesar offset per hari/slot). Karena
 * itu tidak ada guru yang muncul dua kali pada hari+slot yang sama (BR-06), dan tidak
 * ada kelas yang mendapat dua mapel pada hari+slot yang sama (BR-07).
 *
 * Setelah jadwal tersusun, `jp_per_minggu` setiap plotting disetel sama dengan jumlah
 * JP yang benar-benar terjadwal, sehingga BR-09 tidak dilanggar dan FR-JDW-07 tidak
 * memunculkan peringatan palsu.
 */
class JadwalSeeder extends Seeder
{
    /** Jumlah slot pelajaran yang diisi per hari untuk contoh. */
    private const JP_PER_HARI = 4;

    /** @var list<int> */
    private const HARI = [1, 2, 3, 4, 5];

    public function run(): void
    {
        $tahun = TahunPelajaran::where('status', TahunPelajaran::STATUS_AKTIF)->first();

        if ($tahun === null) {
            return;
        }

        $semester = Semester::where('tahun_pelajaran_id', $tahun->id)->where('is_active', true)->first()
            ?? Semester::where('tahun_pelajaran_id', $tahun->id)->orderBy('jenis')->first();

        if ($semester === null) {
            return;
        }

        $mapel = Mapel::orderBy('kode')->get();
        $kelas = Kelas::where('tahun_pelajaran_id', $tahun->id)->orderBy('nama')->get()->values();

        if ($mapel->isEmpty() || $kelas->isEmpty()) {
            return;
        }

        $plotting = PlottingMapel::where('semester_id', $semester->id)->get()
            ->keyBy(fn (PlottingMapel $p): string => $p->kelas_id.':'.$p->mapel_id);

        Jadwal::where('semester_id', $semester->id)->delete();

        $jumlahPerPlotting = [];

        foreach (self::HARI as $hari) {
            $pola = PolaJamHari::where('semester_id', $semester->id)->where('hari', $hari)->first()?->polaJam;

            if ($pola === null) {
                continue;
            }

            $slotPelajaran = SlotJam::where('pola_jam_id', $pola->id)
                ->where('tipe', SlotJam::PELAJARAN)
                ->orderBy('urutan')
                ->take(self::JP_PER_HARI)
                ->get()
                ->values();

            foreach ($slotPelajaran as $indeksSlot => $slot) {
                // Offset tetap untuk (hari, slot) tertentu; kelas berbeda mendapat mapel berbeda.
                $offset = ($hari * 4 + $indeksSlot) % $mapel->count();

                foreach ($kelas as $indeksKelas => $k) {
                    $m = $mapel[($indeksKelas + $offset) % $mapel->count()];

                    $p = $plotting->get($k->id.':'.$m->id);

                    if ($p === null) {
                        continue;
                    }

                    Jadwal::create([
                        'semester_id' => $semester->id,
                        'hari' => $hari,
                        'slot_jam_id' => $slot->id,
                        'plotting_mapel_id' => $p->id,
                        'pegawai_id' => $p->pegawai_id,
                        'kelas_id' => $p->kelas_id,
                    ]);

                    $kunci = (string) $p->id;
                    $jumlahPerPlotting[$kunci] = ($jumlahPerPlotting[$kunci] ?? 0) + 1;
                }
            }
        }

        foreach ($jumlahPerPlotting as $plottingId => $jumlah) {
            PlottingMapel::where('id', (int) $plottingId)->update(['jp_per_minggu' => $jumlah]);
        }

        // Plotting yang belum mendapat jadwal tetap diberi JP wajar agar tidak 0.
        PlottingMapel::where('semester_id', $semester->id)
            ->where('jp_per_minggu', 0)
            ->update(['jp_per_minggu' => 2]);
    }
}
