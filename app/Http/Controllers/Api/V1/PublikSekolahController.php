<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SekolahResource;
use App\Models\ProfilSekolah;
use Illuminate\Http\JsonResponse;

/**
 * Kelompok endpoint `publik` (3.4) — tanpa login.
 * BR-34: hanya info sekolah; tidak ada data pegawai, siswa, atau kehadiran.
 */
class PublikSekolahController extends Controller
{
    public function show(): JsonResponse
    {
        $sekolah = ProfilSekolah::query()->first();

        if (! $sekolah) {
            return response()->json([
                'message' => 'Info sekolah belum diisi oleh administrator.',
                'code' => 'INFO_SEKOLAH_KOSONG',
            ], 404);
        }

        return response()->json(['data' => new SekolahResource($sekolah)]);
    }
}
