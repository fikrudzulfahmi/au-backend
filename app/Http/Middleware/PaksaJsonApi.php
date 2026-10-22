<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memaksa seluruh permintaan `api/*` memakai negosiasi JSON.
 *
 * Tanpa header `Accept`, middleware `auth` mencoba mengalihkan tamu ke route
 * `login` yang tidak ada di aplikasi API-only ini, sehingga hasilnya 500 —
 * bukan 401 JSON yang benar. Menyetel Accept di awal grup api membuat
 * `expectsJson()` selalu benar, termasuk untuk pemanggil non-peramban.
 */
class PaksaJsonApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
