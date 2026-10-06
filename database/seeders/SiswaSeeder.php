<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Siswa;
use Illuminate\Database\Seeder;

/**
 * Bagian 10 — 60 siswa contoh berstatus aktif.
 * Penempatan siswa ke kelas dilakukan lewat Plotting Kelas (Fase 2), bukan di sini,
 * karena siswa berpindah kelas setiap tahun pelajaran (A-05).
 */
class SiswaSeeder extends Seeder
{
    public function run(): void
    {
        if (Siswa::query()->count() >= 60) {
            return;
        }

        $perlu = 60 - Siswa::query()->count();

        Siswa::factory()->count($perlu)->create();
    }
}
