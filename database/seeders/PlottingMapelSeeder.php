<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\Semester;
use App\Models\TahunPelajaran;
use Illuminate\Database\Seeder;

/**
 * 10 — plotting mapel: setiap mapel diampu satu guru, untuk seluruh kelas.
 * JP per minggu diisi sementara; JadwalSeeder menyesuaikannya dengan jumlah JP
 * yang benar-benar terjadwal agar BR-09 dan FR-JDW-07 konsisten sejak awal.
 */
class PlottingMapelSeeder extends Seeder
{
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

        $guru = Pegawai::where('jenis_pegawai', Pegawai::JENIS_GURU)->orderBy('nip')->get()->values();
        $mapel = Mapel::orderBy('kode')->get()->values();
        $kelas = Kelas::where('tahun_pelajaran_id', $tahun->id)->orderBy('nama')->get();

        if ($guru->isEmpty() || $mapel->isEmpty() || $kelas->isEmpty()) {
            return;
        }

        // Satu guru mengampu satu mapel (dipakai JadwalSeeder untuk menghindari bentrok).
        foreach ($kelas as $k) {
            foreach ($mapel as $indeks => $m) {
                $pengampu = $guru[$indeks % $guru->count()];

                PlottingMapel::updateOrCreate(
                    ['semester_id' => $semester->id, 'mapel_id' => $m->id, 'kelas_id' => $k->id],
                    ['pegawai_id' => $pengampu->id, 'jp_per_minggu' => 4],
                );
            }
        }
    }
}
