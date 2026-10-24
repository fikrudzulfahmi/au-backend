<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\PengaturanService;
use App\Support\UrlBerkasSekolah;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-SCH-02 / FR-LND-09 — data Info Sekolah untuk landing page, kop, dan aplikasi.
 * Hanya berisi data sekolah; tidak ada data pegawai, siswa, atau kehadiran (BR-34).
 */
class SekolahResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PengaturanService $pengaturan */
        $pengaturan = app(PengaturanService::class);

        $media = (array) ($this->media_sosial ?? []);

        return [
            'nama_sekolah' => $this->nama_sekolah,
            'npsn' => $this->npsn,
            'status_sekolah' => $this->status_sekolah,
            'akreditasi' => $this->akreditasi,
            'tagline' => $this->tagline,
            'tentang' => $this->tentang,
            'visi' => $this->visi,
            'misi' => $this->misi,
            'nama_kepala_sekolah' => $this->nama_kepala_sekolah,
            'nip_kepala_sekolah' => $this->nip_kepala_sekolah,
            'alamat_jalan' => $this->alamat_jalan,
            'dusun' => $this->dusun,
            'desa_kelurahan' => $this->desa_kelurahan,
            'kecamatan' => $this->kecamatan,
            'kabupaten_kota' => $this->kabupaten_kota,
            'provinsi' => $this->provinsi,
            'kode_pos' => $this->kode_pos,
            'telepon' => $this->telepon,
            'email' => $this->email,
            'website' => $this->website,
            'media_sosial' => [
                'instagram' => $media['instagram'] ?? null,
                'facebook' => $media['facebook'] ?? null,
                'youtube' => $media['youtube'] ?? null,
                'tiktok' => $media['tiktok'] ?? null,
                'x' => $media['x'] ?? null,
                'whatsapp' => $media['whatsapp'] ?? null,
            ],
            // FR-SCH-02 — URL nyata (bukan null) supaya pratinjau logo/favicon
            // tetap tampil setelah halaman dimuat ulang.
            'logo_kiri_url' => UrlBerkasSekolah::url('logo-kiri', $this->logo_kiri_path),
            'logo_kanan_url' => UrlBerkasSekolah::url('logo-kanan', $this->logo_kanan_path),
            'favicon_url' => UrlBerkasSekolah::url('favicon', $this->favicon_path),
            'hero_foto_url' => UrlBerkasSekolah::url('hero', $this->hero_foto_path),
            'alamat_lengkap' => $this->alamatLengkap(),
            'koordinat' => $this->latitude !== null && $this->longitude !== null
                ? ['latitude' => (float) $this->latitude, 'longitude' => (float) $this->longitude]
                : null,
            'landing' => [
                'aktif' => (bool) $pengaturan->ambil('landing_aktif'),
                'judul_hero' => $pengaturan->ambil('landing_judul_hero'),
                'tampilkan_peta' => (bool) $pengaturan->ambil('landing_tampilkan_peta'),
                'tampilkan_pengumuman' => (bool) $pengaturan->ambil('landing_tampilkan_pengumuman'),
            ],
        ];
    }
}
