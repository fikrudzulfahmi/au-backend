<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Laporan;

use App\Exceptions\AturanBisnisException;
use App\Models\Kelas;
use App\Models\Semester;
use App\Services\Laporan\DokumenResmiService;
use App\Services\Laporan\LaporanJurnalService;
use App\Support\PeriodeLaporan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 5.14 B — laporan jurnal & presensi siswa (FR-LAP-06..09).
 *
 * Pembatasan per peran (FR-LAP-12):
 *  - admin/kepsek/wakasek melihat semua;
 *  - guru hanya jurnalnya sendiri (L(S));
 *  - rekap presensi siswa (FR-LAP-06) bagi guru terbatas pada kelas yang ia ampu
 *    sebagai wali kelas (KP-5.5).
 *
 * Catatan penting dari Fase 4: kepala sekolah & wakasek TIDAK punya akses ke
 * endpoint `/jurnal`. Laporan ini karena itu memakai endpoint tersendiri, bukan
 * melonggarkan middleware `/jurnal`.
 */
final class LaporanJurnalController extends LaporanController
{
    public function __construct(
        DokumenResmiService $dokumen,
        private readonly LaporanJurnalService $laporan,
    ) {
        parent::__construct($dokumen);
    }

    /** FR-LAP-06 — rekap presensi siswa per kelas × periode (boleh difilter mapel). */
    public function rekapSiswa(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
            'mapel_id' => ['nullable', 'integer', 'exists:mapel,id'],
        ]);

        $semester = $this->semesterTerpilih($request);
        $kelasId = $this->kelasBolehDirekap($request, $semester, isset($data['kelas_id']) ? (int) $data['kelas_id'] : null);

        $hasil = $this->laporan->rekapSiswa(
            $semester,
            $kelasId,
            Kelas::query()->find($kelasId)?->nama,
            PeriodeLaporan::buat($data),
            isset($data['mapel_id']) ? (int) $data['mapel_id'] : null,
        );

        return $this->sajikan($hasil, $request);
    }

    /** FR-LAP-07 — daftar jurnal dengan filter periode/guru/kelas/mapel. */
    public function daftar(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'guru_id' => ['nullable', 'integer', 'exists:pegawai,id'],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
            'mapel_id' => ['nullable', 'integer', 'exists:mapel,id'],
        ]);

        // Guru hanya jurnalnya sendiri; permintaan atas guru lain ditolak 403.
        $batas = $this->batasPegawai($request);

        if ($batas !== null) {
            if (! empty($data['guru_id']) && (int) $data['guru_id'] !== $batas) {
                throw new AturanBisnisException('Anda hanya dapat melihat jurnal Anda sendiri.', 'AKSES_DITOLAK', 403);
            }

            $data['guru_id'] = $batas;
        }

        $hasil = $this->laporan->daftarJurnal(
            $this->semesterTerpilih($request),
            PeriodeLaporan::buat($data),
            $data,
        );

        return $this->sajikan($hasil, $request, 'landscape');
    }

    /** FR-LAP-08 / BR-26 — rekap kepatuhan jurnal per guru. */
    public function kepatuhan(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'guru_id' => ['nullable', 'integer', 'exists:pegawai,id'],
        ]);

        $batas = $this->batasPegawai($request);

        if ($batas !== null) {
            if (! empty($data['guru_id']) && (int) $data['guru_id'] !== $batas) {
                throw new AturanBisnisException('Anda hanya dapat melihat kepatuhan jurnal Anda sendiri.', 'AKSES_DITOLAK', 403);
            }

            $data['guru_id'] = $batas;
        }

        $hasil = $this->laporan->kepatuhanJurnal(
            $this->semesterTerpilih($request),
            PeriodeLaporan::buat($data),
            $data,
        );

        return $this->sajikan($hasil, $request, 'landscape');
    }

    /** FR-LAP-09 — rekap jam mengajar terlaksana per guru. */
    public function jamMengajar(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'guru_id' => ['nullable', 'integer', 'exists:pegawai,id'],
        ]);

        $batas = $this->batasPegawai($request);

        if ($batas !== null) {
            if (! empty($data['guru_id']) && (int) $data['guru_id'] !== $batas) {
                throw new AturanBisnisException('Anda hanya dapat melihat jam mengajar Anda sendiri.', 'AKSES_DITOLAK', 403);
            }

            $data['guru_id'] = $batas;
        }

        $hasil = $this->laporan->jamMengajar(
            $this->semesterTerpilih($request),
            PeriodeLaporan::buat($data),
            $data,
        );

        return $this->sajikan($hasil, $request);
    }

    /**
     * KP-5.5 — wali kelas hanya boleh membuka rekap kelasnya; guru yang bukan
     * wali kelas tidak dapat membuka rekap siswa sama sekali. Peran pemantau
     * memilih kelas secara bebas.
     */
    private function kelasBolehDirekap(Request $request, Semester $semester, ?int $kelasId): int
    {
        if ($this->penggunaMemantau($request)) {
            if ($kelasId === null) {
                throw AturanBisnisException::tolak('Pilih kelas terlebih dahulu.', 'kelas_id');
            }

            return $kelasId;
        }

        $pegawai = $request->user()?->pegawai;

        if ($pegawai === null) {
            throw new AturanBisnisException('Rekap presensi siswa hanya untuk wali kelas.', 'AKSES_DITOLAK', 403);
        }

        $wali = Kelas::query()
            ->where('tahun_pelajaran_id', $semester->tahun_pelajaran_id)
            ->where('wali_kelas_id', $pegawai->getKey())
            ->get();

        if ($kelasId !== null) {
            if (! $wali->contains(fn (Kelas $k): bool => (int) $k->getKey() === $kelasId)) {
                throw new AturanBisnisException(
                    'Rekap presensi siswa hanya untuk kelas yang Anda ampu sebagai wali kelas.',
                    'AKSES_DITOLAK',
                    403,
                );
            }

            return $kelasId;
        }

        $satu = $wali->first();

        if ($satu === null) {
            throw new AturanBisnisException(
                'Anda bukan wali kelas pada tahun pelajaran ini, sehingga tidak ada rekap kelas yang dapat dibuka.',
                'AKSES_DITOLAK',
                403,
            );
        }

        return (int) $satu->getKey();
    }
}
