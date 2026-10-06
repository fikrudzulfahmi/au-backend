<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PengaturanService;
use App\Services\TvSesiService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-32 — penjaga endpoint `tv`.
 *
 * Middleware ini HANYA menerima token TV (tabel `sesi_tv`); token Sanctum biasa
 * ditolak. Sebaliknya, token TV juga tidak pernah diterima `auth:sanctum`
 * karena tidak tersimpan di `personal_access_tokens` — inilah yang membuat
 * token TV terbatas pada endpoint `tv` (diuji dua arah).
 *
 * TV nonaktif (BR-32) menolak SEMUA akses, termasuk token yang masih berlaku.
 */
class AutentikasiTv
{
    public function __construct(
        private readonly PengaturanService $pengaturan,
        private readonly TvSesiService $sesi,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) $this->pengaturan->ambil('tv_aktif')) {
            return response()->json([
                'message' => 'Layar TV sedang dinonaktifkan oleh administrator.',
                'code' => 'TV_NONAKTIF',
            ], 403);
        }

        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            $header = $request->header('X-TV-Token');
            $token = is_string($header) ? $header : null;
        }

        $sesi = $this->sesi->cari($token);

        if ($sesi === null) {
            return response()->json([
                'message' => 'Sesi TV tidak sah atau sudah berakhir. Silakan masukkan kode TV kembali.',
                'code' => 'TV_TIDAK_SAH',
            ], 401);
        }

        $request->attributes->set('sesi_tv', $sesi);

        return $next($request);
    }
}
