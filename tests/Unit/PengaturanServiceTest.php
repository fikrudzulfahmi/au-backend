<?php

declare(strict_types=1);

use App\Services\PengaturanService;

/**
 * FR-LOK-06 — radius dan parameter teknis tidak di-hardcode; seluruh nilai
 * dapat diubah admin tanpa deploy ulang (7.6).
 */
it('memberi nilai default sesuai spesifikasi 7.6 dan FR-LOK-05', function (): void {
    $service = app(PengaturanService::class);

    expect($service->ambil('gps_max_akurasi_m'))->toBe(50)
        ->and($service->ambil('foto_max_sisi_px'))->toBe(800)
        ->and($service->ambil('foto_kualitas_jpeg'))->toBe(65)
        ->and($service->ambil('foto_target_maks_kb'))->toBe(150)
        ->and($service->ambil('tv_interval_detik'))->toBe(30)
        ->and($service->ambil('tv_tema'))->toBe('gelap')
        ->and($service->ambil('tv_tampilkan_alasan_izin'))->toBeFalse();
});

it('menyimpan nilai sesuai tipe yang tercatat', function (): void {
    $service = app(PengaturanService::class);

    $service->simpan('gps_max_akurasi_m', 35);
    $service->simpan('tv_aktif', false);

    expect($service->ambil('gps_max_akurasi_m'))->toBe(35)
        ->and($service->ambil('tv_aktif'))->toBeFalse();

    $this->assertDatabaseHas('pengaturan', ['kunci' => 'gps_max_akurasi_m', 'nilai' => '35', 'tipe' => 'int']);
    $this->assertDatabaseHas('pengaturan', ['kunci' => 'tv_aktif', 'nilai' => '0', 'tipe' => 'bool']);
});

it('mengembalikan nilai default bila belum pernah disimpan', function (): void {
    expect(app(PengaturanService::class)->ambil('landing_aktif'))->toBeTrue();
});
