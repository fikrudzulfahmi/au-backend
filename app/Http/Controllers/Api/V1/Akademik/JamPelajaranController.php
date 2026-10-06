<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\PolaJamRequest;
use App\Http\Requests\SalinRequest;
use App\Http\Requests\SlotJamRequest;
use App\Http\Resources\PolaJamResource;
use App\Http\Resources\SlotJamResource;
use App\Models\PolaJam;
use App\Models\Semester;
use App\Models\SlotJam;
use App\Services\JamPelajaranService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** FR-JAM — pola jam pelajaran per semester. */
final class JamPelajaranController extends Controller
{
    public function __construct(private readonly JamPelajaranService $service) {}

    public function index(Request $request): JsonResponse
    {
        $semester = $this->semesterDariPermintaan($request);

        $pola = PolaJam::with(['hari', 'slot' => fn ($q) => $q->withCount('jadwal')])
            ->where('semester_id', $semester->id)
            ->orderBy('nama')
            ->get();

        return response()->json([
            'data' => PolaJamResource::collection($pola),
            'meta' => ['semester_id' => $semester->id, 'semester' => $semester->label()],
        ]);
    }

    public function store(PolaJamRequest $request): JsonResponse
    {
        $pola = $this->service->simpanPola(
            null,
            Semester::findOrFail($request->integer('semester_id')),
            (string) $request->string('nama'),
            $request->array('hari'),
            $request->user(),
        );

        return response()->json(['message' => 'Pola jam disimpan.', 'data' => new PolaJamResource($pola)], 201);
    }

    public function update(PolaJamRequest $request, PolaJam $polaJam): JsonResponse
    {
        $pola = $this->service->simpanPola(
            $polaJam,
            Semester::findOrFail($request->integer('semester_id')),
            (string) $request->string('nama'),
            $request->array('hari'),
            $request->user(),
        );

        return response()->json(['message' => 'Pola jam diperbarui.', 'data' => new PolaJamResource($pola)]);
    }

    public function destroy(Request $request, PolaJam $polaJam): JsonResponse
    {
        $this->service->hapusPola($polaJam, $request->user());

        return response()->json(['message' => 'Pola jam dihapus.']);
    }

    /** FR-JAM-02/03 — menambah slot pada sebuah pola. */
    public function storeSlot(SlotJamRequest $request, PolaJam $polaJam): JsonResponse
    {
        $slot = $this->service->simpanSlot($polaJam, $request->validated(), null, $request->user());

        return response()->json([
            'message' => 'Slot jam disimpan.',
            'data' => new SlotJamResource($slot->loadCount('jadwal')),
        ], 201);
    }

    public function updateSlot(SlotJamRequest $request, SlotJam $slotJam): JsonResponse
    {
        $slot = $this->service->simpanSlot($slotJam->polaJam, $request->validated(), $slotJam, $request->user());

        return response()->json([
            'message' => 'Slot jam diperbarui.',
            'data' => new SlotJamResource($slot->loadCount('jadwal')),
        ]);
    }

    /** FR-JAM-05 — slot yang dipakai jadwal tidak boleh dihapus. */
    public function destroySlot(Request $request, SlotJam $slotJam): JsonResponse
    {
        $this->service->hapusSlot($slotJam, $request->user());

        return response()->json(['message' => 'Slot jam dihapus.']);
    }

    /** FR-JAM-04 — salin pola jam dari semester lain. */
    public function salin(SalinRequest $request): JsonResponse
    {
        $hasil = $this->service->salin(
            Semester::findOrFail($request->integer('semester_asal_id')),
            Semester::findOrFail($request->integer('semester_tujuan_id')),
            $request->user(),
        );

        return response()->json([
            'message' => "{$hasil['dibuat']} pola jam disalin, ".count($hasil['dilewati']).' dilewati.',
            'data' => $hasil,
        ]);
    }

    private function semesterDariPermintaan(Request $request): Semester
    {
        if ($request->filled('semester_id')) {
            $semester = Semester::find($request->integer('semester_id'));
            if ($semester !== null) {
                return $semester;
            }
        }

        return Semester::where('is_active', true)->first()
            ?? throw new NotFoundHttpException('Belum ada semester aktif.');
    }
}
