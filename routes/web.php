<?php

use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

// Halaman status ringan di akar domain untuk memastikan backend hidup.
// Rute ini memakai controller (bukan closure) agar `php artisan route:cache`
// tetap berhasil pada deploy produksi.
Route::get('/', [StatusController::class, 'index'])->name('status');
