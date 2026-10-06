<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pegawai;
use Illuminate\Database\Seeder;

/**
 * Bagian 10 — 10 pegawai contoh: 8 guru dan 2 pegawai struktural.
 * Nama bersifat contoh agar aplikasi dapat diuji; data resmi diisi admin.
 */
class PegawaiSeeder extends Seeder
{
    public const CONTOH = [
        ['197801012006041002', 'Ahmad Fauzi, S.Pd.', 'L', 'guru', 'Guru TKJ', 'PNS'],
        ['198203122008012009', 'Siti Aminah, S.Pd.', 'P', 'guru', 'Guru Bahasa Indonesia', 'PNS'],
        ['198507152010012004', 'Dewi Kartika, S.Kom.', 'P', 'guru', 'Guru RPL', 'PPPK'],
        ['198911232014031007', 'Rizky Hidayat, S.Pd.', 'L', 'guru', 'Guru Matematika', 'PPPK'],
        ['199001102015041003', 'Bagus Setiawan, S.Pd.', 'L', 'guru', 'Guru PJOK', 'GTY'],
        ['199204052016042011', 'Nur Laela, S.E.', 'P', 'guru', 'Guru AKL', 'GTY'],
        ['199512302018011005', 'Hendra Pratama, S.Kom.', 'L', 'guru', 'Guru Informatika', 'GTT'],
        ['199609142019032008', 'Anisa Rahmawati, S.Pd.', 'P', 'guru', 'Guru Bahasa Inggris', 'GTT'],
        ['198304202009011003', 'Slamet Riyadi, S.Pd.', 'L', 'struktural', 'Kepala Sekolah', 'PNS'],
        ['198706112012012006', 'Endang Sulistyowati, S.Pd.', 'P', 'struktural', 'Kepala Tata Usaha', 'PNS'],
    ];

    public function run(): void
    {
        foreach (self::CONTOH as [$nip, $nama, $jk, $jenis, $jabatan, $status]) {
            Pegawai::updateOrCreate(
                ['nip' => $nip],
                [
                    'nama' => $nama,
                    'jenis_kelamin' => $jk,
                    'jenis_pegawai' => $jenis,
                    'jabatan' => $jabatan,
                    'status_kepegawaian' => $status,
                    'email' => null,
                    'no_hp' => null,
                    'is_active' => true,
                    'tanggal_lahir' => null,
                ],
            );
        }
    }
}
