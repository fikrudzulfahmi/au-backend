<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PengumumanResource;
use App\Models\Pengumuman;
use App\Services\PengumumanService;
use Illuminate\Http\JsonResponse;

/**
 * FR-LND-05 / FR-LND-09 — pengumuman untuk landing page.
 *
 * BR-34: hanya pengumuman bertanda `tampil_landing`; tidak ada data pegawai,
 * siswa, atau angka kehadiran.
 */
class PublikPengumumanController extends Controller
{
    public function __construct(private readonly PengumumanService $pengumuman) {}

    public function index(): JsonResponse
    {
        $daftar = $this->pengumuman
            ->untukTarget(Pengumuman::TARGET_LANDING)
            ->reject(fn (Pengumuman $p): bool => $p->teksBerjalan())
            ->values();

        return response()->json(['data' => PengumumanResource::collection($daftar)]);
    }
}
