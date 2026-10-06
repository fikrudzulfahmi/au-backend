<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Bagian 10 — data awal agar aplikasi langsung dapat diuji.
 * Jalankan: php artisan migrate:fresh --seed
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
        ]);
    }
}
