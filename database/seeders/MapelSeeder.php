<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Jurusan;
use App\Models\Mapel;
use Illuminate\Database\Seeder;

/** Bagian 10 — 8 mata pelajaran contoh (FR-MPL-01). */
class MapelSeeder extends Seeder
{
    public function run(): void
    {
        $jurusan = Jurusan::query()->pluck('id', 'kode');

        $contoh = [
            ['PABP', 'Pendidikan Agama dan Budi Pekerti', Mapel::KELOMPOK_UMUM, null],
            ['PPKN', 'Pendidikan Pancasila dan Kewarganegaraan', Mapel::KELOMPOK_UMUM, null],
            ['BIND', 'Bahasa Indonesia', Mapel::KELOMPOK_UMUM, null],
            ['MTK', 'Matematika', Mapel::KELOMPOK_UMUM, null],
            ['BING', 'Bahasa Inggris', Mapel::KELOMPOK_UMUM, null],
            ['PJOK', 'Pendidikan Jasmani, Olahraga, dan Kesehatan', Mapel::KELOMPOK_UMUM, null],
            ['PWEB', 'Pemrograman Web dan Perangkat Bergerak', Mapel::KELOMPOK_KEJURUAN, 'RPL'],
            ['JARDAS', 'Jaringan Dasar', Mapel::KELOMPOK_KEJURUAN, 'TKJ'],
        ];

        foreach ($contoh as [$kode, $nama, $kelompok, $kodeJurusan]) {
            Mapel::updateOrCreate(
                ['kode' => $kode],
                [
                    'nama' => $nama,
                    'kelompok' => $kelompok,
                    'jurusan_id' => $kodeJurusan ? $jurusan[$kodeJurusan] : null,
                    'is_active' => true,
                ],
            );
        }
    }
}
