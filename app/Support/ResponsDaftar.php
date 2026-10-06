<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 3.4 — bentuk respons daftar: { "data": [...], "meta": { page, per_page, total } }.
 *
 * Catatan penting: `JsonResource::collection($paginator)` yang dibungkus lagi di
 * dalam `response()->json(['data' => ...])` akan MEMBUANG meta paginasi (hanya
 * item yang tersisa). Karena itu paginasi dibongkar manual di sini agar kontrak
 * respons konsisten untuk seluruh endpoint daftar.
 */
final class ResponsDaftar
{
    /**
     * @param  class-string<JsonResource>  $kelasResource
     */
    public static function buat(LengthAwarePaginator $paginator, string $kelasResource): JsonResponse
    {
        return response()->json([
            'data' => $kelasResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
