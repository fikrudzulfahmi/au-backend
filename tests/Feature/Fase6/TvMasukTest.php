<?php

declare(strict_types=1);

use App\Models\SesiTv;
use App\Models\User;
use App\Services\PengaturanService;
use App\Services\TvSesiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Fase 6 — Masuk Layar TV & token TV (5.19, BR-32, BR-35)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    siapkanPeran();
    Cache::flush();
});

function tvKode(): string
{
    return app(TvSesiService::class)->kode();
}

/** @param array<string, mixed> $muatan */
function tvMasuk(array $muatan = []): TestResponse
{
    return test()->postJson('/api/v1/tv/masuk', array_merge(['kode' => tvKode()], $muatan));
}

function tvToken(array $muatan = []): string
{
    return (string) tvMasuk($muatan)->assertOk()->json('data.token');
}

it('FR-TV-02/03 kode TV benar menerbitkan token read-only 30 hari', function (): void {
    $sekolah = profilSekolah();

    $respon = tvMasuk(['nama_perangkat' => 'TV Ruang Guru'])->assertOk();

    expect($respon->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($respon->json('data.tampilan.tema'))->toBeString()
        ->and($respon->json('data.sekolah.nama_sekolah'))->toBe($sekolah->nama_sekolah);

    $sesi = SesiTv::query()->firstOrFail();
    expect($sesi->nama_perangkat)->toBe('TV Ruang Guru')
        ->and($sesi->kedaluwarsa_at->greaterThan(CarbonImmutable::now()->addDays(29)))->toBeTrue()
        ->and($sesi->token_hash)->not->toBe($respon->json('data.token'));
});

it('FR-TV-02 NPSN diterima saat opsi izinkan NPSN aktif', function (): void {
    profilSekolah(['npsn' => '20512345']);

    test()->postJson('/api/v1/tv/masuk', ['npsn' => '20512345'])->assertOk();

    app(PengaturanService::class)->simpan('tv_izinkan_npsn', false);
    test()->postJson('/api/v1/tv/masuk', ['npsn' => '20512345'])->assertStatus(401);
});

it('BR-32 mengunci kode salah setelah 5 percobaan per menit per IP', function (): void {
    for ($i = 0; $i < 5; $i++) {
        test()->postJson('/api/v1/tv/masuk', ['kode' => 'SALAHXXX'])->assertStatus(401);
    }

    test()->postJson('/api/v1/tv/masuk', ['kode' => 'SALAHXXX'])->assertStatus(429);
    // Kode benar pun tetap terkunci selama jendela satu menit berjalan.
    test()->postJson('/api/v1/tv/masuk', ['kode' => tvKode()])->assertStatus(429);
});

it('BR-32 kode benar menghapus penghitung percobaan salah', function (): void {
    for ($i = 0; $i < 4; $i++) {
        test()->postJson('/api/v1/tv/masuk', ['kode' => 'SALAHXXX'])->assertStatus(401);
    }

    test()->postJson('/api/v1/tv/masuk', ['kode' => tvKode()])->assertOk();
    test()->postJson('/api/v1/tv/masuk', ['kode' => 'SALAHXXX'])->assertStatus(401);
});

it('BR-32 token TV hanya berlaku pada endpoint tv', function (): void {
    $token = tvToken();

    // Arah 1: token TV diterima di endpoint tv.
    test()->withToken($token)->getJson('/api/v1/tv/rekap')->assertOk();

    // Arah 1: token TV DITOLAK di endpoint API lain.
    test()->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    test()->withToken($token)->getJson('/api/v1/pengumuman')->assertStatus(401);
});

it('BR-32 token Sanctum biasa ditolak pada endpoint tv', function (): void {
    $admin = sebagaiAdmin();
    /** @var User $admin */
    $sanctum = $admin->createToken('uji')->plainTextToken;

    test()->withToken($sanctum)->getJson('/api/v1/tv/rekap')->assertStatus(401);
});

it('BR-35 membuat ulang kode TV mencabut semua sesi TV', function (): void {
    $tokenA = tvToken();
    $tokenB = tvToken();

    app(TvSesiService::class)->buatUlangKode();

    test()->withToken($tokenA)->getJson('/api/v1/tv/rekap')->assertStatus(401);
    test()->withToken($tokenB)->getJson('/api/v1/tv/rekap')->assertStatus(401);
    expect(app(TvSesiService::class)->sesiAktif())->toHaveCount(0);
});

it('BR-35 token yang sudah dicabut ditolak', function (): void {
    $token = tvToken();

    test()->withToken($token)->getJson('/api/v1/tv/rekap')->assertOk();

    app(TvSesiService::class)->cabutSemua();

    test()->withToken($token)->getJson('/api/v1/tv/rekap')->assertStatus(401);
});

it('BR-32 TV nonaktif menolak semua akses', function (): void {
    $token = tvToken();

    app(PengaturanService::class)->simpan('tv_aktif', false);

    test()->postJson('/api/v1/tv/masuk', ['kode' => tvKode()])->assertStatus(403);
    test()->withToken($token)->getJson('/api/v1/tv/rekap')->assertStatus(403);
});

it('FR-TV-03 token TV kedaluwarsa ditolak', function (): void {
    $token = tvToken();

    $this->travelTo(CarbonImmutable::now()->addDays(31));

    test()->withToken($token)->getJson('/api/v1/tv/rekap')->assertStatus(401);
});

it('FR-TV-02 kode TV berupa 8 karakter dari alfabet aman', function (): void {
    $kode = tvKode();

    expect(strlen($kode))->toBe(8)
        ->and($kode)->toMatch('/^[A-HJ-NP-Z2-9]{8}$/');
});
