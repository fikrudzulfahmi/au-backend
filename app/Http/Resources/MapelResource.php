<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MapelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode' => $this->kode,
            'nama' => $this->nama,
            'kelompok' => $this->kelompok,
            'jurusan_id' => $this->jurusan_id,
            'jurusan' => $this->whenLoaded('jurusan', fn () => $this->jurusan?->nama),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
