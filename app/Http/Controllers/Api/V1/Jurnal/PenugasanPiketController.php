<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Jurnal;

use App\Http\Controllers\Controller;
use App\Http\Requests\PenugasanPiketRequest;
use App\Http\Resources\PenugasanPiketResource;
use App\Models\Pegawai;
use App\Models\PenugasanPiket;
use App\Models\PolaJamHari;
use App\Models\Semester;
use App\Services\PenugasanPiketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penugasan guru piket — menu admin: siapa piket pada hari apa.
 *
 * Guru yang ditugaskan pada sebuah hari berhak mengisi jurnal kelas lain pada
 * hari itu (lihat `JurnalService`), karena itulah piket: menggantikan pengampu
 * yang berhalangan. Karena penetapannya menentukan siapa yang boleh menulis
 * jurnal orang lain, endpoint ini hanya untuk admin.
 */
final class PenugasanPiketController extends Controller
{
    public function __construct(private readonly PenugasanPiketService $piket) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['nullable', 'integer', 'exists:semester,id'],
            'hari' => ['nullable', 'integer', 'between:1,7'],
            'cari' => ['nullable', 'string', 'max:60'],
        ]);

        $semesterId = isset($data['semester_id'])
            ? (int) $data['semester_id']
            : (int) Semester::aktif()->firstOrFail()->id;

        $daftar = PenugasanPiket::query()
            ->with(['pegawai:id,nip,nama', 'semester'])
            ->untukSemester($semesterId)
            ->when(isset($data['hari']), fn ($q) => $q->where('hari', (int) $data['hari']))
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->whereHas('pegawai', fn ($p) => $p->where('nama', 'like', $cari)->orWhere('nip', 'like', $cari));
            })
            ->orderBy('hari')
            ->orderBy('pegawai_id')
            ->get();

        return response()->json([
            'data' => PenugasanPiketResource::collection($daftar),
            'meta' => [
                'semester_id' => $semesterId,
                // Dikirim server supaya label hari tidak didefinisikan ulang di
                // klien dan tidak pernah menyimpang dari `jadwal`.
                'hari' => collect(PolaJamHari::NAMA)->map(fn (string $nama, int $kode): array => [
                    'kode' => $kode,
                    'nama' => $nama,
                ])->values(),
            ],
        ]);
    }

    /** Guru aktif yang bisa dipiketkan — untuk pilihan pada layar penetapan. */
    public function pegawai(): JsonResponse
    {
        $daftar = Pegawai::query()
            ->where('jenis_pegawai', Pegawai::JENIS_GURU)
            ->where('is_active', true)
            ->orderBy('nama')
            ->get(['id', 'nip', 'nama'])
            ->map(fn (Pegawai $p): array => [
                'id' => (int) $p->id,
                'nip' => $p->nip,
                'nama' => $p->nama,
            ]);

        return response()->json(['data' => $daftar]);
    }

    public function store(PenugasanPiketRequest $request): JsonResponse
    {
        $piket = $this->piket->simpan($request->validated(), null, $request->user());

        return response()->json([
            'message' => 'Penugasan piket disimpan.',
            'data' => new PenugasanPiketResource($piket),
        ], 201);
    }

    public function update(PenugasanPiketRequest $request, PenugasanPiket $penugasanPiket): JsonResponse
    {
        $piket = $this->piket->simpan($request->validated(), $penugasanPiket, $request->user());

        return response()->json([
            'message' => 'Penugasan piket diperbarui.',
            'data' => new PenugasanPiketResource($piket),
        ]);
    }

    public function destroy(Request $request, PenugasanPiket $penugasanPiket): JsonResponse
    {
        $this->piket->hapus($penugasanPiket, $request->user());

        return response()->json(['message' => 'Penugasan piket dihapus.']);
    }
}
