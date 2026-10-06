<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** 7.2 — kelas; nama jurusan dan wali kelas disertakan agar daftar cukup satu panggilan. */
class KelasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tahun_pelajaran_id' => $this->tahun_pelajaran_id,
            'tahun_pelajaran' => $this->whenLoaded('tahunPelajaran', fn () => $this->tahunPelajaran->nama),
            'nama' => $this->nama,
            'tingkat' => $this->tingkat,
            'jurusan_id' => $this->jurusan_id,
            'jurusan' => $this->whenLoaded('jurusan', fn () => [
                'id' => $this->jurusan->id,
                'kode' => $this->jurusan->kode,
                'nama' => $this->jurusan->nama,
            ]),
            'wali_kelas_id' => $this->wali_kelas_id,
            'wali_kelas' => $this->whenLoaded('waliKelas', fn () => $this->waliKelas?->nama),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
