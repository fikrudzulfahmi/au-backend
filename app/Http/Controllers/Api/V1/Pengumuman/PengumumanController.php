<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pengumuman;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengumuman\PengumumanRequest;
use App\Http\Resources\PengumumanResource;
use App\Models\Pengumuman;
use App\Services\AuditLogService;
use App\Services\BerkasService;
use App\Support\ResponsDaftar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 5.20 / FR-PMN-01..03 — CRUD pengumuman.
 *
 * FR-PMN-02: hanya admin & kepala sekolah yang mengelola. Semua pengguna login
 * melihat pengumuman aktif di beranda lewat `aktif()` (BR-36).
 */
class PengumumanController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly BerkasService $berkas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $kolom = match ((string) $request->query('target', '')) {
            'app' => Pengumuman::TARGET_APP,
            'tv' => Pengumuman::TARGET_TV,
            'landing' => Pengumuman::TARGET_LANDING,
            default => null,
        };

        $daftar = Pengumuman::query()
            ->when($kolom !== null, fn ($q) => $q->where($kolom, true))
            ->when($request->filled('tipe'), fn ($q) => $q->where('tipe', $request->query('tipe')))
            ->when($request->filled('aktif'), fn ($q) => $q->where('is_active', $request->boolean('aktif')))
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_halaman', 15));

        return ResponsDaftar::buat($daftar, PengumumanResource::class);
    }

    /** FR-PMN-02 — carousel beranda: pengumuman aktif (BR-36), tanpa data mentah. */
    public function aktif(): JsonResponse
    {
        $daftar = Pengumuman::query()
            ->tayang()
            ->target(Pengumuman::TARGET_APP)
            ->orderByRaw("FIELD(prioritas, 'penting', 'normal')")
            ->orderByDesc('tanggal_mulai')
            ->get();

        return response()->json(['data' => PengumumanResource::collection($daftar)]);
    }

    public function store(PengumumanRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['gambar']);
        $data = $this->lampiran($request, $data);

        $pengumuman = Pengumuman::query()->create($data);

        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $pengumuman, null, [
            'judul' => $pengumuman->judul,
            'tipe' => $pengumuman->tipe,
        ]);

        return response()->json([
            'message' => 'Pengumuman berhasil dibuat.',
            'data' => new PengumumanResource($pengumuman),
        ], 201);
    }

    public function update(PengumumanRequest $request, Pengumuman $pengumuman): JsonResponse
    {
        $lama = $pengumuman->only(['judul', 'is_active', 'tanggal_mulai', 'tanggal_selesai']);

        $data = $request->safe()->except(['gambar']);
        $data = $this->lampiran($request, $data, $pengumuman->gambar_path);

        $pengumuman->update($data);

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $pengumuman, $lama, [
            'judul' => $pengumuman->judul,
        ]);

        return response()->json([
            'message' => 'Pengumuman berhasil diperbarui.',
            'data' => new PengumumanResource($pengumuman->refresh()),
        ]);
    }

    public function destroy(Request $request, Pengumuman $pengumuman): JsonResponse
    {
        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $pengumuman, [
            'judul' => $pengumuman->judul,
        ], null);

        if ($pengumuman->gambar_path !== null) {
            $this->berkas->hapus($pengumuman->gambar_path);
        }

        $pengumuman->delete();

        return response()->json(['message' => 'Pengumuman berhasil dihapus.']);
    }

    /**
     * FR-PMN-01 — gambar opsional (dikompres).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function lampiran(PengumumanRequest $request, array $data, ?string $kolomLama = null): array
    {
        if (! $request->hasFile('gambar')) {
            return $data;
        }

        $data['gambar_path'] = $this->berkas->simpanGambarTerkompres(
            $request->file('gambar'),
            'pengumuman',
            800,
            $kolomLama,
        );

        return $data;
    }
}
