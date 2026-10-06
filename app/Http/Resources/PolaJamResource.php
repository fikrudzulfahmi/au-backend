<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolaJam;
use App\Models\SlotJam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PolaJam */
class PolaJamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'semester_id' => $this->semester_id,
            'nama' => $this->nama,
            'hari' => $this->whenLoaded('hari', fn () => $this->hari->map(fn ($h): array => [
                'id' => $h->id,
                'hari' => $h->hari,
                'nama_hari' => $h->namaHari(),
            ])),
            'slot' => SlotJamResource::collection($this->whenLoaded('slot')),
            'jumlah_jp' => $this->whenLoaded('slot', fn () => $this->slot->where('tipe', SlotJam::PELAJARAN)->count()),
        ];
    }
}
