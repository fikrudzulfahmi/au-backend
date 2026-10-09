<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder PRODUKSI — hanya data struktural + SATU akun admin dari .env.
 *
 * Berbeda dari DatabaseSeeder (yang mengisi data demo/uji): seeder ini mengisi
 * peran, pengaturan, info sekolah, dan penandatangan, lalu membuat SATU akun
 * admin yang kredensialnya dibaca dari ADMIN_USERNAME / ADMIN_PASSWORD /
 * ADMIN_NAME di .env server. TIDAK mengisi siswa/guru/jadwal contoh.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PengaturanSeeder::class,
            InfoSekolahSeeder::class,
            PenandatanganSeeder::class,
        ]);

        $username = (string) (env('ADMIN_USERNAME') ?: 'admin');
        $password = (string) (env('ADMIN_PASSWORD') ?: '');
        $nama = (string) (env('ADMIN_NAME') ?: 'Administrator SIPANDU');

        if ($password === '') {
            throw new \RuntimeException(
                'ADMIN_PASSWORD kosong. Isi ADMIN_USERNAME & ADMIN_PASSWORD di .env server, lalu jalankan ulang seeder.'
            );
        }

        $admin = User::updateOrCreate(
            ['username' => $username],
            [
                'pegawai_id' => null,
                'name' => $nama,
                'password' => $password,
                'wajib_ganti_password' => false,
                'is_active' => true,
            ],
        );

        $admin->roles()->sync([Role::where('kode', Role::ADMIN)->value('id')]);
    }
}
