<?php

declare(strict_types=1);

use App\Models\LokasiPresensi;
use App\Models\PengajuanIzin;
use App\Models\PengajuanLuarRadius;
use App\Models\PresensiPegawai;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Perbaikan alur presensi (laporan sekolah, Oktober 2026)
|--------------------------------------------------------------------------
| Empat keluhan yang diuji di sini:
| 1. Radius tidak bisa 0 padahal zona memakai poligon → poligon tidak tersimpan.
| 2. Presensi yang menunggu di sisi pulang tidak bisa diputuskan dari halaman
|    persetujuan karena klien tidak mengirim `sisi`.
| 3. Tidak ada opsi hapus pengajuan untuk admin/kepala sekolah.
*/

beforeEach(function (): void {
    siapkanPeran();
    Storage::fake('local');
});

it('menyimpan lokasi dengan radius 0 bila zona poligon terisi', function (): void {
    $admin = sebagaiAdmin();

    $respons = test()->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi', [
        'nama' => 'Gedung Poligon',
        'latitude' => -7.8654000,
        'longitude' => 111.4650000,
        'radius_m' => 0,
        'poligon' => [
            [-7.8654000, 111.4640000],
            [-7.8644000, 111.4640000],
            [-7.8644000, 111.4660000],
            [-7.8654000, 111.4660000],
        ],
        'is_active' => true,
    ]);

    $respons->assertCreated();

    $lokasi = LokasiPresensi::query()->where('nama', 'Gedung Poligon')->firstOrFail();

    expect($lokasi->radius_m)->toBe(0)
        ->and($lokasi->poligon)->toHaveCount(4);
});

it('menolak radius 0 bila tidak ada poligon sama sekali', function (): void {
    $admin = sebagaiAdmin();

    $respons = test()->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi', [
        'nama' => 'Tanpa Batas',
        'latitude' => -7.8654000,
        'longitude' => 111.4650000,
        'radius_m' => 0,
        'is_active' => true,
    ]);

    $respons->assertStatus(422)->assertJsonValidationErrors('radius_m');
    expect(LokasiPresensi::query()->count())->toBe(0);
});

it('memutuskan presensi yang menunggu di sisi pulang tanpa parameter sisi', function (): void {
    test()->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));

    $p = siapkanPresensi();

    // Masuk dari dalam radius → sah tanpa antrean.
    kirimPresensi($p['user'])->assertCreated();

    // Pulang dari luar radius dengan alasan → menunggu keputusan (BR-17 Jalur B).
    test()->travelTo(CarbonImmutable::parse(seninUji().' 15:05:00'));

    kirimPulang($p['user'], [
        'lat' => -7.8700000,
        'lng' => 111.4700000,
        'alasan' => 'Mendampingi siswa lomba di luar sekolah.',
    ])->assertOk();

    $presensi = PresensiPegawai::query()->firstOrFail();
    expect($presensi->pulang_validasi)->toBe(PresensiPegawai::MENUNGGU);

    // Halaman Persetujuan tidak mengirim `sisi`; server harus memilih sisi yang
    // benar-benar menunggu, bukan menolak dengan "tidak sedang menunggu keputusan".
    $respons = test()->actingAs(sebagaiAdmin())
        ->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/putuskan", [
            'keputusan' => PresensiPegawai::DISETUJUI,
        ]);

    $respons->assertOk()->assertJsonPath('data.pulang.validasi', PresensiPegawai::DISETUJUI);

    expect($presensi->refresh()->pulang_validasi)->toBe(PresensiPegawai::DISETUJUI)
        ->and($presensi->masuk_validasi)->toBe(PresensiPegawai::VALID);
});

it('mengirim jam dan sisi yang menunggu pada antrean persetujuan', function (): void {
    test()->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));

    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    test()->travelTo(CarbonImmutable::parse(seninUji().' 15:05:00'));
    kirimPulang($p['user'], [
        'lat' => -7.8700000,
        'lng' => 111.4700000,
        'alasan' => 'Mendampingi siswa lomba di luar sekolah.',
    ])->assertOk();

    $presensi = PresensiPegawai::query()->firstOrFail();

    $respons = test()->actingAs(sebagaiAdmin())->getJson('/api/v1/monitoring/persetujuan-presensi');

    $respons->assertOk()
        ->assertJsonPath('data.0.pulang_jam', $presensi->pulang_waktu?->format('H:i'))
        ->assertJsonPath('data.0.sisi_menunggu', ['pulang']);
});

it('menghapus pengajuan izin yang masih menunggu', function (): void {
    $p = siapkanPresensi();

    $izin = PengajuanIzin::factory()->create([
        'pegawai_id' => $p['pegawai']->id,
        'status' => PengajuanIzin::STATUS_MENUNGGU,
    ]);

    test()->actingAs(sebagaiAdmin())
        ->deleteJson("/api/v1/pengajuan-izin/{$izin->id}")
        ->assertOk();

    expect(PengajuanIzin::query()->find($izin->id))->toBeNull();
});

it('menolak menghapus pengajuan izin yang sudah disetujui', function (): void {
    $p = siapkanPresensi();

    $izin = PengajuanIzin::factory()->disetujui()->create([
        'pegawai_id' => $p['pegawai']->id,
    ]);

    $respons = test()->actingAs(sebagaiAdmin())
        ->deleteJson("/api/v1/pengajuan-izin/{$izin->id}");

    $respons->assertStatus(422);
    expect(PengajuanIzin::query()->find($izin->id))->not->toBeNull();
});

it('menghapus pengajuan luar radius yang belum dipakai presensi', function (): void {
    $p = siapkanPresensi();

    $luar = PengajuanLuarRadius::factory()->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal' => seninUji(),
    ]);

    test()->actingAs(sebagaiAdmin())
        ->deleteJson("/api/v1/pengajuan-luar-radius/{$luar->id}")
        ->assertOk();

    expect(PengajuanLuarRadius::query()->find($luar->id))->toBeNull();
});

it('menolak menghapus pengajuan luar radius yang sudah dipakai presensi', function (): void {
    test()->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));

    $p = siapkanPresensi();

    $luar = PengajuanLuarRadius::factory()->disetujui()->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal' => seninUji(),
    ]);

    // Presensi sah yang merujuk pengajuan tersebut.
    kirimPresensi($p['user'], ['lat' => -7.8654000, 'lng' => 111.4650000])->assertCreated();

    $presensi = PresensiPegawai::query()->firstOrFail();
    $presensi->update([
        'masuk_validasi' => PresensiPegawai::DISETUJUI,
        'masuk_lokasi_id' => null,
        'masuk_pengajuan_luar_radius_id' => $luar->id,
    ]);

    $respons = test()->actingAs(sebagaiAdmin())
        ->deleteJson("/api/v1/pengajuan-luar-radius/{$luar->id}");

    $respons->assertStatus(422);
    expect(PengajuanLuarRadius::query()->find($luar->id))->not->toBeNull();
});

it('melarang guru menghapus pengajuan', function (): void {
    $p = siapkanPresensi();

    $izin = PengajuanIzin::factory()->create([
        'pegawai_id' => $p['pegawai']->id,
        'status' => PengajuanIzin::STATUS_MENUNGGU,
    ]);

    test()->actingAs($p['user'])
        ->deleteJson("/api/v1/pengajuan-izin/{$izin->id}")
        ->assertForbidden();

    expect(PengajuanIzin::query()->find($izin->id))->not->toBeNull();
});
