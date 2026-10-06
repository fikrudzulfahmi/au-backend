<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HariLibur;
use App\Models\TahunPelajaran;
use Illuminate\Database\Seeder;

/**
 * FR-TP-07 — dua hari libur contoh pada tahun pelajaran aktif agar perhitungan
 * hari kerja dan alpa (BR-24) dapat langsung diuji.
 */
class HariLiburSeeder extends Seeder
{
    public function run(): void
    {
        $tahun = TahunPelajaran::where('nama', TahunPelajaranSeeder::NAMA)->first();

        if (! $tahun) {
            return;
        }

        $contoh = [
            ['2026-08-17', 'Hari Kemerdekaan Republik Indonesia'],
            ['2026-12-25', 'Hari Raya Natal'],
        ];

        foreach ($contoh as [$tanggal, $keterangan]) {
            HariLibur::updateOrCreate(
                ['tahun_pelajaran_id' => $tahun->id, 'tanggal_mulai' => $tanggal],
                ['tanggal_selesai' => $tanggal, 'keterangan' => $keterangan],
            );
        }
    }
}
