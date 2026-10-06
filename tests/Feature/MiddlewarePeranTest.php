<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\Route;

/**
 * 3.2 / Bagian 2 — otorisasi peran ditegakkan di server, bukan hanya disembunyikan di UI.
 */
beforeEach(function (): void {
    siapkanPeran();

    Route::middleware(['auth:sanctum', 'peran:admin'])->get(
        '/api/v1/_uji/khusus-admin',
        fn () => response()->json(['data' => 'ok'])
    );

    Route::middleware(['auth:sanctum', 'peran:admin,kepala_sekolah'])->get(
        '/api/v1/_uji/pimpinan',
        fn () => response()->json(['data' => 'ok'])
    );
});

it('menolak permintaan tanpa token', function (): void {
    $this->getJson('/api/v1/_uji/khusus-admin')->assertStatus(401);
});

it('menolak pengguna yang perannya tidak sesuai', function (): void {
    $guru = buatPegawaiDenganAkun();

    $this->actingAs($guru)
        ->getJson('/api/v1/_uji/khusus-admin')
        ->assertStatus(403)
        ->assertJsonPath('code', 'TIDAK_BERWENANG');
});

it('mengizinkan pengguna dengan salah satu peran yang diminta', function (): void {
    $kepsek = buatPengguna([Role::KEPALA_SEKOLAH]);

    $this->actingAs($kepsek)->getJson('/api/v1/_uji/pimpinan')->assertOk();
    $this->actingAs($kepsek)->getJson('/api/v1/_uji/khusus-admin')->assertStatus(403);
});

it('mengizinkan admin mengakses rute khusus admin', function (): void {
    $admin = buatPengguna([Role::ADMIN]);

    $this->actingAs($admin)->getJson('/api/v1/_uji/khusus-admin')->assertOk();
});

it('menolak pengguna yang tidak aktif walau memegang peran', function (): void {
    $admin = buatPengguna([Role::ADMIN], ['is_active' => false]);

    $this->actingAs($admin)->getJson('/api/v1/_uji/khusus-admin')->assertStatus(401);
});
