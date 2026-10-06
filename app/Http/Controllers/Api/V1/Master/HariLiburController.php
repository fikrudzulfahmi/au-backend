<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\HariLiburRequest;
use App\Http\Resources\HariLiburResource;
use App\Models\HariLibur;
use App\Services\AuditLogService;
use App\Support\ResponsDaftar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-TP-07 — hari libur per tahun pelajaran; dipakai BR-24 saat menghitung alpa. */
class HariLiburController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = HariLibur::query()
            ->when($request->filled('tahun_pelajaran_id'), fn ($q) => $q->where('tahun_pelajaran_id', $request->integer('tahun_pelajaran_id')))
            ->orderBy('tanggal_mulai');

        return ResponsDaftar::buat($query->paginate($this->perHalaman($request)), HariLiburResource::class);
    }

    public function store(HariLiburRequest $request): JsonResponse
    {
        $hariLibur = HariLibur::create($request->validated());

        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $hariLibur, null, $hariLibur->toArray());

        return response()->json(['data' => new HariLiburResource($hariLibur)], 201);
    }

    public function update(HariLiburRequest $request, HariLibur $hariLibur): JsonResponse
    {
        $lama = $hariLibur->toArray();
        $hariLibur->update($request->validated());

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $hariLibur, $lama, $request->validated());

        return response()->json(['data' => new HariLiburResource($hariLibur)]);
    }

    public function destroy(Request $request, HariLibur $hariLibur): JsonResponse
    {
        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $hariLibur, $hariLibur->toArray(), null);
        $hariLibur->delete();

        return response()->json(['message' => 'Hari libur berhasil dihapus.']);
    }
}
