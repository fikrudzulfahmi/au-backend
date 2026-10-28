<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Laporan;

use App\Exceptions\AturanBisnisException;
use App\Models\Jurnal;
use App\Models\JurnalFoto;
use App\Models\Kelas;
use App\Models\Semester;
use App\Services\Laporan\DokumenResmiService;
use App\Services\Laporan\LaporanJurnalService;
use App\Support\PeriodeLaporan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * Dasbor harian pengisian jurnal — kelas mana yang sesinya sudah diisi,
     * mana yang masih kosong, dan mana yang berhalangan pada satu tanggal.
     *
     * Berbeda dari `kepatuhan` (per guru × periode), permintaan ini memotong
     * satu tanggal lalu mengelompokkan per kelas; defaultnya hari ini.
     *
     * Otorisasi: pemantau saja (admin/kepsek/wakasek). Guru sengaja tidak
     * dimasukkan karena laporan ini memperlihatkan jurnal guru lain — di luar
     * pembatasan `L(S)` FR-LAP-12.
     */
    public function harian(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'kelas_id' => ['nullable', 'integer', 'exists:kelas,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semester,id'],
        ], [
            'tanggal.date_format' => 'Tanggal harus berformat YYYY-MM-DD.',
        ]);

        $hasil = $this->laporan->harianKelas(
            $this->semesterTerpilih($request),
            $data['tanggal'] ?? now()->toDateString(),
            isset($data['kelas_id']) ? (int) $data['kelas_id'] : null,
        );

        return $this->sajikan($hasil, $request, 'landscape');
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

    /**
     * Detail read-only satu jurnal untuk pembaca laporan (FR-LAP-07).
     *
     * Admin/kepala sekolah/wakasek boleh membuka jurnal siapa pun; guru hanya
     * jurnal miliknya atau yang ia isi sebagai guru piket (L(S) FR-LAP-12).
     * Berbeda dari `/jurnal/{id}`, endpoint ini tidak memberi hak menyunting.
     */
    public function detail(Request $request, Jurnal $jurnal): JsonResponse
    {
        $this->pastikanBolehBaca($request, $jurnal);

        return response()->json(['data' => $this->laporan->detailJurnal($jurnal)]);
    }

    /** Foto kegiatan jurnal; tetap melewati pemeriksaan hak akses laporan. */
    public function foto(Request $request, Jurnal $jurnal, JurnalFoto $foto): JsonResponse|BinaryFileResponse
    {
        // Foto harus benar-benar milik jurnal pada URL, bukan sekadar id yang sah.
        if ((int) $foto->jurnal_id !== (int) $jurnal->id) {
            throw new AturanBisnisException('Foto tidak ditemukan pada jurnal ini.', 'FOTO_TIDAK_ADA', 404);
        }

        $this->pastikanBolehBaca($request, $jurnal);

        // BR-30 — setelah retensi kolom path dikosongkan dan berkasnya dibuang.
        if ($foto->foto_path === null || $foto->foto_path === '') {
            return response()->json([
                'message' => 'Berkas foto sudah tidak tersedia karena masa simpan berakhir.',
            ], 404);
        }

        $berkas = Storage::disk('local')->path($foto->foto_path);

        if (! is_file($berkas)) {
            return response()->json([
                'message' => 'Berkas foto sudah tidak tersedia karena masa simpan berakhir.',
            ], 404);
        }

        return response()->file($berkas);
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
     * Kepala sekolah & wakasek boleh membaca SEMUA jurnal; guru hanya miliknya
     * sendiri atau jurnal yang ia isi sebagai guru piket hari itu (L(S) FR-LAP-12).
     */
    private function pastikanBolehBaca(Request $request, Jurnal $jurnal): void
    {
        if ($this->penggunaMemantau($request)) {
            return;
        }

        $pegawai = $request->user()?->pegawai;
        $boleh = $pegawai !== null
            && ((int) $jurnal->pegawai_id === (int) $pegawai->getKey()
                || (int) $jurnal->diisi_oleh_pegawai_id === (int) $pegawai->getKey());

        if (! $boleh) {
            throw new AturanBisnisException('Anda tidak berhak atas jurnal ini.', 'AKSES_DITOLAK', 403);
        }
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
