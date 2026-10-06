<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Kelas;
use App\Models\PlottingKelas;
use App\Models\Siswa;
use App\Models\TahunPelajaran;
use Illuminate\Database\Seeder;

/** 10 — menempatkan 60 siswa ke 6 kelas pada tahun pelajaran aktif. */
class PlottingKelasSeeder extends Seeder
{
    public function run(): void
    {
        $tahun = TahunPelajaran::where('status', TahunPelajaran::STATUS_AKTIF)->first();

        if ($tahun === null) {
            return;
        }

        $kelas = Kelas::where('tahun_pelajaran_id', $tahun->id)->orderBy('nama')->get();

        if ($kelas->isEmpty()) {
            return;
        }

        $siswa = Siswa::where('status', 'aktif')->orderBy('nis')->get();

        foreach ($siswa as $indeks => $murid) {
            $tujuan = $kelas[$indeks % $kelas->count()];

            PlottingKelas::updateOrCreate(
                ['tahun_pelajaran_id' => $tahun->id, 'siswa_id' => $murid->id],
                [
                    'kelas_id' => $tujuan->id,
                    'status_akhir' => PlottingKelas::BERJALAN,
                ],
            );
        }
    }
}
