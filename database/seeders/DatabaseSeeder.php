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
 *
 * Fase 2 melanjutkan setelah master siap: plotting kelas (siswa → kelas) →
 * plotting mapel (guru pengampu) → pola jam → jadwal contoh tanpa bentrok.
 * JadwalSeeder dijalankan paling akhir karena ia menyesuaikan `jp_per_minggu`
 * setiap plotting dengan jumlah JP yang benar-benar terjadwal.
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

            // ---- Fase 2: plotting & jadwal ----
            PlottingKelasSeeder::class,
            PlottingMapelSeeder::class,
            JamPelajaranSeeder::class,
            JadwalSeeder::class,
        ]);
    }
}
