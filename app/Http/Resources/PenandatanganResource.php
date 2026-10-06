<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Penandatangan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 7.1 / FR-KOP-03 — penandatangan dokumen resmi.
 *
 * Berkas tanda tangan & stempel TIDAK disajikan sebagai tautan (keduanya privat);
 * hanya penandanya yang dikirim, berkasnya sendiri melewati endpoint berpelindung.
 *
 * @mixin Penandatangan
 */
class PenandatanganResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jabatan' => $this->jabatan,
            'nama' => $this->nama,
            'nip' => $this->nip,
            'urutan' => (int) $this->urutan,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'ada_ttd' => $this->ttd_path !== null,
            'ada_stempel' => $this->stempel_path !== null,
        ];
    }
}
