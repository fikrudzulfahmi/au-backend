<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PengajuanLuarRadius;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PengajuanLuarRadius */
class PengajuanLuarRadiusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pegawai_id' => $this->pegawai_id,
            'pegawai' => $this->whenLoaded('pegawai', fn () => [
                'id' => $this->pegawai->id,
                'nip' => $this->pegawai->nip,
                'nama' => $this->pegawai->nama,
                'jenis_pegawai' => $this->pegawai->jenis_pegawai,
            ]),
            'tanggal' => $this->tanggal?->toDateString(),
            'alasan' => $this->alasan,
            'ada_lampiran' => $this->lampiran_path !== null,
            'dari_dinas' => $this->pengajuan_izin_id !== null,
            'pengajuan_izin_id' => $this->pengajuan_izin_id,
            'status' => $this->status,
            'label_status' => PengajuanLuarRadius::DAFTAR_STATUS[$this->status] ?? $this->status,
            'catatan_penyetuju' => $this->catatan_penyetuju,
            'diputuskan_pada' => $this->diputuskan_pada?->toIso8601String(),
            'diputuskan_oleh' => $this->whenLoaded('diputuskanOleh', fn () => $this->diputuskanOleh?->name),
        ];
    }
}
