<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 5.20 — bentuk respons pengumuman. Tidak memuat data pegawai/siswa/kehadiran,
 * sehingga aman dipakai juga oleh landing page (BR-34).
 */
class PengumumanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'isi' => $this->isi,
            'isi_panjang' => $this->isi_panjang,
            'tipe' => $this->tipe,
            'prioritas' => $this->prioritas,
            'tanggal_mulai' => $this->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai?->toDateString(),
            'jam_mulai' => $this->jam_mulai !== null ? substr((string) $this->jam_mulai, 0, 5) : null,
            'jam_selesai' => $this->jam_selesai !== null ? substr((string) $this->jam_selesai, 0, 5) : null,
            'tampil_app' => (bool) $this->tampil_app,
            'tampil_tv' => (bool) $this->tampil_tv,
            'tampil_landing' => (bool) $this->tampil_landing,
            'gambar_url' => null,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
