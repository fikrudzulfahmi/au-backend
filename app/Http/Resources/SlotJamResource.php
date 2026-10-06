<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SlotJam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SlotJam */
class SlotJamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pola_jam_id' => $this->pola_jam_id,
            'urutan' => $this->urutan,
            'tipe' => $this->tipe,
            'label' => $this->label,
            'jam_mulai' => $this->jamMulaiPendek(),
            'jam_selesai' => $this->jamSelesaiPendek(),
            'jam_ke' => $this->jam_ke,
            'boleh_dijadwalkan' => $this->bolehDijadwalkan(),
            'jumlah_jadwal' => $this->whenCounted('jadwal'),
        ];
    }
}
