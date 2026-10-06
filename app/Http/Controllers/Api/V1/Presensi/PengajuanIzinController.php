<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Http\Requests\PengajuanIzinRequest;
use App\Http\Requests\PutuskanPengajuanRequest;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\Role;
use App\Services\PengajuanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-IZN-01..09 — pengajuan izin/sakit/dinas/cuti.
 * Pegawai mengajukan miliknya sendiri; admin/kepala sekolah memutuskan (FR-IZN-04).
 */
final class PengajuanIzinController extends Controller
{
    use MemakaiPegawai;

    public function __construct(private readonly PengajuanService $pengajuan) {}

    /** FR-IZN-09 — pegawai melihat riwayatnya; pemantau dapat melihat semua. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->pengguna($request);
        $bolehSemua = $user->punyaPeran(Role::ADMIN)
            || $user->punyaPeran(Role::KEPALA_SEKOLAH)
            || $user->punyaPeran(Role::WAKASEK_KURIKULUM);

        $kueri = PengajuanIzin::query()
            ->with(['pegawai', 'diputuskanOleh'])
            ->when(! $bolehSemua, fn ($q) => $q->where('pegawai_id', $user->pegawai_id))
            ->when($bolehSemua && $request->filled('pegawai_id'), fn ($q) => $q->where('pegawai_id', $request->integer('pegawai_id')))
            ->when($request->filled('jenis'), fn ($q) => $q->where('jenis', $request->string('jenis')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('dari'), fn ($q) => $q->whereDate('tanggal_selesai', '>=', $request->date('dari')))
            ->when($request->filled('sampai'), fn ($q) => $q->whereDate('tanggal_mulai', '<=', $request->date('sampai')))
            ->orderByDesc('tanggal_mulai');

        $daftar = $kueri->paginate($this->perHalaman($request));

        return response()->json([
            'data' => PengajuanIzinResource::collection($daftar->items()),
            'meta' => [
                'page' => $daftar->currentPage(),
                'per_page' => $daftar->perPage(),
                'total' => $daftar->total(),
                'last_page' => $daftar->lastPage(),
                'menunggu' => PengajuanIzin::query()->when(! $bolehSemua, fn ($q) => $q->where('pegawai_id', $user->pegawai_id))->menunggu()->count(),
            ],
        ]);
    }

    /** FR-IZN-01 — pegawai mengajukan untuk dirinya sendiri. */
    public function store(PengajuanIzinRequest $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $pengajuan = $this->pengajuan->ajukan($pegawai, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Pengajuan terkirim dan menunggu persetujuan.',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ], 201);
    }

    /** FR-IZN-08 — admin membuat pengajuan atas nama pegawai (langsung disetujui). */
    public function storeAtasNama(PengajuanIzinRequest $request, Pegawai $pegawai): JsonResponse
    {
        $pengajuan = $this->pengajuan->buatAtasNama($pegawai, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Pengajuan dibuat atas nama '.$pegawai->nama.' dan langsung disetujui (FR-IZN-08).',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ], 201);
    }

    /** FR-IZN-04 — keputusan admin/kepala sekolah. */
    public function putuskan(PutuskanPengajuanRequest $request, PengajuanIzin $pengajuanIzin): JsonResponse
    {
        $pengajuan = $this->pengajuan->putuskan(
            $pengajuanIzin,
            $request->validated('status'),
            $request->validated('catatan_penyetuju'),
            $request->user(),
        );

        return response()->json([
            'message' => $pengajuan->status === PengajuanIzin::STATUS_DISETUJUI
                ? 'Pengajuan disetujui.'
                : 'Pengajuan ditolak.',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ]);
    }

    /** FR-IZN-03 — pegawai membatalkan pengajuannya selama masih menunggu. */
    public function batalkan(Request $request, PengajuanIzin $pengajuanIzin): JsonResponse
    {
        $user = $this->pengguna($request);

        $milikSendiri = $user->pegawai_id !== null && (int) $user->pegawai_id === (int) $pengajuanIzin->pegawai_id;

        if (! $milikSendiri && ! $user->punyaPeran(Role::ADMIN)) {
            return response()->json(['message' => 'Anda hanya dapat membatalkan pengajuan sendiri.'], 403);
        }

        $pengajuan = $this->pengajuan->batalkan($pengajuanIzin, $user);

        return response()->json([
            'message' => 'Pengajuan dibatalkan.',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ]);
    }
}
