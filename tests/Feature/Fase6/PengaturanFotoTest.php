<?php

declare(strict_types=1);

use App\Services\PengaturanService;
use App\Support\BatasFoto;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Fase 6 — Batas kompresi foto untuk klien (BR-29)
|--------------------------------------------------------------------------
| Foto asal boleh berukuran berapa pun: klien mengompres ke nilai Pengaturan
| Sistem, dan batas validasi server mengikuti nilai itu (bukan 1 MB tetap).
*/

beforeEach(function (): void {
    siapkanPeran();
    Cache::flush();
});

it('guru membaca setelan kompresi foto yang sama dengan pengaturan sistem', function (): void {
    $guru = buatPegawaiDenganAkun();

    test()->actingAs($guru)
        ->getJson('/api/v1/pengaturan/foto')
        ->assertOk()
        ->assertJsonPath('data.max_sisi_px', 800)
        ->assertJsonPath('data.kualitas_jpeg', 65)
        ->assertJsonPath('data.target_maks_kb', 150);
});

it('nilai setelan yang diubah admin langsung terbaca klien', function (): void {
    $admin = sebagaiAdmin();
    $guru = buatPegawaiDenganAkun();

    test()->actingAs($admin)->putJson('/api/v1/pengaturan/sistem', [
        'gps_max_akurasi_m' => 50,
        'foto_max_sisi_px' => 1280,
        'foto_kualitas_jpeg' => 80,
        'foto_target_maks_kb' => 400,
    ])->assertOk();

    Cache::flush();

    test()->actingAs($guru)
        ->getJson('/api/v1/pengaturan/foto')
        ->assertOk()
        ->assertJsonPath('data.max_sisi_px', 1280)
        ->assertJsonPath('data.kualitas_jpeg', 80)
        ->assertJsonPath('data.target_maks_kb', 400);
});

it('batas validasi unggah mengikuti target kompresi, bukan angka tetap 1 MB', function (): void {
    expect(BatasFoto::kb())->toBe(1024);

    app(PengaturanService::class)->simpan('foto_target_maks_kb', 900);
    Cache::flush();

    expect(BatasFoto::kb())->toBe(1800)
        ->and(BatasFoto::pesan())->toContain('1,8 MB');
});

it('tamu tidak boleh membaca setelan kompresi foto', function (): void {
    test()->getJson('/api/v1/pengaturan/foto')->assertUnauthorized();
});
