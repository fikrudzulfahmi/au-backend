<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Semester;
use App\Models\TahunPelajaran;
use App\Services\TahunPelajaranService;
use Illuminate\Database\Seeder;

/**
 * Bagian 10 — satu tahun pelajaran AKTIF dengan dua semester (ganjil & genap).
 * Semester ganjil aktif karena tanggal seeder berada pada semester tersebut.
 */
class TahunPelajaranSeeder extends Seeder
{
    public const NAMA = '2026/2027';

    public function run(): void
    {
        $tahun = TahunPelajaran::updateOrCreate(
            ['nama' => self::NAMA],
            [
                'tanggal_mulai' => '2026-07-01',
                'tanggal_selesai' => '2027-06-30',
                'status' => TahunPelajaran::STATUS_DRAFT,
            ],
        );

        // FR-TP-02 — dua semester dengan tanggal yang dapat diubah admin.
        $tahun->semester()->updateOrCreate(
            ['jenis' => Semester::JENIS_GANJIL],
            ['tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2026-12-31'],
        );

        $tahun->semester()->updateOrCreate(
            ['jenis' => Semester::JENIS_GENAP],
            ['tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
        );

        // BR-01 — mengaktifkan tahun pelajaran sekaligus menonaktifkan yang lain.
        app(TahunPelajaranService::class)->aktifkan($tahun, Semester::JENIS_GANJIL);
    }
}
