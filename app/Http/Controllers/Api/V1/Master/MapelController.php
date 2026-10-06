<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\MapelRequest;
use App\Http\Resources\MapelResource;
use App\Models\Mapel;
use App\Services\AuditLogService;
use App\Services\EksporMasterService;
use App\Support\PenjagaHapus;
use App\Support\ResponsDaftar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FR-MPL-01..03 — mata pelajaran. */
class MapelController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly EksporMasterService $ekspor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ResponsDaftar::buat($this->query($request)->paginate($this->perHalaman($request)), MapelResource::class);
    }

    public function store(MapelRequest $request): JsonResponse
    {
        $mapel = Mapel::create($request->validated());
        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $mapel, null, $mapel->toArray());

        return response()->json(['data' => new MapelResource($mapel->load('jurusan'))], 201);
    }

    public function update(MapelRequest $request, Mapel $mapel): JsonResponse
    {
        $lama = $mapel->toArray();
        $mapel->update($request->validated());
        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $mapel, $lama, $request->validated());

        return response()->json(['data' => new MapelResource($mapel->refresh()->load('jurusan'))]);
    }

    /** FR-MPL-03 — mapel yang sudah dipakai plotting tidak boleh dihapus. */
    public function destroy(Request $request, Mapel $mapel): JsonResponse
    {
        if ($pesan = PenjagaHapus::periksa($mapel->getKey(), [
            ['plotting_mapel', 'mapel_id', 'plotting mata pelajaran'],
        ])) {
            return response()->json([
                'message' => $pesan.' Nonaktifkan mata pelajaran bila sudah tidak dipakai.',
                'code' => 'KONFLIK_DATA',
            ], 409);
        }

        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $mapel, $mapel->toArray(), null);
        $mapel->delete();

        return response()->json(['message' => 'Mata pelajaran berhasil dihapus.']);
    }

    /** FR-MPL-02 — export daftar mapel ke Excel. */
    public function ekspor(Request $request): Response
    {
        $mapel = $this->query($request)->orderBy('kode')->get();

        $baris = $mapel->map(fn (Mapel $m): array => [
            $m->kode, $m->nama, $m->kelompok, $m->jurusan?->nama, $m->is_active ? 'Aktif' : 'Nonaktif',
        ])->all();

        return $this->ekspor->excelDaftarMapel($baris);
    }

    /** @return Builder<Mapel> */
    private function query(Request $request): Builder
    {
        return Mapel::query()
            ->with('jurusan')
            ->when($request->filled('kelompok'), fn ($q) => $q->where('kelompok', $request->string('kelompok')))
            ->when($request->filled('jurusan_id'), fn ($q) => $q->where('jurusan_id', $request->integer('jurusan_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->where(fn ($w) => $w->where('kode', 'like', $cari)->orWhere('nama', 'like', $cari));
            });
    }
}
