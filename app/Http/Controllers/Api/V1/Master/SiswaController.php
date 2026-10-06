<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ImportMasterRequest;
use App\Http\Requests\Master\SiswaRequest;
use App\Http\Resources\SiswaResource;
use App\Models\Siswa;
use App\Services\AuditLogService;
use App\Services\EksporMasterService;
use App\Services\ImportMasterService;
use App\Support\PenjagaHapus;
use App\Support\ResponsDaftar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-SIS-01..06 — data siswa, import, dan export.
 *
 * Filter kelas/jurusan/tingkat bergantung pada `plotting_kelas` (Fase 2).
 * Pemeriksaan memakai keberadaan tabel sehingga filter itu langsung berfungsi
 * begitu tabelnya dibuat, tanpa mengubah controller ini.
 */
class SiswaController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ImportMasterService $import,
        private readonly EksporMasterService $ekspor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ResponsDaftar::buat($this->query($request)->paginate($this->perHalaman($request)), SiswaResource::class);
    }

    public function store(SiswaRequest $request): JsonResponse
    {
        $siswa = Siswa::create($request->validated());
        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $siswa, null, $siswa->toArray());

        return response()->json(['data' => new SiswaResource($siswa)], 201);
    }

    public function show(Siswa $siswa): JsonResponse
    {
        return response()->json(['data' => new SiswaResource($siswa)]);
    }

    public function update(SiswaRequest $request, Siswa $siswa): JsonResponse
    {
        $lama = $siswa->toArray();
        $siswa->update($request->validated());
        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $siswa, $lama, $request->validated());

        return response()->json(['data' => new SiswaResource($siswa)]);
    }

    /** FR-SIS-06 — siswa yang sudah punya presensi tidak boleh dihapus ( hanya diubah status). */
    public function destroy(Request $request, Siswa $siswa): JsonResponse
    {
        if ($pesan = PenjagaHapus::periksa($siswa->getKey(), [
            ['presensi_siswa', 'siswa_id', 'presensi siswa pada jurnal'],
        ])) {
            return response()->json([
                'message' => $pesan.' Ubah status siswa menjadi lulus, pindah, atau keluar.',
                'code' => 'KONFLIK_DATA',
            ], 409);
        }

        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $siswa, $siswa->toArray(), null);
        $siswa->delete();

        return response()->json(['message' => 'Siswa berhasil dihapus.']);
    }

    /** FR-SIS-03 / KP-1.3 — baris gagal dilaporkan tanpa menggagalkan baris valid. */
    public function import(ImportMasterRequest $request): JsonResponse
    {
        try {
            $hasil = $this->import->importSiswa($request->file('berkas'));
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => 'Berkas tidak dapat dibaca: '.$e->getMessage(),
                'code' => 'BERKAS_TIDAK_VALID',
            ], 422);
        }

        $this->audit->catat(AuditLogService::AKSI_IMPORT, $request->user(), null, null, [
            'modul' => 'siswa', 'berhasil' => $hasil['berhasil'], 'gagal' => $hasil['gagal'],
        ]);

        $pesan = $hasil['gagal'] === 0
            ? "{$hasil['berhasil']} siswa berhasil diimport."
            : "{$hasil['berhasil']} siswa berhasil, {$hasil['gagal']} baris gagal.";

        return response()->json(['message' => $pesan, 'data' => $hasil]);
    }

    /** FR-SIS-04 — export daftar siswa ke Excel. */
    public function ekspor(Request $request): Response
    {
        $format = $request->string('format', 'xlsx')->toString();

        if ($format !== 'xlsx') {
            abort(422, 'Format ekspor yang tersedia saat ini hanya xlsx. Ekspor PDF menyusul pada Fase 5.');
        }

        return $this->ekspor->siswa($this->query($request));
    }

    /** FR-SIS-03 — templat import yang dapat diunduh. */
    public function template(): Response
    {
        return $this->ekspor->templateSiswa();
    }

    /** @return Builder<Siswa> */
    private function query(Request $request): Builder
    {
        $kelasId = $request->integer('kelas_id');

        return Siswa::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('tahun_masuk'), fn ($q) => $q->where('tahun_masuk', $request->integer('tahun_masuk')))
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->where(fn ($w) => $w->where('nis', 'like', $cari)
                    ->orWhere('nisn', 'like', $cari)
                    ->orWhere('nama', 'like', $cari));
            })
            // Filter kelas aktif hanya bila tabel plotting_kelas sudah ada (Fase 2).
            ->when($kelasId > 0 && Schema::hasTable('plotting_kelas'), function ($q) use ($kelasId): void {
                $q->whereIn('siswa.id', DB::table('plotting_kelas')->where('kelas_id', $kelasId)->pluck('siswa_id'));
            })
            ->orderBy('nama');
    }
}
