<?php

declare(strict_types=1);

use App\Models\HariLibur;
use App\Models\JamKerja;
use App\Models\LokasiPresensi;
use App\Models\Pegawai;
use App\Models\PegawaiLokasi;
use App\Services\JamKerjaService;
use App\Services\LokasiPresensiService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    siapkanPeran();
});

/** FR-LOK-01 — membuat lokasi presensi dengan radius yang dapat diatur (FR-LOK-06). */
it('membuat lokasi presensi melalui API (FR-LOK-01)', function (): void {
    $admin = sebagaiAdmin();

    $respons = $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi', [
        'nama' => 'Gedung Utama',
        'latitude' => -7.8654000,
        'longitude' => 111.4650000,
        'radius_m' => 250,
        'is_default' => true,
    ]);

    $respons->assertCreated()->assertJsonPath('data.radius_m', 250);

    expect(LokasiPresensi::count())->toBe(1)
        ->and(LokasiPresensi::firstOrFail()->is_default)->toBeTrue();
});

it('menolak radius di luar rentang wajar', function (): void {
    $admin = sebagaiAdmin();

    foreach ([5, 20000] as $radius) {
        $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi', [
            'nama' => 'Lokasi', 'latitude' => -7.86, 'longitude' => 111.46, 'radius_m' => $radius,
        ])->assertStatus(422);
    }
});

/** BR-12 — hanya satu lokasi default, ditegakkan database dan layanan. */
it('menjaga hanya satu lokasi default (BR-12)', function (): void {
    $admin = sebagaiAdmin();

    $pertama = LokasiPresensi::factory()->default()->create(['nama' => 'Lokasi A']);
    $kedua = LokasiPresensi::factory()->create(['nama' => 'Lokasi B']);

    // Menjadikan B sebagai default harus melepas A, bukan gagal.
    $this->actingAs($admin)->patch("/api/v1/pengaturan/lokasi/{$kedua->id}/default")
        ->assertOk();

    expect(LokasiPresensi::where('is_default', true)->count())->toBe(1)
        ->and($pertama->refresh()->is_default)->toBeFalse()
        ->and($kedua->refresh()->is_default)->toBeTrue();
});

it('memindahkan default lewat penyimpanan lokasi baru yang bertanda default (BR-12)', function (): void {
    $admin = sebagaiAdmin();
    $lama = LokasiPresensi::factory()->default()->create(['nama' => 'Lokasi Lama']);

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi', [
        'nama' => 'Lokasi Baru',
        'latitude' => -7.87, 'longitude' => 111.47, 'radius_m' => 120,
        'is_default' => true,
    ])->assertCreated();

    expect(LokasiPresensi::where('is_default', true)->count())->toBe(1)
        ->and($lama->refresh()->is_default)->toBeFalse();
});

/** FR-LOK-03 — penetapan massal lokasi ke banyak pegawai. */
it('menetapkan lokasi ke banyak pegawai sekaligus (FR-LOK-03)', function (): void {
    $admin = sebagaiAdmin();
    $lokasi = LokasiPresensi::factory()->default()->create();

    $a = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $b = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_STRUKTURAL]);

    $respons = $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi/tetapkan', [
        'pegawai_ids' => [$a->id, $b->id],
        'lokasi_ids' => [$lokasi->id],
    ]);

    $respons->assertOk();
    expect(PegawaiLokasi::count())->toBe(2);
});

it('mengganti penetapan sebelumnya, bukan menumpuknya (FR-LOK-03)', function (): void {
    $admin = sebagaiAdmin();
    $satu = LokasiPresensi::factory()->default()->create();
    $dua = LokasiPresensi::factory()->create();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi/tetapkan', [
        'pegawai_ids' => [$pegawai->id], 'lokasi_ids' => [$satu->id],
    ])->assertOk();

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi/tetapkan', [
        'pegawai_ids' => [$pegawai->id], 'lokasi_ids' => [$dua->id],
    ])->assertOk();

    expect(PegawaiLokasi::where('pegawai_id', $pegawai->id)->pluck('lokasi_id')->all())->toBe([$dua->id]);
});

/** BR-12 — mengosongkan centang mengembalikan pegawai ke lokasi default. */
it('mengembalikan pegawai ke lokasi default saat penetapan dikosongkan (BR-12)', function (): void {
    $admin = sebagaiAdmin();
    $default = LokasiPresensi::factory()->default()->create();
    $lain = LokasiPresensi::factory()->create();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi/tetapkan', [
        'pegawai_ids' => [$pegawai->id], 'lokasi_ids' => [$lain->id],
    ])->assertOk();

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/lokasi/tetapkan', [
        'pegawai_ids' => [$pegawai->id], 'lokasi_ids' => [],
    ])->assertOk();

    $layanan = app(LokasiPresensiService::class);
    $efektif = $layanan->lokasiEfektif($pegawai->refresh());

    expect($efektif)->toHaveCount(1)
        ->and($efektif->first()->id)->toBe($default->id);
});

/** FR-LOK-04 — menyimpan jam kerja sepekan. */
it('menyimpan jam kerja sepekan untuk satu jenis pegawai (FR-LOK-04)', function (): void {
    $admin = sebagaiAdmin();

    $perHari = [];
    foreach (range(1, 7) as $hari) {
        $kerja = $hari <= 5;
        $perHari[$hari] = [
            'is_hari_kerja' => $kerja,
            'buka_presensi' => $kerja ? '06:30' : null,
            'jam_masuk' => $kerja ? '07:00' : null,
            'jam_pulang' => $kerja ? '15:00' : null,
        ];
    }

    $respons = $this->actingAs($admin)->postJson('/api/v1/jam-kerja', [
        'jenis_pegawai' => JamKerja::JENIS_GURU,
        'per_hari' => $perHari,
    ]);

    $respons->assertOk();
    expect(JamKerja::count())->toBe(7)
        ->and(JamKerja::untuk(JamKerja::JENIS_GURU, 1)?->jam_masuk)->toBe('07:00:00')
        ->and(JamKerja::untuk(JamKerja::JENIS_GURU, 6)?->is_hari_kerja)->toBeFalse();
});

it('menolak hari kerja tanpa jam masuk atau dengan jam pulang mendahului (FR-LOK-04)', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/jam-kerja', [
        'jenis_pegawai' => JamKerja::JENIS_GURU,
        'per_hari' => [1 => ['is_hari_kerja' => true, 'jam_masuk' => null, 'jam_pulang' => '15:00']],
    ])->assertStatus(422)->assertJsonPath('errors.jam_masuk.0', 'Jam masuk wajib diisi pada hari kerja.');

    $this->actingAs($admin)->postJson('/api/v1/jam-kerja', [
        'jenis_pegawai' => JamKerja::JENIS_GURU,
        'per_hari' => [1 => ['is_hari_kerja' => true, 'jam_masuk' => '15:00', 'jam_pulang' => '07:00']],
    ])->assertStatus(422)->assertJsonPath('errors.jam_pulang.0', 'Jam pulang harus setelah jam masuk.');
});

/** BR-24 — hari libur membuat hari kerja menjadi tidak wajib presensi. */
it('menghitung hari kerja dengan mengecualikan hari libur (BR-24)', function (): void {
    $tahun = tahunAktif();
    app(JamKerjaService::class)->simpanMassal(JamKerja::JENIS_GURU, [
        1 => ['is_hari_kerja' => true, 'jam_masuk' => '07:00', 'jam_pulang' => '15:00'],
    ]);

    HariLibur::create([
        'tahun_pelajaran_id' => $tahun->id,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
        'keterangan' => 'Cuti bersama',
    ]);

    $layanan = app(JamKerjaService::class);

    expect($layanan->hariKerja(JamKerja::JENIS_GURU, CarbonImmutable::parse(seninUji())))->toBeFalse()
        ->and($layanan->hariKerja(JamKerja::JENIS_GURU, CarbonImmutable::parse(seninUji())->addWeek()))->toBeTrue();
});

/** Bagian 2 — pengaturan lokasi & jam kerja hanya admin. */
it('membatasi pengaturan lokasi dan jam kerja hanya untuk admin', function (): void {
    foreach ([sebagaiKepsek(), sebagaiWakasek(), buatPegawaiDenganAkun()] as $bukanAdmin) {
        $this->actingAs($bukanAdmin)->getJson('/api/v1/pengaturan/lokasi')->assertStatus(403);
        $this->actingAs($bukanAdmin)->postJson('/api/v1/pengaturan/lokasi', [
            'nama' => 'X', 'latitude' => -7.86, 'longitude' => 111.46, 'radius_m' => 100,
        ])->assertStatus(403);
        $this->actingAs($bukanAdmin)->getJson('/api/v1/jam-kerja')->assertStatus(403);
    }

    $this->actingAs(sebagaiAdmin())->getJson('/api/v1/pengaturan/lokasi')->assertOk();
    $this->actingAs(sebagaiAdmin())->getJson('/api/v1/jam-kerja')->assertOk();
});
