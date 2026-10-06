<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\JurusanRequest;
use App\Http\Resources\JurusanResource;
use App\Models\Jurusan;
use App\Services\AuditLogService;
use App\Support\PenjagaHapus;
use App\Support\ResponsDaftar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-KLS-01 — master jurusan / kompetensi keahlian. */
class JurusanController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Jurusan::query()
            ->withCount('kelas')
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->where(fn ($w) => $w->where('kode', 'like', $cari)->orWhere('nama', 'like', $cari));
            })
            ->orderBy('kode');

        return ResponsDaftar::buat($query->paginate($this->perHalaman($request)), JurusanResource::class);
    }

    public function store(JurusanRequest $request): JsonResponse
    {
        $jurusan = Jurusan::create($request->validated());
        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $jurusan, null, $jurusan->toArray());

        return response()->json(['data' => new JurusanResource($jurusan)], 201);
    }

    public function update(JurusanRequest $request, Jurusan $jurusan): JsonResponse
    {
        $lama = $jurusan->toArray();
        $jurusan->update($request->validated());
        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $jurusan, $lama, $request->validated());

        return response()->json(['data' => new JurusanResource($jurusan)]);
    }

    public function destroy(Request $request, Jurusan $jurusan): JsonResponse
    {
        if ($jurusan->kelas()->exists()) {
            return response()->json([
                'message' => 'Jurusan tidak dapat dihapus karena masih dipakai kelas.',
                'code' => 'KONFLIK_DATA',
            ], 409);
        }

        if ($pesan = PenjagaHapus::periksa($jurusan->getKey(), [
            ['mapel', 'jurusan_id', 'mata pelajaran'],
        ])) {
            return response()->json(['message' => $pesan, 'code' => 'KONFLIK_DATA'], 409);
        }

        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $jurusan, $jurusan->toArray(), null);
        $jurusan->delete();

        return response()->json(['message' => 'Jurusan berhasil dihapus.']);
    }
}
