<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 3.2 / Bagian 2 — otorisasi berbasis peran ditegakkan di server.
 * Frontend hanya menyembunyikan menu, bukan pengganti middleware ini.
 */
class PastikanPeran
{
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            throw new AuthenticationException(
                'Anda belum terautentikasi. Silakan masuk terlebih dahulu.'
            );
        }

        if ($peran !== [] && ! $user->punyaPeran(...$peran)) {
            throw new AuthorizationException('Anda tidak berwenang mengakses fitur ini.');
        }

        return $next($request);
    }
}
