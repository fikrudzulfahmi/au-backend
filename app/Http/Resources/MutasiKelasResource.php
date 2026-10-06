<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MutasiKelas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MutasiKelas */
class MutasiKelasResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plotting_kelas_id' => $this->plotting_kelas_id,
            'kelas_asal_id' => $this->kelas_asal_id,
            'kelas_asal' => $this->whenLoaded('kelasAsal', fn () => $this->kelasAsal?->nama),
            'kelas_tujuan_id' => $this->kelas_tujuan_id,
            'kelas_tujuan' => $this->whenLoaded('kelasTujuan', fn () => $this->kelasTujuan?->nama),
            'tanggal' => $this->tanggal?->format('Y-m-d'),
            'alasan' => $this->alasan,
            'dibuat_oleh' => $this->whenLoaded('dibuatOleh', fn () => $this->dibuatOleh?->name),
        ];
    }
}
