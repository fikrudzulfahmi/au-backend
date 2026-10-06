<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PengajuanIzin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PengajuanIzin */
class PengajuanIzinResource extends JsonResource
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
            'jenis' => $this->jenis,
            'label_jenis' => PengajuanIzin::DAFTAR_JENIS[$this->jenis] ?? $this->jenis,
            'tanggal_mulai' => $this->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai?->toDateString(),
            'jumlah_hari' => $this->tanggal_mulai && $this->tanggal_selesai
                ? $this->tanggal_mulai->diffInDays($this->tanggal_selesai) + 1
                : null,
            'alasan' => $this->alasan,
            'ada_lampiran' => $this->lampiran_path !== null && $this->lampiran_dihapus_pada === null,
            'presensi_luar_radius' => (bool) $this->presensi_luar_radius,
            'status' => $this->status,
            'label_status' => PengajuanIzin::DAFTAR_STATUS[$this->status] ?? $this->status,
            'membebaskan_presensi' => in_array($this->jenis, PengajuanIzin::JENIS_MEMBEBASKAN, true)
                && $this->status === PengajuanIzin::STATUS_DISETUJUI,
            'catatan_penyetuju' => $this->catatan_penyetuju,
            'dibuat_oleh_admin' => (bool) $this->dibuat_oleh_admin,
            'diputuskan_pada' => $this->diputuskan_pada?->toIso8601String(),
            'diputuskan_oleh' => $this->whenLoaded('diputuskanOleh', fn () => $this->diputuskanOleh?->name),
            'dibuat_pada' => $this->created_at?->toIso8601String(),
        ];
    }
}
