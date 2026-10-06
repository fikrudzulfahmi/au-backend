<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Http\Requests\PengajuanLuarRadiusRequest;
use App\Http\Resources\PengajuanLuarRadiusResource;
use App\Models\PengajuanLuarRadius;
use App\Models\Role;
use App\Services\PengajuanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-IZN-06 / FR-PRS-07 Jalur A — pengajuan presensi di luar radius.
 */
final class PengajuanLuarRadiusController extends Controller
{
    use MemakaiPegawai;

    public function __construct(private readonly PengajuanService $pengajuan) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->pengguna($request);
        $bolehSemua = $user->punyaPeran(Role::ADMIN)
            || $user->punyaPeran(Role::KEPALA_SEKOLAH)
            || $user->punyaPeran(Role::WAKASEK_KURIKULUM);

        $daftar = PengajuanLuarRadius::query()
            ->with(['pegawai', 'diputuskanOleh'])
            ->when(! $bolehSemua, fn ($q) => $q->where('pegawai_id', $user->pegawai_id))
            ->when($bolehSemua && $request->filled('pegawai_id'), fn ($q) => $q->where('pegawai_id', $request->integer('pegawai_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('tanggal', $request->date('tanggal')))
            ->orderByDesc('tanggal')
            ->paginate($this->perHalaman($request));

        return response()->json([
            'data' => PengajuanLuarRadiusResource::collection($daftar->items()),
            'meta' => [
                'page' => $daftar->currentPage(),
                'per_page' => $daftar->perPage(),
                'total' => $daftar->total(),
                'last_page' => $daftar->lastPage(),
            ],
        ]);
    }

    /** FR-IZN-06 — pegawai mengajukan presensi luar radius mandiri. */
    public function store(PengajuanLuarRadiusRequest $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $pengajuan = $this->pengajuan->ajukanLuarRadius($pegawai, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Pengajuan presensi luar radius terkirim. Setelah disetujui, presensi pada tanggal itu otomatis sah (BR-17 Jalur A).',
            'data' => new PengajuanLuarRadiusResource($pengajuan->load('pegawai')),
        ], 201);
    }

    public function putuskan(Request $request, PengajuanLuarRadius $pengajuanLuarRadius): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:disetujui,ditolak'],
            'catatan_penyetuju' => ['nullable', 'string', 'max:1000'],
        ]);

        $pengajuan = $this->pengajuan->putuskanLuarRadius(
            $pengajuanLuarRadius,
            $data['status'],
            $data['catatan_penyetuju'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Keputusan tersimpan.',
            'data' => new PengajuanLuarRadiusResource($pengajuan->load('pegawai')),
        ]);
    }
}
