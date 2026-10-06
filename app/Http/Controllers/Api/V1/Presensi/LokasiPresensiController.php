<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Http\Controllers\Controller;
use App\Http\Requests\LokasiPresensiRequest;
use App\Http\Requests\TetapkanLokasiRequest;
use App\Http\Resources\LokasiPresensiResource;
use App\Models\LokasiPresensi;
use App\Models\Pegawai;
use App\Services\LokasiPresensiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-LOK-01..03 — master lokasi presensi (Bagian 2: hanya admin). */
final class LokasiPresensiController extends Controller
{
    public function __construct(private readonly LokasiPresensiService $lokasi) {}

    public function index(Request $request): JsonResponse
    {
        $daftar = LokasiPresensi::query()
            ->withCount('pegawai')
            ->when($request->filled('cari'), fn ($q) => $q->where('nama', 'like', '%'.$request->string('cari').'%'))
            ->orderByDesc('is_default')
            ->orderBy('nama')
            ->get();

        return response()->json(['data' => LokasiPresensiResource::collection($daftar)]);
    }

    public function store(LokasiPresensiRequest $request): JsonResponse
    {
        $lokasi = $this->lokasi->simpan($request->validated(), null, $request->user());

        return response()->json([
            'message' => 'Lokasi presensi disimpan.',
            'data' => new LokasiPresensiResource($lokasi->loadCount('pegawai')),
        ], 201);
    }

    public function update(LokasiPresensiRequest $request, LokasiPresensi $lokasiPresensi): JsonResponse
    {
        $lokasi = $this->lokasi->simpan($request->validated(), $lokasiPresensi, $request->user());

        return response()->json([
            'message' => 'Lokasi presensi diperbarui.',
            'data' => new LokasiPresensiResource($lokasi->loadCount('pegawai')),
        ]);
    }

    /** FR-LOK-02 — menjadikan lokasi sebagai satu-satunya default (BR-12). */
    public function jadikanDefault(Request $request, LokasiPresensi $lokasiPresensi): JsonResponse
    {
        $lokasi = $this->lokasi->jadikanDefault($lokasiPresensi, $request->user());

        return response()->json([
            'message' => 'Lokasi default diperbarui. Pegawai tanpa penetapan khusus memakai lokasi ini (BR-12).',
            'data' => new LokasiPresensiResource($lokasi),
        ]);
    }

    public function destroy(Request $request, LokasiPresensi $lokasiPresensi): JsonResponse
    {
        $this->lokasi->hapus($lokasiPresensi, $request->user());

        return response()->json(['message' => 'Lokasi presensi dihapus.']);
    }

    /** FR-LOK-03 — penetapan massal lokasi ke pegawai. */
    public function tetapkan(TetapkanLokasiRequest $request): JsonResponse
    {
        $hasil = $this->lokasi->tetapkanPegawai(
            $request->validated('pegawai_ids'),
            $request->validated('lokasi_ids') ?? [],
            $request->user(),
        );

        return response()->json([
            'message' => "Lokasi ditetapkan untuk {$hasil['pegawai']} pegawai.",
            'data' => $hasil,
        ]);
    }

    /** FR-LOK-03 — daftar pegawai beserta lokasi yang ditetapkan (untuk layar penetapan). */
    public function pegawai(Request $request): JsonResponse
    {
        $daftar = Pegawai::query()
            ->with('lokasi')
            ->where('is_active', true)
            ->when($request->filled('jenis_pegawai'), fn ($q) => $q->where('jenis_pegawai', $request->string('jenis_pegawai')))
            ->orderBy('nama')
            ->get()
            ->map(fn (Pegawai $p): array => [
                'pegawai_id' => $p->id,
                'nip' => $p->nip,
                'nama' => $p->nama,
                'jenis_pegawai' => $p->jenis_pegawai,
                'lokasi_ids' => $p->lokasi->pluck('id')->all(),
                'lokasi_nama' => $p->lokasi->pluck('nama')->all(),
            ]);

        return response()->json(['data' => $daftar]);
    }
}
