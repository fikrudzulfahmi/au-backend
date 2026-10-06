<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SemesterRequest;
use App\Http\Resources\SemesterResource;
use App\Models\Semester;
use App\Services\AuditLogService;
use App\Services\TahunPelajaranService;
use Illuminate\Http\JsonResponse;

/** FR-TP-02 — mengubah rentang tanggal semester. */
class SemesterController extends Controller
{
    public function __construct(
        private readonly TahunPelajaranService $service,
        private readonly AuditLogService $audit,
    ) {}

    public function update(SemesterRequest $request, Semester $semester): JsonResponse
    {
        $lama = $semester->only(['tanggal_mulai', 'tanggal_selesai']);

        $semester = $this->service->ubahSemester($semester, $request->validated());

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $semester, $lama, $request->validated());

        return response()->json(['data' => new SemesterResource($semester)]);
    }
}
