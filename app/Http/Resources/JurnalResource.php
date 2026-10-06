<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Jurnal;
use App\Models\PresensiSiswa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 7.5 — satu jurnal pada daftar/riwayat (FR-JRN-09).
 *
 * Ringkasan H/S/I/A memakai count yang sudah disiapkan pemanggil (withCount) agar
 * daftar tidak memicu query per baris.
 *
 * @mixin Jurnal
 */
class JurnalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tanggal' => $this->tanggal?->toDateString(),
            'label_jam' => $this->labelJamKe(),
            'jam_ke_mulai' => (int) $this->jam_ke_mulai,
            'jam_ke_selesai' => (int) $this->jam_ke_selesai,
            'kelas_id' => (int) $this->kelas_id,
            'kelas' => $this->whenLoaded('kelas', fn () => $this->kelas?->nama),
            'mapel_id' => $this->whenLoaded('plottingMapel', fn () => $this->plottingMapel?->mapel_id),
            'mapel' => $this->whenLoaded('plottingMapel', fn () => $this->plottingMapel?->mapel?->nama),
            'kode_mapel' => $this->whenLoaded('plottingMapel', fn () => $this->plottingMapel?->mapel?->kode),
            'materi' => $this->materi,
            'kegiatan' => $this->kegiatan,
            'catatan' => $this->catatan,
            // `foto` dibatasi relasinya (yang belum dihapus karena retensi), sehingga
            // withCount pun menghitung hanya foto yang berkasnya masih ada.
            'jumlah_foto' => (int) ($this->foto_count
                ?? ($this->relationLoaded('foto') ? $this->foto->count() : 0)),
            'ringkasan' => [
                'H' => (int) ($this->jumlah_hadir ?? 0),
                'S' => (int) ($this->jumlah_sakit ?? 0),
                'I' => (int) ($this->jumlah_izin ?? 0),
                'A' => (int) ($this->jumlah_alpa ?? 0),
            ],
            'diubah_pada' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Dipakai halaman isi jurnal: presensi siswa per baris. */
    public static function presensiLengkap(Jurnal $jurnal): array
    {
        return $jurnal->presensiSiswa
            ->sortBy(fn (PresensiSiswa $p): string => (string) $p->siswa?->nama)
            ->values()
            ->map(fn (PresensiSiswa $p): array => [
                'siswa_id' => (int) $p->siswa_id,
                'nis' => $p->siswa?->nis,
                'nama' => $p->siswa?->nama,
                'jenis_kelamin' => $p->siswa?->jenis_kelamin,
                'status' => $p->status,
                'label_status' => $p->labelStatus(),
                'keterangan' => $p->keterangan,
            ])
            ->all();
    }
}
