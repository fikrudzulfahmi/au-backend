<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Pegawai;
use App\Models\TahunPelajaran;
use Illuminate\Database\Seeder;

/**
 * Bagian 10 — 6 kelas contoh (X sampai XII) pada tahun pelajaran aktif.
 * BR-02: setiap wali kelas berbeda, sebab satu guru hanya boleh menjadi wali
 * satu kelas per tahun pelajaran.
 */
class KelasSeeder extends Seeder
{
    public function run(): void
    {
        $tahun = TahunPelajaran::where('nama', TahunPelajaranSeeder::NAMA)->first();

        if (! $tahun) {
            return;
        }

        $jurusan = Jurusan::query()->pluck('id', 'kode');
        $wali = Pegawai::query()->where('jenis_pegawai', Pegawai::JENIS_GURU)
            ->orderBy('id')->pluck('id')->values();

        $contoh = [
            ['X TKJ 1', 'X', 'TKJ'],
            ['X RPL 1', 'X', 'RPL'],
            ['XI TKJ 1', 'XI', 'TKJ'],
            ['XI AKL 1', 'XI', 'AKL'],
            ['XII TKJ 1', 'XII', 'TKJ'],
            ['XII RPL 1', 'XII', 'RPL'],
        ];

        foreach ($contoh as $urutan => [$nama, $tingkat, $kodeJurusan]) {
            Kelas::updateOrCreate(
                ['tahun_pelajaran_id' => $tahun->id, 'nama' => $nama],
                [
                    'tingkat' => $tingkat,
                    'jurusan_id' => $jurusan[$kodeJurusan],
                    'wali_kelas_id' => $wali[$urutan] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }
}
