<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\JamKerja;
use Illuminate\Database\Seeder;

/**
 * §10 / FR-LOK-04 — jam kerja contoh.
 * Guru: Senin–Kamis 07.00–15.00, Jumat 07.00–11.30.
 * Struktural: Senin–Jumat 07.30–15.30.
 * Sabtu & Minggu ditandai bukan hari kerja.
 */
class JamKerjaSeeder extends Seeder
{
    public function run(): void
    {
        $pola = [
            JamKerja::JENIS_GURU => [
                1 => ['06:30:00', '07:00:00', '15:00:00'],
                2 => ['06:30:00', '07:00:00', '15:00:00'],
                3 => ['06:30:00', '07:00:00', '15:00:00'],
                4 => ['06:30:00', '07:00:00', '15:00:00'],
                5 => ['06:30:00', '07:00:00', '11:30:00'],
                6 => null,
                7 => null,
            ],
            JamKerja::JENIS_STRUKTURAL => [
                1 => ['06:30:00', '07:30:00', '15:30:00'],
                2 => ['06:30:00', '07:30:00', '15:30:00'],
                3 => ['06:30:00', '07:30:00', '15:30:00'],
                4 => ['06:30:00', '07:30:00', '15:30:00'],
                5 => ['06:30:00', '07:30:00', '11:30:00'],
                6 => null,
                7 => null,
            ],
        ];

        foreach ($pola as $jenis => $perHari) {
            foreach (JamKerja::DAFTAR_HARI as $hari => $nama) {
                $jam = $perHari[$hari] ?? null;

                JamKerja::updateOrCreate(
                    ['jenis_pegawai' => $jenis, 'hari' => $hari],
                    [
                        'is_hari_kerja' => $jam !== null,
                        'buka_presensi' => $jam[0] ?? null,
                        'jam_masuk' => $jam[1] ?? null,
                        'jam_pulang' => $jam[2] ?? null,
                    ],
                );
            }
        }
    }
}
