<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LokasiPresensi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LokasiPresensi */
class LokasiPresensiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'radius_m' => (int) $this->radius_m,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'jumlah_pegawai' => $this->whenCounted('pegawai'),
            'jumlah_presensi' => $this->when(isset($this->jumlah_presensi), fn () => (int) $this->jumlah_presensi),
        ];
    }
}
