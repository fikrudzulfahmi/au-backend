<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pengaturan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengaturan\PenandatanganRequest;
use App\Http\Resources\PenandatanganResource;
use App\Models\Penandatangan;
use App\Services\AuditLogService;
use App\Services\BerkasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-KOP-03 — kelola penandatangan dokumen resmi (banyak data, maksimal 2 dipakai
 * per dokumen). Hanya admin (matriks Bagian 2: pengaturan kop & TTD = K).
 *
 * Tanda tangan & stempel disimpan sebagai PNG di disk privat agar latar
 * transparannya terjaga; berkas lama dihapus saat diganti.
 */
final class PenandatanganController extends Controller
{
    public function __construct(
        private readonly BerkasService $berkas,
        private readonly AuditLogService $audit,
    ) {}

    public function index(): JsonResponse
    {
        $daftar = Penandatangan::query()
            ->orderByDesc('is_default')
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => PenandatanganResource::collection($daftar)]);
    }

    public function store(PenandatanganRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['ttd', 'stempel', 'hapus_ttd', 'hapus_stempel']);

        $penandatangan = DB::transaction(function () use ($data, $request): Penandatangan {
            $penandatangan = Penandatangan::create([
                ...$data,
                'urutan' => (int) ($data['urutan'] ?? 1),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'ttd_path' => $this->simpan($request, 'ttd', null),
                'stempel_path' => $this->simpan($request, 'stempel', null),
            ]);

            if ($penandatangan->is_default) {
                $this->kosongkanDefaultLain((int) $penandatangan->getKey());
            }

            return $penandatangan;
        });

        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $penandatangan, null, [
            'jabatan' => $penandatangan->jabatan,
            'nama' => $penandatangan->nama,
        ]);

        return response()->json([
            'message' => 'Penandatangan berhasil disimpan.',
            'data' => new PenandatanganResource($penandatangan),
        ], 201);
    }

    public function update(PenandatanganRequest $request, Penandatangan $penandatangan): JsonResponse
    {
        $data = $request->safe()->except(['ttd', 'stempel', 'hapus_ttd', 'hapus_stempel']);
        $lama = ['jabatan' => $penandatangan->jabatan, 'nama' => $penandatangan->nama];

        DB::transaction(function () use ($data, $request, $penandatangan): void {
            $penandatangan->fill([
                ...$data,
                'urutan' => (int) ($data['urutan'] ?? $penandatangan->urutan),
                'is_default' => (bool) ($data['is_default'] ?? $penandatangan->is_default),
                'is_active' => (bool) ($data['is_active'] ?? $penandatangan->is_active),
            ]);

            if ($request->boolean('hapus_ttd')) {
                $this->berkas->hapus($penandatangan->ttd_path);
                $penandatangan->ttd_path = null;
            }

            if ($request->boolean('hapus_stempel')) {
                $this->berkas->hapus($penandatangan->stempel_path);
                $penandatangan->stempel_path = null;
            }

            if ($request->hasFile('ttd')) {
                $this->berkas->hapus($penandatangan->ttd_path);
                $penandatangan->ttd_path = $this->simpan($request, 'ttd', null);
            }

            if ($request->hasFile('stempel')) {
                $this->berkas->hapus($penandatangan->stempel_path);
                $penandatangan->stempel_path = $this->simpan($request, 'stempel', null);
            }

            $penandatangan->save();

            if ($penandatangan->is_default) {
                $this->kosongkanDefaultLain((int) $penandatangan->getKey());
            }
        });

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $penandatangan, $lama, [
            'jabatan' => $penandatangan->jabatan,
            'nama' => $penandatangan->nama,
        ]);

        return response()->json([
            'message' => 'Penandatangan berhasil diperbarui.',
            'data' => new PenandatanganResource($penandatangan->refresh()),
        ]);
    }

    public function destroy(Request $request, Penandatangan $penandatangan): JsonResponse
    {
        $this->berkas->hapus($penandatangan->ttd_path);
        $this->berkas->hapus($penandatangan->stempel_path);

        $penandatangan->delete();

        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $penandatangan, [
            'jabatan' => $penandatangan->jabatan,
            'nama' => $penandatangan->nama,
        ], null);

        return response()->json(['message' => 'Penandatangan berhasil dihapus.']);
    }

    private function simpan(Request $request, string $bidang, ?string $lama): ?string
    {
        if ($request->hasFile($bidang)) {
            return $this->berkas->simpanGambarPng($request->file($bidang), 'penandatangan');
        }

        return $lama;
    }

    /** Hanya satu penandatangan default pada satu waktu. */
    private function kosongkanDefaultLain(int $id): void
    {
        Penandatangan::query()
            ->whereKeyNot($id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
