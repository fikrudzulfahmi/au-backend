<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlottingKelas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlottingKelas */
class PlottingKelasResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tahun_pelajaran_id' => $this->tahun_pelajaran_id,
            'kelas_id' => $this->kelas_id,
            'kelas' => $this->whenLoaded('kelas', fn () => $this->kelas?->nama),
            'tingkat' => $this->whenLoaded('kelas', fn () => $this->kelas?->tingkat),
            'status_akhir' => $this->status_akhir,
            'sudah_diproses' => $this->sudahDiproses(),
            'catatan' => $this->catatan,
            'siswa_id' => $this->siswa_id,
            'siswa' => $this->whenLoaded('siswa', fn () => [
                'id' => $this->siswa?->id,
                'nis' => $this->siswa?->nis,
                'nisn' => $this->siswa?->nisn,
                'nama' => $this->siswa?->nama,
                'jenis_kelamin' => $this->siswa?->jenis_kelamin,
                'status' => $this->siswa?->status,
            ]),
            'plotting_sebelumnya_id' => $this->plotting_sebelumnya_id,
            'mutasi' => MutasiKelasResource::collection($this->whenLoaded('mutasi')),
        ];
    }
}
