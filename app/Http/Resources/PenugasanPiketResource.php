<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PenugasanPiket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PenugasanPiket */
class PenugasanPiketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'semester_id' => (int) $this->semester_id,
            'semester' => $this->whenLoaded('semester', fn () => $this->semester?->label()),
            'pegawai_id' => (int) $this->pegawai_id,
            'nip' => $this->whenLoaded('pegawai', fn () => $this->pegawai?->nip),
            'nama' => $this->whenLoaded('pegawai', fn () => $this->pegawai?->nama),
            'hari' => (int) $this->hari,
            'nama_hari' => $this->namaHari(),
            'catatan' => $this->catatan,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
