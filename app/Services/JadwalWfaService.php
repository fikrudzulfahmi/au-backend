<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\JadwalWfa;
use App\Models\JamKerja;
use App\Models\Pegawai;
use App\Models\User;

/**
 * FR-WFA — jadwal Work From Anywhere per pegawai per hari.
 *
 * Admin mengelola jadwal WFA; service lain (`PresensiService`,
 * `MonitoringPresensiService`, `LaporanPresensiService`) membaca
 * untuk menentukan kewajiban presensi dan hitung terlambat.
 */
final class JadwalWfaService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** Ambil jadwal WFA satu pegawai pada satu hari. */
    public function untuk(int $pegawaiId, int $hari): ?JadwalWfa
    {
        return JadwalWfa::untuk($pegawaiId, $hari);
    }

    /** Semua jadwal WFA satu pegawai (7 hari). */
    public function sepekan(int $pegawaiId): array
    {
        $jadwal = JadwalWfa::query()
            ->where('pegawai_id', $pegawaiId)
            ->get()
            ->keyBy('hari');

        return collect(JamKerja::DAFTAR_HARI)->map(function (string $nama, int $hari) use ($jadwal): array {
            $baris = $jadwal->get($hari);

            return [
                'hari' => $hari,
                'nama_hari' => $nama,
                'ada_wfa' => $baris !== null,
                'jenis' => $baris?->jenis,
                'label_jenis' => $baris !== null ? (JadwalWfa::DAFTAR_JENIS[$baris->jenis] ?? $baris->jenis) : null,
                'jam_masuk' => $baris?->jam_masuk,
                'keterangan' => $baris?->keterangan,
            ];
        })->values()->all();
    }

    /** Daftar seluruh pegawai yang punya jadwal WFA (ringkasan). */
    public function daftar(?string $jenisPegawai = null, ?string $nama = null): array
    {
        $pegawai = Pegawai::query()
            ->where('is_active', true)
            ->when($jenisPegawai, fn ($q, $j) => $q->where('jenis_pegawai', $j))
            ->when($nama, fn ($q, $n) => $q->where('nama', 'like', '%'.trim($n).'%'))
            ->orderBy('nama')
            ->get();

        $semuaWfa = JadwalWfa::query()
            ->whereIn('pegawai_id', $pegawai->pluck('id'))
            ->get()
            ->groupBy('pegawai_id');

        return $pegawai->map(function (Pegawai $orang) use ($semuaWfa): array {
            $wfa = $semuaWfa->get($orang->getKey()) ?? collect();

            return [
                'pegawai_id' => $orang->getKey(),
                'nip' => $orang->nip,
                'nama' => $orang->nama,
                'jenis_pegawai' => $orang->jenis_pegawai,
                'jabatan' => $orang->jabatan,
                'jumlah_hari_wfa' => $wfa->count(),
                'hari' => $wfa->map(fn (JadwalWfa $w): array => [
                    'hari' => $w->hari,
                    'nama_hari' => $w->namaHari(),
                    'jenis' => $w->jenis,
                    'label_jenis' => JadwalWfa::DAFTAR_JENIS[$w->jenis] ?? $w->jenis,
                    'jam_masuk' => $w->jam_masuk,
                ])->sortBy('hari')->values()->all(),
            ];
        })->values()->all();
    }

    /** Simpan/ubah jadwal WFA satu pegawai satu hari. */
    public function simpan(Pegawai $pegawai, int $hari, array $data, ?User $oleh = null): JadwalWfa
    {
        if (! array_key_exists($hari, JamKerja::DAFTAR_HARI)) {
            throw AturanBisnisException::tolak('Hari tidak valid.', 'hari');
        }

        $jenis = $data['jenis'] ?? JadwalWfa::JENIS_SEBAGIAN;

        if (! array_key_exists($jenis, JadwalWfa::DAFTAR_JENIS)) {
            throw AturanBisnisException::tolak('Jenis WFA tidak valid.', 'jenis');
        }

        if ($jenis === JadwalWfa::JENIS_SEBAGIAN && empty($data['jam_masuk'])) {
            throw AturanBisnisException::tolak('Jam masuk wajib diisi untuk WFA sebagian.', 'jam_masuk');
        }

        $jadwal = JadwalWfa::updateOrCreate(
            ['pegawai_id' => $pegawai->getKey(), 'hari' => $hari],
            [
                'jenis' => $jenis,
                'jam_masuk' => $jenis === JadwalWfa::JENIS_SEBAGIAN ? $data['jam_masuk'] : null,
                'keterangan' => $data['keterangan'] ?? null,
            ],
        );

        $this->audit->catat(AuditLogService::AKSI_BUAT, $oleh, $jadwal, null, [
            'pegawai_id' => $pegawai->getKey(), 'hari' => $hari, 'jenis' => $jenis,
        ]);

        return $jadwal;
    }

    /** Simpan sepekan sekaligus untuk satu pegawai. */
    public function simpanMassal(Pegawai $pegawai, array $perHari, ?User $oleh = null): array
    {
        $tersimpan = [];

        foreach (JamKerja::DAFTAR_HARI as $hari => $nama) {
            $data = $perHari[$hari] ?? $perHari[(string) $hari] ?? null;

            if ($data === null) {
                // Hari tidak dikirim = hapus WFA untuk hari itu.
                JadwalWfa::query()
                    ->where('pegawai_id', $pegawai->getKey())
                    ->where('hari', $hari)
                    ->delete();

                continue;
            }

            // Hari dikirim tapi tidak aktif WFA = hapus juga.
            if (empty($data['ada_wfa'])) {
                JadwalWfa::query()
                    ->where('pegawai_id', $pegawai->getKey())
                    ->where('hari', $hari)
                    ->delete();

                continue;
            }

            $tersimpan[$hari] = $this->simpan($pegawai, $hari, $data, $oleh);
        }

        return $tersimpan;
    }

    /** Hapus jadwal WFA satu pegawai satu hari. */
    public function hapus(Pegawai $pegawai, int $hari, ?User $oleh = null): void
    {
        $jadwal = JadwalWfa::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->where('hari', $hari)
            ->first();

        if ($jadwal === null) {
            return;
        }

        $this->audit->catat(AuditLogService::AKSI_HAPUS, $oleh, $jadwal);
        $jadwal->delete();
    }
}
