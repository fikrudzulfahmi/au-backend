<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 3.4 — setiap permintaan API dicatat ke kanal log `api` agar error server
 * dapat ditelusuri (9 — pencatatan).
 */
class CatatPermintaanApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $mulai = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        if ($request->is('api/*')) {
            logger()->channel('api')->info('permintaan api', [
                'metode' => $request->method(),
                'jalur' => '/'.$request->path(),
                'status' => $response->getStatusCode(),
                'durasi_ms' => (int) round((microtime(true) - $mulai) * 1000),
                'pengguna_id' => $request->user()?->getKey(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }
}
