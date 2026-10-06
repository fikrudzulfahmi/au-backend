<?php

declare(strict_types=1);

use App\Models\PresensiPegawai;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    siapkanPeran();
    Storage::fake('local');
});

/** KP-3.4 — presensi pulang tanpa presensi masuk ditolak. */
it('menolak presensi pulang tanpa presensi masuk (KP-3.4)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 15:00:00'));
    $p = siapkanPresensi();

    $respons = kirimPulang($p['user']);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BR-10');
    expect($respons->json('errors.foto.0'))->toContain('presensi masuk terlebih dahulu');
    expect(PresensiPegawai::count())->toBe(0);
});

/** KP-3.4 — pulang sebelum jam_pulang → pulang_cepat dengan menit tepat. */
it('menandai pulang cepat bila pulang sebelum jam pulang (KP-3.4/BR-16)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    // Jam pulang 15:00; pulang pukul 14:30 → 30 menit lebih awal.
    $this->travelTo(CarbonImmutable::parse(seninUji().' 14:30:00'));
    $respons = kirimPulang($p['user']);

    $respons->assertOk()->assertJsonPath('data.pulang.status', 'pulang_cepat');

    expect($respons->json('message'))->toContain('30 menit lebih awal');

    $presensi = PresensiPegawai::firstOrFail();
    expect($presensi->pulang_status)->toBe(PresensiPegawai::PULANG_CEPAT)
        ->and($presensi->pulang_menit_cepat)->toBe(30)
        ->and($presensi->sudahPulang())->toBeTrue();
});

it('menandai pulang normal bila tepat pada jam pulang (BR-16)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    $this->travelTo(CarbonImmutable::parse(seninUji().' 15:00:00'));
    kirimPulang($p['user'])->assertOk()->assertJsonPath('data.pulang.status', 'normal');

    expect(PresensiPegawai::firstOrFail()->pulang_menit_cepat)->toBe(0);
});

/** BR-10 — presensi pulang satu kali per hari. */
it('menolak presensi pulang kedua pada hari yang sama (BR-10)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    $this->travelTo(CarbonImmutable::parse(seninUji().' 15:00:00'));
    kirimPulang($p['user'])->assertOk();

    $kedua = kirimPulang($p['user']);
    $kedua->assertStatus(422);
    expect($kedua->json('code'))->toBe('BR-10');
});

/** FR-PRS-05 — sudah masuk tetapi belum pulang ditandai pada data. */
it('menandai belum presensi pulang pada akhir hari (FR-PRS-05)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    $presensi = PresensiPegawai::firstOrFail();

    expect($presensi->belumPulang())->toBeTrue();
    expect($presensi->sudahPulang())->toBeFalse();
});

/** KP-3.1 berlaku juga untuk presensi pulang. */
it('menolak presensi pulang tanpa foto atau GPS', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    $this->travelTo(CarbonImmutable::parse(seninUji().' 15:00:00'));

    $this->actingAs($p['user'])->post('/api/v1/presensi/pulang', ['lat' => -7.8654, 'lng' => 111.465])
        ->assertStatus(422);

    $this->actingAs($p['user'])->post('/api/v1/presensi/pulang', ['foto' => fotoUji()])
        ->assertStatus(422);

    expect(PresensiPegawai::firstOrFail()->sudahPulang())->toBeFalse();
});

/** FR-PRS-12 — riwayat presensi milik sendiri. */
it('menyajikan riwayat presensi milik sendiri (FR-PRS-12)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:05:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    $this->travelTo(CarbonImmutable::parse(seninUji().' 15:10:00'));
    kirimPulang($p['user'])->assertOk();

    $respons = $this->actingAs($p['user'])->get('/api/v1/presensi/riwayat');

    $respons->assertOk()->assertJsonPath('meta.total', 1);
    expect($respons->json('data.0.masuk.jam'))->toBe('07:05')
        ->and($respons->json('data.0.pulang.jam'))->toBe('15:10')
        ->and($respons->json('data.0.masuk.status'))->toBe('terlambat');
});

/** Foto presensi hanya boleh dilihat pemilik atau peran pemantau. */
it('membatasi akses foto presensi', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    $presensi = PresensiPegawai::firstOrFail();

    // Pemilik boleh.
    $this->actingAs($p['user'])->get("/api/v1/presensi/{$presensi->id}/foto/masuk")->assertOk();

    // Peran pemantau boleh.
    $this->actingAs(sebagaiAdmin())->get("/api/v1/presensi/{$presensi->id}/foto/masuk")->assertOk();

    // Pegawai lain tidak boleh.
    $lain = buatPegawaiDenganAkun();
    $this->actingAs($lain)->get("/api/v1/presensi/{$presensi->id}/foto/masuk")->assertStatus(403);
});

it('mengembalikan 404 untuk foto yang sudah dihapus karena retensi (BR-30)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();
    kirimPresensi($p['user'])->assertCreated();

    $presensi = PresensiPegawai::firstOrFail();
    Storage::disk('local')->delete($presensi->masuk_foto_path);

    $respons = $this->actingAs($p['user'])->get("/api/v1/presensi/{$presensi->id}/foto/masuk");

    $respons->assertStatus(404);
    expect($respons->json('message'))->toContain('retensi');
});
