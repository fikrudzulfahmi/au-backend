<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Http\Controllers\Controller;
use App\Http\Requests\JamKerjaRequest;
use App\Models\JamKerja;
use App\Services\JamKerjaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-LOK-04 — pengaturan jam kerja per jenis pegawai (Bagian 2: hanya admin). */
final class JamKerjaController extends Controller
{
    public function __construct(private readonly JamKerjaService $jamKerja) {}

    public function index(Request $request): JsonResponse
    {
        $jenis = (string) ($request->string('jenis_pegawai')->toString() ?: JamKerja::JENIS_GURU);

        return response()->json([
            'data' => [
                'jenis_pegawai' => $jenis,
                'per_hari' => $this->jamKerja->sepekan($jenis),
            ],
        ]);
    }

    public function simpan(JamKerjaRequest $request): JsonResponse
    {
        $jenis = $request->validated('jenis_pegawai');
        $tersimpan = $this->jamKerja->simpanMassal($jenis, $request->validated('per_hari'), $request->user());

        return response()->json([
            'message' => 'Jam kerja disimpan ('.count($tersimpan).' hari).',
            'data' => ['jenis_pegawai' => $jenis, 'per_hari' => $this->jamKerja->sepekan($jenis)],
        ]);
    }
}
