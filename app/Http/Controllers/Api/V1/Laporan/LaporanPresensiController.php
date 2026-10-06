<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Laporan;

use App\Exceptions\AturanBisnisException;
use App\Models\Pegawai;
use App\Services\Laporan\DokumenResmiService;
use App\Services\Laporan\LaporanPresensiService;
use App\Support\PeriodeLaporan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 5.14 A — laporan presensi pegawai (FR-LAP-01..05).
 *
 * Semua laporan di sini dapat diekspor dengan `?format=pdf` atau `?format=excel`
 * (KP-5.1); tanpa `format` hasilnya JSON untuk ditampilkan di web.
 */
final class LaporanPresensiController extends LaporanController
{
    public function __construct(
        DokumenResmiService $dokumen,
        private readonly LaporanPresensiService $laporan,
    ) {
        parent::__construct($dokumen);
    }

    /** FR-LAP-01 — rekap presensi pegawai (hadir, terlambat, alpa, dst.). */
    public function rekapPegawai(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'jenis_pegawai' => ['nullable', 'in:guru,struktural'],
            'pegawai_id' => ['nullable', 'integer', 'exists:pegawai,id'],
        ]);

        $data['pegawai_id'] = $this->batasiPegawai($request, isset($data['pegawai_id']) ? (int) $data['pegawai_id'] : null);

        $hasil = $this->laporan->rekapPegawai(
            $this->semesterTerpilih($request),
            PeriodeLaporan::buat($data),
            $data,
        );

        return $this->sajikan($hasil, $request, 'landscape');
    }

    /** FR-LAP-02 — detail presensi harian satu pegawai. */
    public function detailPegawai(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'pegawai_id' => ['required', 'integer', 'exists:pegawai,id'],
        ]);

        $pegawaiId = (int) $data['pegawai_id'];
        $this->pastikanBolehPegawai($request, $pegawaiId);

        $hasil = $this->laporan->detailPegawai(
            $this->semesterTerpilih($request),
            Pegawai::query()->findOrFail($pegawaiId),
            PeriodeLaporan::buat($data),
        );

        return $this->sajikan($hasil, $request, 'landscape');
    }

    /** FR-LAP-03 — presensi harian seluruh pegawai untuk satu tanggal. */
    public function harian(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'jenis_pegawai' => ['nullable', 'in:guru,struktural'],
        ]);

        $hasil = $this->laporan->harian(
            (string) $data['tanggal'],
            $data['jenis_pegawai'] ?? null,
            $this->batasPegawai($request),
        );

        return $this->sajikan($hasil, $request, 'landscape');
    }

    /** FR-LAP-04 — rekap izin/sakit/dinas/cuti per periode. */
    public function rekapIzin(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'jenis' => ['nullable', 'in:izin,sakit,dinas,cuti'],
            'pegawai_id' => ['nullable', 'integer', 'exists:pegawai,id'],
        ]);

        $data['pegawai_id'] = $this->batasiPegawai($request, isset($data['pegawai_id']) ? (int) $data['pegawai_id'] : null);

        $hasil = $this->laporan->rekapIzin(PeriodeLaporan::buat($data), $data);

        return $this->sajikan($hasil, $request);
    }

    /** FR-LAP-05 — rekap presensi luar radius menurut status keputusan. */
    public function rekapLuarRadius(Request $request): JsonResponse|Response
    {
        $data = $this->validasiPeriode($request, [
            'status' => ['nullable', 'in:menunggu,disetujui,ditolak,dibatalkan'],
            'pegawai_id' => ['nullable', 'integer', 'exists:pegawai,id'],
        ]);

        $data['pegawai_id'] = $this->batasiPegawai($request, isset($data['pegawai_id']) ? (int) $data['pegawai_id'] : null);

        $hasil = $this->laporan->rekapLuarRadius(PeriodeLaporan::buat($data), $data);

        return $this->sajikan($hasil, $request);
    }

    /**
     * Peran L(S) hanya boleh membuka rekap dirinya sendiri; permintaan atas
     * pegawai lain ditolak 403, bukan diam-diam diabaikan (FR-LAP-12).
     *
     * @return int|null pegawai_id yang benar-benar dipakai
     */
    private function batasiPegawai(Request $request, ?int $diminta): ?int
    {
        $batas = $this->batasPegawai($request);

        if ($batas === null) {
            return $diminta;
        }

        if ($diminta !== null && $diminta !== $batas) {
            throw new AturanBisnisException(
                'Anda hanya dapat melihat laporan data Anda sendiri.',
                'AKSES_DITOLAK',
                403,
            );
        }

        return $batas;
    }
}
