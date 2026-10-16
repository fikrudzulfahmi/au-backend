<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pegawai;
use App\Models\PenugasanPiket;
use App\Models\Semester;
use Illuminate\Database\Seeder;

/**
 * §10 / BR-26 — contoh penugasan guru piket untuk semester aktif.
 *
 * Satu guru per hari kerja (Senin–Jumat) supaya menu "Penugasan Guru Piket"
 * dan alur guru piket mengisi jurnal kelas lain dapat langsung dicoba pada
 * instalasi contoh. Dua guru terakhir dipakai bergiliran sebagai petugas Jumat
 * dan Sabtu agar terlihat bahwa piket bukan penugasan tetap satu orang.
 *
 * Idempotent: dijalankan ulang tidak menambah baris (kunci semester+pegawai+hari).
 */
class PenugasanPiketSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::query()->where('is_active', true)->first();

        if ($semester === null) {
            return;
        }

        $guru = Pegawai::query()
            ->where('jenis_pegawai', Pegawai::JENIS_GURU)
            ->where('is_active', true)
            ->orderBy('nama')
            ->get();

        if ($guru->isEmpty()) {
            return;
        }

        $catatan = [
            1 => 'Ruang piket utama',
            2 => 'Ruang piket utama',
            3 => 'Ruang piket utama',
            4 => 'Ruang piket utama',
            5 => 'Mendampingi kegiatan Jumat',
        ];

        foreach ([1, 2, 3, 4, 5] as $hari) {
            /** @var Pegawai $petugas */
            $petugas = $guru[($hari - 1) % $guru->count()];

            PenugasanPiket::updateOrCreate(
                [
                    'semester_id' => $semester->id,
                    'pegawai_id' => $petugas->id,
                    'hari' => $hari,
                ],
                [
                    'catatan' => $catatan[$hari] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }
}
