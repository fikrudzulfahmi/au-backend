<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Bagian 10 — data awal agar aplikasi langsung dapat diuji.
 * Jalankan: php artisan migrate:fresh --seed
 *
 * Urutan penting: peran → pegawai (butuh peran) → akun → tahun pelajaran
 * (butuh dikaitkan ke kelas) → jurusan → kelas (butuh pegawai & jurusan) →
 * mapel → siswa.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PegawaiSeeder::class,
            UserSeeder::class,
            InfoSekolahSeeder::class,
            PengaturanSeeder::class,
            PenandatanganSeeder::class,
            TahunPelajaranSeeder::class,
            JurusanSeeder::class,
            KelasSeeder::class,
            MapelSeeder::class,
            HariLiburSeeder::class,
            SiswaSeeder::class,
        ]);
    }
}
