<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Halaman status ringan (health check) untuk memastikan backend masih hidup.
 * Diakses lewat akar domain API, mis. https://api-sipandu.smkiau.sch.id/
 */
class StatusController extends Controller
{
    public function index(): View
    {
        $dbTerhubung = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbTerhubung = false;
        }

        return view('status', [
            'namaApp'     => 'SIPANDU API',
            'waktu'       => now()->format('d/m/Y H:i:s'),
            'timezone'    => (string) config('app.timezone'),
            'laravel'     => app()->version(),
            'php'         => PHP_VERSION,
            'lingkungan'  => (string) config('app.env'),
            'dbTerhubung' => $dbTerhubung,
        ]);
    }
}
