<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Jadwal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Jadwal */
class JadwalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'semester_id' => $this->semester_id,
            'hari' => $this->hari,
            'nama_hari' => $this->namaHari(),
            'slot_jam_id' => $this->slot_jam_id,
            'jam_mulai' => $this->whenLoaded('slotJam', fn () => $this->slotJam?->jamMulaiPendek()),
            'jam_selesai' => $this->whenLoaded('slotJam', fn () => $this->slotJam?->jamSelesaiPendek()),
            'jam_ke' => $this->whenLoaded('slotJam', fn () => $this->slotJam?->jam_ke),
            'plotting_mapel_id' => $this->plotting_mapel_id,
            'mapel' => $this->whenLoaded('plottingMapel', fn () => $this->plottingMapel?->mapel?->nama),
            'kode_mapel' => $this->whenLoaded('plottingMapel', fn () => $this->plottingMapel?->mapel?->kode),
            'kelas_id' => $this->kelas_id,
            'kelas' => $this->whenLoaded('plottingMapel', fn () => $this->plottingMapel?->kelas?->nama),
            'pegawai_id' => $this->pegawai_id,
            'guru' => $this->whenLoaded('pegawai', fn () => $this->pegawai?->nama),
        ];
    }
}
