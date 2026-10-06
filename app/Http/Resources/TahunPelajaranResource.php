<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** 7.2 — tahun pelajaran beserta semesternya. */
class TahunPelajaranResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'tanggal_mulai' => $this->tanggal_mulai?->format('Y-m-d'),
            'tanggal_selesai' => $this->tanggal_selesai?->format('Y-m-d'),
            'status' => $this->status,
            'jumlah_kelas' => $this->whenCounted('kelas'),
            'semester' => SemesterResource::collection($this->whenLoaded('semester')),
        ];
    }
}
