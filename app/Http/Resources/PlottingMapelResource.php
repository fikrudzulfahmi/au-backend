<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlottingMapel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlottingMapel */
class PlottingMapelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'semester_id' => $this->semester_id,
            'pegawai_id' => $this->pegawai_id,
            'guru' => $this->whenLoaded('pegawai', fn () => $this->pegawai?->nama),
            'mapel_id' => $this->mapel_id,
            'mapel' => $this->whenLoaded('mapel', fn () => [
                'id' => $this->mapel?->id,
                'kode' => $this->mapel?->kode,
                'nama' => $this->mapel?->nama,
                'kelompok' => $this->mapel?->kelompok,
            ]),
            'kelas_id' => $this->kelas_id,
            'kelas' => $this->whenLoaded('kelas', fn () => $this->kelas?->nama),
            'tingkat' => $this->whenLoaded('kelas', fn () => $this->kelas?->tingkat),
            'jp_per_minggu' => $this->jp_per_minggu,
            // FR-JDW-07 — jumlah JP yang sudah terjadwal, untuk peringatan kekurangan.
            'jp_terjadwal' => $this->whenCounted('jadwal'),
            'jumlah_jadwal' => $this->whenCounted('jadwal'),
        ];
    }
}
