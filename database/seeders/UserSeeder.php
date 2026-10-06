<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pegawai;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Bagian 10 — akun awal: admin (tidak terhubung pegawai),
 * kepala sekolah, dan wakasek kurikulum. Selain itu setiap pegawai
 * contoh diberi akun agar aplikasi langsung dapat diuji (FR-PEG-02).
 *
 * Password awal seluruh akun contoh: `Sipandu#2026` dan wajib diganti saat
 * login pertama (FR-SEC-04). Ganti pada produksi.
 */
class UserSeeder extends Seeder
{
    public const PASSWORD_AWAL = 'Sipandu#2026';

    public function run(): void
    {
        // 1. Admin tanpa data pegawai (FR-SEC-03: dikecualikan dari pembatasan perangkat)
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'pegawai_id' => null,
                'name' => 'Administrator SIPANDU',
                'password' => self::PASSWORD_AWAL,
                'wajib_ganti_password' => true,
                'is_active' => true,
            ],
        );
        $admin->roles()->sync([Role::where('kode', Role::ADMIN)->value('id')]);

        // 2. Kepala sekolah — terhubung pegawai struktural "Kepala Sekolah"
        $kepsek = Pegawai::where('jabatan', 'Kepala Sekolah')->first();
        if ($kepsek) {
            $userKepsek = User::updateOrCreate(
                ['username' => $kepsek->nip],
                [
                    'pegawai_id' => $kepsek->id,
                    'name' => $kepsek->nama,
                    'password' => self::PASSWORD_AWAL,
                    'wajib_ganti_password' => true,
                    'is_active' => true,
                ],
            );
            $userKepsek->roles()->sync([
                Role::where('kode', Role::KEPALA_SEKOLAH)->value('id'),
            ]);
        }

        // 3. Wakasek kurikulum — seorang guru dengan tambahan peran (A-11)
        $wakasek = Pegawai::where('nip', '198507152010012004')->first();
        if ($wakasek) {
            $userWakasek = User::updateOrCreate(
                ['username' => $wakasek->nip],
                [
                    'pegawai_id' => $wakasek->id,
                    'name' => $wakasek->nama,
                    'password' => self::PASSWORD_AWAL,
                    'wajib_ganti_password' => true,
                    'is_active' => true,
                ],
            );
            $userWakasek->roles()->sync([
                Role::where('kode', Role::GURU)->value('id'),
                Role::where('kode', Role::WAKASEK_KURIKULUM)->value('id'),
            ]);
        }

        // 4. Akun untuk setiap pegawai lain (peran mengikuti jenis_pegawai, FR-PEG-02)
        Pegawai::query()->orderBy('id')->each(function (Pegawai $pegawai): void {
            $user = User::updateOrCreate(
                ['username' => $pegawai->nip],
                [
                    'pegawai_id' => $pegawai->id,
                    'name' => $pegawai->nama,
                    'password' => self::PASSWORD_AWAL,
                    'wajib_ganti_password' => true,
                    'is_active' => $pegawai->is_active,
                ],
            );

            $kodePeran = $pegawai->isGuru() ? Role::GURU : Role::PEGAWAI_STRUKTURAL;

            $idPeran = [
                Role::where('kode', $kodePeran)->value('id'),
            ];

            // Pertahankan peran khusus yang sudah ditetapkan pada langkah 2 dan 3.
            foreach ([Role::KEPALA_SEKOLAH, Role::WAKASEK_KURIKULUM, Role::ADMIN] as $khusus) {
                if ($user->punyaPeran($khusus)) {
                    $idPeran[] = Role::where('kode', $khusus)->value('id');
                }
            }

            $user->roles()->sync(array_values(array_unique($idPeran)));
        });
    }
}
