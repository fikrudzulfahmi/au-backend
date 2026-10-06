<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PresensiPegawai;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-PRS-02 — rincian presensi.
 *
 * Foto disimpan pada disk privat, sehingga yang dikirim adalah JALUR endpoint
 * berpelindung token, bukan URL publik (lihat PresensiController::foto).
 *
 * @mixin PresensiPegawai
 */
class PresensiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tanggal' => $this->tanggal?->toDateString(),
            'pegawai_id' => $this->pegawai_id,
            'pegawai' => $this->whenLoaded('pegawai', fn () => [
                'id' => $this->pegawai->id,
                'nip' => $this->pegawai->nip,
                'nama' => $this->pegawai->nama,
                'jenis_pegawai' => $this->pegawai->jenis_pegawai,
            ]),

            'masuk' => [
                'waktu' => $this->masuk_waktu?->toIso8601String(),
                'jam' => $this->masuk_waktu?->format('H:i'),
                'tanggal' => $this->masuk_waktu?->toDateString(),
                'lat' => $this->masuk_lat,
                'lng' => $this->masuk_lng,
                'akurasi_m' => $this->masuk_akurasi_m,
                'jarak_m' => $this->masuk_jarak_m,
                'lokasi_id' => $this->masuk_lokasi_id,
                'lokasi' => $this->whenLoaded('masukLokasi', fn () => $this->masukLokasi?->nama),
                'status' => $this->masuk_status,
                'menit_terlambat' => (int) $this->masuk_menit_terlambat,
                'validasi' => $this->masuk_validasi,
                'alasan_luar_radius' => $this->masuk_alasan_luar_radius,
                'foto' => $this->masuk_foto_path !== null,
                'foto_url' => $this->masuk_foto_path !== null ? "/presensi/{$this->id}/foto/masuk" : null,
            ],

            'pulang' => [
                'waktu' => $this->pulang_waktu?->toIso8601String(),
                'jam' => $this->pulang_waktu?->format('H:i'),
                'lat' => $this->pulang_lat,
                'lng' => $this->pulang_lng,
                'akurasi_m' => $this->pulang_akurasi_m,
                'jarak_m' => $this->pulang_jarak_m,
                'lokasi_id' => $this->pulang_lokasi_id,
                'lokasi' => $this->whenLoaded('pulangLokasi', fn () => $this->pulangLokasi?->nama),
                'status' => $this->pulang_status,
                'menit_cepat' => (int) $this->pulang_menit_cepat,
                'validasi' => $this->pulang_validasi,
                'alasan_luar_radius' => $this->pulang_alasan_luar_radius,
                'foto' => $this->pulang_foto_path !== null,
                'foto_url' => $this->pulang_foto_path !== null ? "/presensi/{$this->id}/foto/pulang" : null,
            ],

            'belum_pulang' => $this->belumPulang(),
            'dihitung_hadir' => $this->dihitungHadir(),
            'dikoreksi_admin' => (bool) $this->dikoreksi_admin,
            'catatan_penyetuju' => $this->catatan_penyetuju,
            'diputuskan_pada' => $this->diputuskan_pada?->toIso8601String(),
        ];
    }
}
