<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProfilSekolah;
use Illuminate\Database\Seeder;

/**
 * Bagian 10 — Info Sekolah awal.
 *
 * NPSN, nama kepala sekolah, NIP, kode pos, telepon, email, media sosial, dan
 * koordinat SENGAJA dikosongkan: admin yang mengisi (jangan diisi data karangan).
 * Nama sekolah dan alamat TIDAK ditulis di kode aplikasi (KP-0.6).
 */
class InfoSekolahSeeder extends Seeder
{
    public function run(): void
    {
        ProfilSekolah::updateOrCreate(
            ['id' => 1],
            [
                'nama_sekolah' => 'SMK Islam Anharul Ulum',
                'npsn' => null,
                'status_sekolah' => 'swasta',
                'akreditasi' => null,
                'tagline' => 'SIPANDU — Sistem Presensi & Jurnal Digital',
                'tentang' => null,
                'visi' => null,
                'misi' => null,
                'nama_kepala_sekolah' => null,
                'nip_kepala_sekolah' => null,
                'alamat_jalan' => 'Jl. Pondok No. 17, RT 02 RW 01',
                'dusun' => 'Dusun Sukosari',
                'desa_kelurahan' => 'Plumpungrejo',
                'kecamatan' => 'Kademangan',
                'kabupaten_kota' => 'Blitar',
                'provinsi' => 'Jawa Timur',
                'kode_pos' => null,
                'telepon' => null,
                'email' => null,
                'website' => null,
                'media_sosial' => [
                    'instagram' => null,
                    'facebook' => null,
                    'youtube' => null,
                    'tiktok' => null,
                    'x' => null,
                    'whatsapp' => null,
                ],
                'latitude' => null,
                'longitude' => null,
                'kop_baris1' => null,
                'kop_baris2' => null,
                'kop_baris3' => null,
                'kop_tampilkan_logo_kiri' => true,
                'kop_tampilkan_logo_kanan' => false,
            ],
        );
    }
}
