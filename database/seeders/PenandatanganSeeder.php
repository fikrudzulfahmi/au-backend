<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Penandatangan;
use App\Models\PengaturanTtd;
use Illuminate\Database\Seeder;

/**
 * Bagian 10 — satu penandatangan (Kepala Sekolah) dengan nama kosong sampai
 * diisi admin, dan tata letak blok tanda tangan awal (FR-KOP-03/04).
 */
class PenandatanganSeeder extends Seeder
{
    public function run(): void
    {
        Penandatangan::updateOrCreate(
            ['jabatan' => 'Kepala Sekolah', 'urutan' => 1],
            [
                'nama' => '',
                'nip' => null,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        PengaturanTtd::updateOrCreate(
            ['id' => 1],
            [
                'kota_penetapan' => 'Blitar',
                'mode_tanggal' => 'otomatis',
                'tanggal_manual' => null,
                'posisi' => 'kanan',
                'tampilkan_mengetahui' => false,
            ],
        );
    }
}
