<?php

declare(strict_types=1);

use App\Models\PresensiPegawai;
use App\Support\Poligon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Fase 6 — Validasi Presensi Berbasis Zona Poligon
|--------------------------------------------------------------------------
| Sekolah dapat memiliki batas area fisik berbentuk poligon (bukan lingkaran).
| Bila lokasi memiliki data poligon yang sah, penentuan sah-lokasi presensi
| menggunakan ray-casting poligon; bila tidak ada, tetap menggunakan radius Haversine.
*/

beforeEach(function (): void {
    siapkanPeran();
    Storage::fake('local');
});

it('menerima presensi saat koordinat berada di dalam poligon (FR-PRS/POLIGON)', function (): void {
    test()->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));

    $p = siapkanPresensi(radius: 200);

    // Poligon di sisi utara sekolah (-7.8654 s/d -7.8644).
    $poligon = [
        [-7.8654000, 111.4640000],
        [-7.8644000, 111.4640000],
        [-7.8644000, 111.4660000],
        [-7.8654000, 111.4660000],
    ];

    $p['lokasi']->update(['poligon' => $poligon]);

    // Koordinat di dalam poligon (sekitar 55m dari pusat).
    $respons = kirimPresensi($p['user'], [
        'lat' => -7.8649000,
        'lng' => 111.4650000,
    ]);

    $respons->assertCreated()->assertJsonPath('data.masuk.validasi', 'valid');

    $presensi = PresensiPegawai::query()->firstOrFail();
    expect($presensi->masuk_validasi)->toBe(PresensiPegawai::VALID)
        ->and($presensi->masuk_lokasi_id)->toBe($p['lokasi']->id);
});

it('menolak presensi di luar poligon meskipun masih di dalam radius lingkaran', function (): void {
    test()->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));

    $p = siapkanPresensi(radius: 200);

    // Poligon hanya melingkupi sisi utara sekolah (-7.8654 s/d -7.8644).
    $poligon = [
        [-7.8654000, 111.4640000],
        [-7.8644000, 111.4640000],
        [-7.8644000, 111.4660000],
        [-7.8654000, 111.4660000],
    ];

    $p['lokasi']->update(['poligon' => $poligon]);

    // Titik di sisi selatan (-7.8660000): jarak ~66m dari pusat (< 200m radius),
    // tetapi di luar batas poligon.
    $tanpaAlasan = kirimPresensi($p['user'], [
        'lat' => -7.8660000,
        'lng' => 111.4650000,
    ]);

    $tanpaAlasan->assertStatus(422);
    expect($tanpaAlasan->json('code'))->toBe('ALASAN-WAJIB');
    expect(PresensiPegawai::query()->count())->toBe(0);

    // Bila menyertakan alasan luar radius, presensi masuk jalur menunggu (BR-17).
    $denganAlasan = kirimPresensi($p['user'], [
        'lat' => -7.8660000,
        'lng' => 111.4650000,
        'alasan' => 'Kegiatan ekstrakurikuler di lapangan luar.',
    ]);

    $denganAlasan->assertCreated()->assertJsonPath('data.masuk.validasi', 'menunggu');
});

it('tetap menggunakan radius Haversine bila lokasi presensi tidak memiliki poligon', function (): void {
    test()->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));

    $p = siapkanPresensi(radius: 200);

    // Memastikan lokasi tidak memiliki poligon.
    $p['lokasi']->update(['poligon' => null]);

    // Titik di sisi selatan (-7.8660000, jarak ~66m dari pusat): masih dalam radius 200m.
    $respons = kirimPresensi($p['user'], [
        'lat' => -7.8660000,
        'lng' => 111.4650000,
    ]);

    $respons->assertCreated()->assertJsonPath('data.masuk.validasi', 'valid');

    $presensi = PresensiPegawai::query()->firstOrFail();
    expect($presensi->masuk_validasi)->toBe(PresensiPegawai::VALID)
        ->and($presensi->masuk_lokasi_id)->toBe($p['lokasi']->id);
});

it('Poligon::memuat mengevaluasi poligon persegi dan titik di dalam, di luar, serta di tepi', function (): void {
    $persegi = [
        [-7.0, 110.0],
        [-7.0, 110.1],
        [-6.9, 110.1],
        [-6.9, 110.0],
    ];

    // Titik di dalam poligon.
    expect(Poligon::memuat($persegi, -6.95, 110.05))->toBeTrue();

    // Titik di luar poligon (sebelah timur dan sebelah selatan).
    expect(Poligon::memuat($persegi, -6.95, 110.2))->toBeFalse()
        ->and(Poligon::memuat($persegi, -7.05, 110.05))->toBeFalse();

    // Titik tepat di tepi poligon dianggap di dalam.
    expect(Poligon::memuat($persegi, -7.0, 110.05))->toBeTrue()
        ->and(Poligon::memuat($persegi, -6.95, 110.1))->toBeTrue();

    // Titik di sudut poligon.
    expect(Poligon::memuat($persegi, -7.0, 110.0))->toBeTrue();
});

it('Poligon::sah memvalidasi keabsahan struktur dan rentang koordinat poligon', function (): void {
    // Kurang dari 3 titik.
    expect(Poligon::sah([[-7.0, 110.0], [-7.1, 110.1]]))->toBeFalse();

    // Koordinat latitude di luar rentang -90..90.
    expect(Poligon::sah([
        [-95.0, 110.0],
        [-7.0, 110.1],
        [-7.1, 110.1],
    ]))->toBeFalse();

    // Koordinat longitude di luar rentang -180..180.
    expect(Poligon::sah([
        [-7.0, 190.0],
        [-7.0, 110.1],
        [-7.1, 110.1],
    ]))->toBeFalse();

    // Bukan array numerik.
    expect(Poligon::sah([
        ['bukan', 'angka'],
        [-7.0, 110.1],
        [-7.1, 110.1],
    ]))->toBeFalse();

    // Format sah.
    expect(Poligon::sah([
        [-7.0, 110.0],
        [-7.0, 110.1],
        [-7.1, 110.1],
    ]))->toBeTrue();
});

it('admin dapat menyimpan dan memperbarui zona poligon melalui API lokasi presensi', function (): void {
    $admin = sebagaiAdmin();

    $poligon = [
        [-7.8654, 111.4640],
        [-7.8644, 111.4640],
        [-7.8644, 111.4660],
        [-7.8654, 111.4660],
    ];

    // Simpan lokasi baru dengan poligon.
    $simpan = test()->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi', [
        'nama' => 'Kampus 2 Poligon',
        'latitude' => -7.8654,
        'longitude' => 111.4650,
        'radius_m' => 150,
        'poligon' => $poligon,
    ]);

    $simpan->assertCreated()
        ->assertJsonPath('data.poligon.0.0', -7.8654)
        ->assertJsonPath('data.poligon.2.1', 111.4660);

    $id = $simpan->json('data.id');

    // Validasi penolakan poligon kurang dari 3 titik.
    $gagal = test()->actingAs($admin)->putJson("/api/v1/pengaturan/lokasi/{$id}", [
        'nama' => 'Kampus 2 Poligon',
        'latitude' => -7.8654,
        'longitude' => 111.4650,
        'radius_m' => 150,
        'poligon' => [[-7.8654, 111.4640], [-7.8644, 111.4640]],
    ]);

    $gagal->assertStatus(422)
        ->assertJsonValidationErrors('poligon');

    // Menghapus poligon (kembali ke lingkaran radius).
    $update = test()->actingAs($admin)->putJson("/api/v1/pengaturan/lokasi/{$id}", [
        'nama' => 'Kampus 2 Poligon',
        'latitude' => -7.8654,
        'longitude' => 111.4650,
        'radius_m' => 150,
        'poligon' => null,
    ]);

    $update->assertOk()->assertJsonPath('data.poligon', null);
});
