<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\AktifkanTahunPelajaranRequest;
use App\Http\Requests\Master\TahunPelajaranRequest;
use App\Http\Resources\TahunPelajaranResource;
use App\Models\TahunPelajaran;
use App\Services\AuditLogService;
use App\Services\RetensiFotoService;
use App\Services\TahunPelajaranService;
use App\Support\PenjagaHapus;
use App\Support\ResponsDaftar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-TP-01..07 — data tahun pelajaran.
 * BR-01: hanya satu tahun pelajaran dan satu semester aktif (lihat TahunPelajaranService).
 */
class TahunPelajaranController extends Controller
{
    public function __construct(
        private readonly TahunPelajaranService $service,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TahunPelajaran::query()
            ->with('semester')
            ->withCount('kelas')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('cari'), fn ($q) => $q->where('nama', 'like', '%'.$request->string('cari').'%'))
            ->orderByDesc('tanggal_mulai');

        return ResponsDaftar::buat($query->paginate($this->perHalaman($request)), TahunPelajaranResource::class);
    }

    public function store(TahunPelajaranRequest $request): JsonResponse
    {
        $tahun = $this->service->buat($request->validated());

        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $tahun, null, $tahun->only(['nama', 'status']));

        return response()->json(['data' => new TahunPelajaranResource($tahun)], 201);
    }

    public function show(TahunPelajaran $tahunPelajaran): JsonResponse
    {
        return response()->json([
            'data' => new TahunPelajaranResource($tahunPelajaran->load('semester')->loadCount('kelas')),
        ]);
    }

    public function update(TahunPelajaranRequest $request, TahunPelajaran $tahunPelajaran): JsonResponse
    {
        $lama = $tahunPelajaran->only(['nama', 'tanggal_mulai', 'tanggal_selesai', 'status']);

        $tahunPelajaran->update($request->validated());

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $tahunPelajaran, $lama, $request->validated());

        return response()->json([
            'data' => new TahunPelajaranResource($tahunPelajaran->refresh()->load('semester')),
        ]);
    }

    /**
     * BR-31 — penghapusan memerlukan konfirmasi di UI dan dicatat di audit_log.
     * Tahun pelajaran yang sudah memiliki kelas tidak dapat dihapus.
     */
    public function destroy(Request $request, TahunPelajaran $tahunPelajaran): JsonResponse
    {
        if ($tahunPelajaran->kelas()->exists()) {
            return response()->json([
                'message' => 'Tahun pelajaran tidak dapat dihapus karena sudah memiliki kelas.',
                'code' => 'KONFLIK_DATA',
            ], 409);
        }

        if ($pesan = PenjagaHapus::periksa($tahunPelajaran->getKey(), [])) {
            return response()->json(['message' => $pesan, 'code' => 'KONFLIK_DATA'], 409);
        }

        $data = $tahunPelajaran->only(['nama', 'status']);
        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $tahunPelajaran, $data, null);

        $tahunPelajaran->delete();

        return response()->json(['message' => 'Tahun pelajaran berhasil dihapus.']);
    }

    /** FR-TP-04 / BR-01 — mengaktifkan tahun pelajaran dan satu semester. */
    public function aktifkan(AktifkanTahunPelajaranRequest $request, TahunPelajaran $tahunPelajaran): JsonResponse
    {
        $tahun = $this->service->aktifkan($tahunPelajaran, $request->validated('jenis_semester'));

        $this->audit->catat(AuditLogService::AKSI_AKTIFKAN_TAHUN, $request->user(), $tahun, null, [
            'nama' => $tahun->nama,
            'semester' => $request->validated('jenis_semester'),
        ]);

        return response()->json([
            'message' => "Tahun pelajaran {$tahun->nama} diaktifkan.",
            'data' => new TahunPelajaranResource($tahun),
        ]);
    }

    /** FR-TP-05 — tandai selesai (memicu aturan retensi foto BR-30). */
    public function tandaiSelesai(Request $request, TahunPelajaran $tahunPelajaran): JsonResponse
    {
        $tahun = $this->service->tandaiSelesai($tahunPelajaran);

        $this->audit->catat(AuditLogService::AKSI_SELESAI_TAHUN, $request->user(), $tahun, null, [
            'nama' => $tahun->nama,
        ]);

        return response()->json([
            'message' => "Tahun pelajaran {$tahun->nama} ditandai selesai. Data transaksinya bersifat read-only.",
            'data' => new TahunPelajaranResource($tahun),
        ]);
    }

    /**
     * BR-30 — pembersihan manual berkas foto presensi & lampiran setelah konfirmasi admin.
     *
     * Hanya tahun pelajaran berstatus `selesai` yang boleh dibersihkan; tahun yang
     * masih aktif ditolak 422 supaya perlindungannya tidak bergantung pada UI.
     */
    public function bersihkanFoto(
        Request $request,
        TahunPelajaran $tahunPelajaran,
        RetensiFotoService $retensi,
    ): JsonResponse {
        $request->validate([
            'konfirmasi' => ['required', 'accepted'],
        ]);

        if (! $tahunPelajaran->isSelesai()) {
            throw new AturanBisnisException(
                "Tahun pelajaran {$tahunPelajaran->nama} berstatus \"{$tahunPelajaran->status}\". Foto hanya boleh dibersihkan setelah tahun pelajaran ditandai selesai.",
                'TAHUN_BELUM_SELESAI',
            );
        }

        $ringkasan = $retensi->bersihkan(new Collection([$tahunPelajaran]));

        $this->audit->catat(AuditLogService::AKSI_BERSIHKAN_FOTO, $request->user(), $tahunPelajaran, null, $ringkasan);

        return response()->json([
            'message' => "Pembersihan berkas foto tahun pelajaran {$tahunPelajaran->nama} selesai. Data teks presensi tetap tersimpan.",
            'data' => $ringkasan,
        ]);
    }

    /**
     * FR-TP-06 — ringkasan "salin dari semester sebelumnya".
     * Data yang belum dapat disalin (plotting mapel, pola jam, jadwal) dilaporkan
     * sebagai tertunda, bukan dibuat-buat, karena tabelnya milik Fase 2.
     */
    public function salin(Request $request, TahunPelajaran $tahunPelajaran): JsonResponse
    {
        $valid = $request->validate([
            'tahun_pelajaran_asal_id' => ['required', 'integer', 'exists:tahun_pelajaran,id', 'different:'.$tahunPelajaran->getKey()],
        ], [
            'tahun_pelajaran_asal_id.different' => 'Tahun pelajaran asal harus berbeda dari tahun pelajaran tujuan.',
        ]);

        $asal = TahunPelajaran::findOrFail($valid['tahun_pelajaran_asal_id']);

        $ringkasan = $this->service->ringkasanSalin($asal, $tahunPelajaran->load('semester'));

        $this->audit->catat('salin_tahun_pelajaran', $request->user(), $tahunPelajaran, null, $ringkasan);

        return response()->json([
            'message' => 'Ringkasan salin data disiapkan.',
            'data' => $ringkasan,
        ]);
    }
}
