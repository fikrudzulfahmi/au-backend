<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Semester;
use App\Models\TahunPelajaran;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FR-TP-03/04/05 — pengaturan tahun pelajaran & semester.
 * BR-01 (mengikat): hanya SATU tahun pelajaran dan SATU semester berstatus
 * aktif pada satu waktu. Aturan ini ditegakkan di server, dalam satu transaksi.
 */
class TahunPelajaranService
{
    /**
     * FR-TP-01/02 — membuat tahun pelajaran beserta dua semesternya
     * (Ganjil & Genap) dengan tanggal mulai/selesai yang dapat diubah.
     */
    public function buat(array $data): TahunPelajaran
    {
        return DB::transaction(function () use ($data): TahunPelajaran {
            /** @var TahunPelajaran $tahun */
            $tahun = TahunPelajaran::create([
                'nama' => $data['nama'],
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'],
                'status' => TahunPelajaran::STATUS_DRAFT,
            ]);

            $mulai = strtotime((string) $data['tanggal_mulai']);
            $selesai = strtotime((string) $data['tanggal_selesai']);
            $tengah = (int) (($mulai + $selesai) / 2);

            $tahun->semester()->createMany([
                [
                    'jenis' => Semester::JENIS_GANJIL,
                    'tanggal_mulai' => date('Y-m-d', $mulai),
                    'tanggal_selesai' => date('Y-m-d', $tengah),
                    'is_active' => false,
                ],
                [
                    'jenis' => Semester::JENIS_GENAP,
                    'tanggal_mulai' => date('Y-m-d', $tengah + 86400),
                    'tanggal_selesai' => date('Y-m-d', $selesai),
                    'is_active' => false,
                ],
            ]);

            return $tahun->load('semester');
        });
    }

    /**
     * FR-TP-04 / BR-01 — mengaktifkan tahun pelajaran dan satu semester terpilih.
     * Seluruh tahun pelajaran dan semester lain menjadi nonaktif.
     * BR-27: menolak mengaktifkan tahun pelajaran yang sudah `selesai`.
     */
    public function aktifkan(TahunPelajaran $tahun, string $jenisSemester): TahunPelajaran
    {
        if ($tahun->isSelesai()) {
            throw ValidationException::withMessages([
                'status' => ['Tahun pelajaran yang sudah ditandai selesai tidak dapat diaktifkan kembali.'],
            ]);
        }

        if (! in_array($jenisSemester, TahunPelajaran::JENIS_SEMESTER, true)) {
            throw ValidationException::withMessages([
                'jenis_semester' => ['Semester harus ganjil atau genap.'],
            ]);
        }

        return DB::transaction(function () use ($tahun, $jenisSemester): TahunPelajaran {
            // BR-01: matikan seluruh tahun pelajaran lain lebih dahulu.
            TahunPelajaran::query()
                ->whereKeyNot($tahun->getKey())
                ->where('status', TahunPelajaran::STATUS_AKTIF)
                ->update(['status' => TahunPelajaran::STATUS_DRAFT]);

            $tahun->forceFill(['status' => TahunPelajaran::STATUS_AKTIF])->save();

            // BR-01: hanya satu semester aktif di seluruh instalasi.
            Semester::query()
                ->where('tahun_pelajaran_id', '!=', $tahun->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            Semester::query()
                ->where('tahun_pelajaran_id', $tahun->getKey())
                ->update(['is_active' => false]);

            Semester::query()
                ->where('tahun_pelajaran_id', $tahun->getKey())
                ->where('jenis', $jenisSemester)
                ->update(['is_active' => true]);

            return $tahun->refresh()->load('semester');
        });
    }

    /**
     * FR-TP-05 — menandai tahun pelajaran selesai.
     * Memicu aturan retensi foto (BR-30); pembersihan berkas dijalankan
     * tugas terjadwal `presensi:bersihkan-foto` pada Fase 7.
     */
    public function tandaiSelesai(TahunPelajaran $tahun): TahunPelajaran
    {
        return DB::transaction(function () use ($tahun): TahunPelajaran {
            $tahun->forceFill(['status' => TahunPelajaran::STATUS_SELESAI])->save();

            $tahun->semester()->update(['is_active' => false]);

            return $tahun->refresh()->load('semester');
        });
    }

    /** FR-TP-02 — mengubah rentang tanggal sebuah semester. */
    public function ubahSemester(Semester $semester, array $data): Semester
    {
        $semester->update([
            'tanggal_mulai' => $data['tanggal_mulai'] ?? $semester->tanggal_mulai,
            'tanggal_selesai' => $data['tanggal_selesai'] ?? $semester->tanggal_selesai,
        ]);

        return $semester->refresh();
    }

    /**
     * FR-TP-06 — menyalin data semester sebelumnya.
     * Fase 1 baru menyalin semester (tanggal tidak diubah) dan merangkum apa yang
     * belum dapat disalin karena tabelnya milik fase berikutnya
     * (plotting mapel, pola jam, jadwal). Bagian yang belum ada TIDAK dibuat
     * agar tidak ada fitur di luar dokumen.
     *
     * @return array{dari: int, ke: int, disalin: array<string, int>, ditunda: array<int, string>}
     */
    public function ringkasanSalin(TahunPelajaran $asal, TahunPelajaran $tujuan): array
    {
        return [
            'dari' => $asal->getKey(),
            'ke' => $tujuan->getKey(),
            'disalin' => [
                'semester' => $tujuan->semester()->count(),
                'kelas' => $tujuan->kelas()->count(),
            ],
            'ditunda' => [
                'Plotting mapel disalin pada Fase 2 (FR-PLM-05).',
                'Pola jam & jadwal disalin pada Fase 2 (FR-JAM-04, FR-TP-06).',
            ],
        ];
    }
}
