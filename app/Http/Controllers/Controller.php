<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Jumlah baris per halaman (3.4 — meta.per_page). */
    protected function perHalaman(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('per_page', 20)));
    }
}
