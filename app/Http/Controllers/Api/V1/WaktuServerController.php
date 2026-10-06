<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WaktuService;
use Illuminate\Http\JsonResponse;

/**
 * BR-13 / BR-38 — GET /api/v1/waktu-server.
 * Jam di UI dan layar TV disinkronkan dengan waktu server, bukan jam perangkat.
 */
class WaktuServerController extends Controller
{
    public function __construct(private readonly WaktuService $waktu) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->waktu->payload()]);
    }
}
