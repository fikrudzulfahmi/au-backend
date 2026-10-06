<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LokasiPresensi;
use Illuminate\Database\Seeder;

/**
 * §10 — lokasi presensi contoh: satu lokasi sekolah sebagai default (BR-12)
 * dan satu lokasi tambahan untuk menguji pegawai dengan lebih dari satu lokasi.
 *
 * Idempoten: `updateOrCreate` berdasarkan nama, sehingga seeder aman diulang.
 */
class LokasiPresensiSeeder extends Seeder
{
    public function run(): void
    {
        // Lokasi default harus dibuat tanpa melewati penjaga BR-12: tandai default
        // hanya lewat satu baris, dan lepas default lama lebih dahulu.
        LokasiPresensi::query()->where('is_default', true)->update(['is_default' => false, 'penanda_default' => null]);

        LokasiPresensi::updateOrCreate(
            ['nama' => 'SMK Islam Anharul Ulum'],
            [
                'latitude' => -7.8654000,
                'longitude' => 111.4650000,
                'radius_m' => 150,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        LokasiPresensi::updateOrCreate(
            ['nama' => 'Kantor Dinas Pendidikan'],
            [
                'latitude' => -7.8710000,
                'longitude' => 111.4740000,
                'radius_m' => 200,
                'is_default' => false,
                'is_active' => true,
            ],
        );
    }
}
