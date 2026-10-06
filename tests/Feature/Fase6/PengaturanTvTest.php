<?php

declare(strict_types=1);

use App\Services\PengaturanService;
use App\Services\TvSesiService;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Fase 6 — Pengaturan Layar TV & sesi aktif (FR-TV-16, BR-35)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    siapkanPeran();
    Cache::flush();
});

it('FR-TV-16 admin melihat pengaturan TV beserta kode aktif', function (): void {
    $admin = sebagaiAdmin();

    $data = test()->actingAs($admin)->getJson('/api/v1/pengaturan/tv')->assertOk()->json('data');

    expect($data)->toHaveKeys([
        'tv_aktif', 'tv_izinkan_npsn', 'tv_interval_detik', 'tv_masa_berlaku_hari',
        'tv_tema', 'tv_skala_font', 'tv_tampilkan_alasan_izin', 'tv_tampilkan_ulang_tahun',
        'tv_rotasi_panel_detik', 'tv_kecepatan_scroll', 'kode',
    ])
        ->and(strlen((string) $data['kode']))->toBe(8)
        ->and($data['tv_interval_detik'])->toBe(30);
});

it('FR-TV-16 admin dapat menyimpan pengaturan tampilan', function (): void {
    $admin = sebagaiAdmin();

    test()->actingAs($admin)->putJson('/api/v1/pengaturan/tv', [
        'tv_aktif' => true,
        'tv_izinkan_npsn' => false,
        'tv_interval_detik' => 60,
        'tv_masa_berlaku_hari' => 14,
        'tv_tema' => 'terang',
        'tv_skala_font' => 'ekstra_besar',
        'tv_tampilkan_alasan_izin' => true,
        'tv_tampilkan_ulang_tahun' => false,
        'tv_rotasi_panel_detik' => 20,
        'tv_kecepatan_scroll' => 'cepat',
    ])->assertOk()->assertJsonPath('data.tv_interval_detik', 60);

    expect((int) app(PengaturanService::class)->ambil('tv_masa_berlaku_hari'))->toBe(14)
        ->and((bool) app(PengaturanService::class)->ambil('tv_tampilkan_alasan_izin'))->toBeTrue();
});

it('FR-TV-16 interval refresh di luar 10–120 detik ditolak', function (): void {
    $admin = sebagaiAdmin();

    test()->actingAs($admin)->putJson('/api/v1/pengaturan/tv', [
        'tv_aktif' => true,
        'tv_izinkan_npsn' => true,
        'tv_interval_detik' => 5,
        'tv_masa_berlaku_hari' => 30,
        'tv_tema' => 'gelap',
        'tv_skala_font' => 'besar',
        'tv_tampilkan_alasan_izin' => false,
        'tv_tampilkan_ulang_tahun' => true,
        'tv_rotasi_panel_detik' => 10,
        'tv_kecepatan_scroll' => 'normal',
    ])->assertStatus(422)->assertJsonValidationErrors('tv_interval_detik');
});

it('FR-TV-16 daftar sesi aktif memuat nama perangkat, IP, dan terakhir aktif', function (): void {
    $admin = sebagaiAdmin();
    app(TvSesiService::class)->terbitkan('TV Ruang Guru', '10.0.0.9');

    $data = test()->actingAs($admin)->getJson('/api/v1/pengaturan/tv/sesi')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['nama_perangkat'])->toBe('TV Ruang Guru')
        ->and($data[0]['ip'])->toBe('10.0.0.9')
        ->and($data[0]['terakhir_aktif_at'])->not->toBeNull();
});

it('FR-TV-16 tombol cabut mencabut satu sesi', function (): void {
    $admin = sebagaiAdmin();
    $terbit = app(TvSesiService::class)->terbitkan('TV Lama', '10.0.0.5');

    test()->withToken($terbit['token'])->getJson('/api/v1/tv/rekap')->assertOk();

    test()->actingAs($admin)
        ->deleteJson('/api/v1/pengaturan/tv/sesi/'.$terbit['sesi']->id)
        ->assertOk();

    test()->withToken($terbit['token'])->getJson('/api/v1/tv/rekap')->assertStatus(401);
    expect(app(TvSesiService::class)->sesiAktif())->toHaveCount(0);
});

it('BR-35 buat ulang kode mencabut seluruh sesi dan mengganti kode', function (): void {
    $admin = sebagaiAdmin();
    $lama = app(TvSesiService::class)->kode();
    $terbit = app(TvSesiService::class)->terbitkan('TV A', '10.0.0.1');

    $respon = test()->actingAs($admin)
        ->postJson('/api/v1/pengaturan/tv/kode/buat-ulang')
        ->assertOk()
        ->json('data');

    expect($respon['kode'])->not->toBe($lama)
        ->and(strlen($respon['kode']))->toBe(8)
        ->and($respon['sesi_dicabut'])->toBe(1)
        ->and($respon['sisa_sesi'])->toBe(0);

    test()->withToken($terbit['token'])->getJson('/api/v1/tv/rekap')->assertStatus(401);
});

it('FR-TV-16 pratinjau admin memakai layanan rekap yang sama', function (): void {
    $admin = sebagaiAdmin();

    $data = test()->actingAs($admin)->getJson('/api/v1/pengaturan/tv/pratinjau')->assertOk()->json('data');

    expect($data)->toHaveKeys(['server', 'presensi', 'jurnal', 'perizinan', 'pengumuman']);
});

it('hanya admin yang boleh melihat pengaturan TV', function (): void {
    siapkanAkademik();
    $guru = buatPegawaiDenganAkun();

    test()->actingAs($guru)->getJson('/api/v1/pengaturan/tv')->assertStatus(403);
    test()->actingAs($guru)->postJson('/api/v1/pengaturan/tv/kode/buat-ulang')->assertStatus(403);
});
