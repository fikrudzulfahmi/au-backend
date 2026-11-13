<?php

declare(strict_types=1);

use App\Models\JadwalWfa;
use App\Models\Pegawai;
use App\Models\PresensiPegawai;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    siapkanPeran();
    Storage::fake('local');
});

/** FR-WFA — WFA Penuh TIDAK membebaskan presensi: presensi biasa tetap diterima. */
it('menerima presensi biasa saat pegawai dijadwalkan WFA Penuh hari ini', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    // Buat jadwal WFA Penuh untuk Senin (hari = 1)
    JadwalWfa::create([
        'pegawai_id' => $p['pegawai']->id,
        'hari' => 1,
        'jenis' => JadwalWfa::JENIS_PENUH,
        'jam_masuk' => null,
        'keterangan' => 'Riset kurikulum di rumah',
    ]);

    $respons = kirimPresensi($p['user']);

    $respons->assertCreated()
        ->assertJsonPath('data.masuk.status', PresensiPegawai::STATUS_HADIR);

    expect(PresensiPegawai::count())->toBe(1);
});

/** FR-WFA — status hari ini tetap membolehkan masuk walau dijadwalkan WFA Penuh. */
it('mengembalikan boleh_masuk true walau pegawai dijadwalkan WFA Penuh', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:00:00'));
    $p = siapkanPresensi();

    JadwalWfa::create([
        'pegawai_id' => $p['pegawai']->id,
        'hari' => 1,
        'jenis' => JadwalWfa::JENIS_PENUH,
        'jam_masuk' => null,
        'keterangan' => 'WFA Penuh',
    ]);

    $respons = $this->actingAs($p['user'])->get('/api/v1/presensi/hari-ini');

    $respons->assertOk()
        ->assertJsonPath('data.boleh_masuk', true)
        ->assertJsonPath('data.wfa.jenis', 'penuh');
});

/** FR-WFA — WFA Sebagian menggeser batas masuk (guru masuk 08:30 tidak terlambat). */
it('memakai jam masuk WFA sebagian sehingga presensi 08:15 berstatus hadir', function (): void {
    // Jam kerja standar guru Senin adalah masuk 07:00.
    // Pegawai ini punya WFA Sebagian jam masuk 08:30.
    $this->travelTo(CarbonImmutable::parse(seninUji().' 08:15:00'));
    $p = siapkanPresensi();

    JadwalWfa::create([
        'pegawai_id' => $p['pegawai']->id,
        'hari' => 1,
        'jenis' => JadwalWfa::JENIS_SEBAGIAN,
        'jam_masuk' => '08:30:00',
        'keterangan' => 'Mengajar mulai jam ke-3',
    ]);

    $respons = kirimPresensi($p['user']);

    $respons->assertCreated()
        ->assertJsonPath('data.masuk.status', PresensiPegawai::STATUS_HADIR)
        ->assertJsonPath('data.masuk.menit_terlambat', 0);
});

/** FR-WFA — WFA Sebagian: datang melewati jam WFA tetap dihitung terlambat dari jam WFA. */
it('menghitung keterlambatan WFA sebagian dari jam masuk WFA bukan jam kerja standar', function (): void {
    // Datang 08:45, batas WFA 08:30 -> terlambat 15 menit (bukan 105 menit dari 07:00).
    $this->travelTo(CarbonImmutable::parse(seninUji().' 08:45:00'));
    $p = siapkanPresensi();

    JadwalWfa::create([
        'pegawai_id' => $p['pegawai']->id,
        'hari' => 1,
        'jenis' => JadwalWfa::JENIS_SEBAGIAN,
        'jam_masuk' => '08:30:00',
        'keterangan' => 'Jadwal jam ke-3',
    ]);

    $respons = kirimPresensi($p['user']);

    $respons->assertCreated()
        ->assertJsonPath('data.masuk.status', PresensiPegawai::STATUS_TERLAMBAT)
        ->assertJsonPath('data.masuk.menit_terlambat', 15);
});

/** FR-WFA — admin dapat menyimpan jadwal WFA sepekan per pegawai. */
it('mengizinkan admin mengelola jadwal WFA satu pegawai', function (): void {
    $admin = buatPegawaiDenganAkun(Pegawai::JENIS_GURU, [], [Role::ADMIN]);
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $respons = $this->actingAs($admin)->postJson('/api/v1/jadwal-wfa', [
        'pegawai_id' => $guru->pegawai_id,
        'per_hari' => [
            1 => [
                'ada_wfa' => true,
                'jenis' => 'sebagian',
                'jam_masuk' => '08:30',
                'keterangan' => 'Jam ke-3',
            ],
            2 => [
                'ada_wfa' => true,
                'jenis' => 'penuh',
                'jam_masuk' => null,
                'keterangan' => 'Hari riset',
            ],
            3 => ['ada_wfa' => false],
            4 => ['ada_wfa' => false],
            5 => ['ada_wfa' => false],
            6 => ['ada_wfa' => false],
            7 => ['ada_wfa' => false],
        ],
    ]);

    $respons->assertOk();
    expect(JadwalWfa::where('pegawai_id', $guru->pegawai_id)->count())->toBe(2);
});
