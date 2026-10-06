<?php

declare(strict_types=1);

use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PresensiPegawai;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    siapkanPeran();
    Storage::fake('local');
});

/** FR-PRS-10 — kategori status setiap pegawai pada monitoring harian. */
it('mengelompokkan status presensi harian dengan benar (FR-PRS-10)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 16:00:00'));

    $p = siapkanPresensi();

    // 1) Hadir lengkap (masuk & pulang tepat waktu).
    $hadir = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    PresensiPegawai::factory()->create([
        'pegawai_id' => $hadir->pegawai_id,
        'tanggal' => seninUji(),
        'pulang_waktu' => seninUji().' 15:05:00',
        'pulang_status' => PresensiPegawai::PULANG_NORMAL,
        'pulang_validasi' => PresensiPegawai::VALID,
    ]);
    $hadir->pegawai->lokasi()->attach($p['lokasi']->id);

    // 2) Belum presensi.
    $belum = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    $belum->pegawai->lokasi()->attach($p['lokasi']->id);

    // 3) Terlambat, belum pulang.
    $terlambat = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    PresensiPegawai::factory()->terlambat(25)->create([
        'pegawai_id' => $terlambat->pegawai_id,
        'tanggal' => seninUji(),
        'masuk_lokasi_id' => $p['lokasi']->id,
    ]);
    $terlambat->pegawai->lokasi()->attach($p['lokasi']->id);

    // 4) Menunggu persetujuan (di luar radius, tanpa pengajuan).
    $menunggu = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    PresensiPegawai::factory()->menunggu()->create([
        'pegawai_id' => $menunggu->pegawai_id,
        'tanggal' => seninUji(),
    ]);

    // 5) Izin disetujui → berhalangan.
    $izin = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    PengajuanIzin::factory()->disetujui()->create([
        'pegawai_id' => $izin->pegawai_id,
        'jenis' => PengajuanIzin::JENIS_SAKIT,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    $respons = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/monitoring/presensi-harian?tanggal='.seninUji());

    $respons->assertOk();

    $perPegawai = collect($respons->json('data.baris'))->keyBy('pegawai_id');

    expect($perPegawai[$hadir->pegawai_id]['status'])->toBe('hadir')
        ->and($perPegawai[$belum->pegawai_id]['status'])->toBe('belum_presensi')
        ->and($perPegawai[$terlambat->pegawai_id]['status'])->toBe('terlambat')
        ->and($perPegawai[$terlambat->pegawai_id]['menit_terlambat'])->toBe(25)
        ->and($perPegawai[$menunggu->pegawai_id]['status'])->toBe('menunggu')
        ->and($perPegawai[$izin->pegawai_id]['status'])->toBe('berhalangan')
        ->and($perPegawai[$izin->pegawai_id]['pengajuan_jenis'])->toBe('sakit');
});

it('menandai pegawai yang belum presensi pulang (FR-PRS-05/FR-PRS-10)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 16:00:00'));

    $p = siapkanPresensi();
    PresensiPegawai::factory()->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal' => seninUji(),
        'masuk_lokasi_id' => $p['lokasi']->id,
        'pulang_waktu' => null,
    ]);

    $respons = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/monitoring/presensi-harian?tanggal='.seninUji());

    $baris = collect($respons->json('data.baris'))->firstWhere('pegawai_id', $p['pegawai']->id);

    expect($baris['status'])->toBe('tidak_presensi_pulang');
});

it('menyaring monitoring harian menurut status', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 16:00:00'));

    $p = siapkanPresensi();
    buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $respons = $this->actingAs(sebagaiAdmin())
        ->getJson('/api/v1/monitoring/presensi-harian?tanggal='.seninUji().'&status=belum_presensi');

    $respons->assertOk();
    expect(collect($respons->json('data.baris'))->every(fn (array $b): bool => $b['status'] === 'belum_presensi'))->toBeTrue();
});

/** FR-PRS-11 — antrean & keputusan presensi luar radius. */
it('menyetujui presensi luar radius dari antrean (FR-PRS-11)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 07:30:00'));

    $p = siapkanPresensi();

    // Dibuat seperti keadaan setelah presensi dikirim pukul 07:30 (jam masuk 07:00):
    // status sudah terhitung terlambat 30 menit SEBELUM keputusan admin.
    $presensi = PresensiPegawai::factory()->menunggu()->terlambat(30)->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal' => seninUji(),
        'masuk_waktu' => seninUji().' 07:30:00',
    ]);

    expect($presensi->masuk_status)->toBe(PresensiPegawai::STATUS_TERLAMBAT);

    $antrean = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/monitoring/persetujuan-presensi');
    $antrean->assertOk();
    expect(collect($antrean->json('data'))->pluck('presensi_id'))->toContain($presensi->id);

    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/putuskan", [
        'keputusan' => 'disetujui',
    ])->assertOk()->assertJsonPath('data.masuk.validasi', 'disetujui');

    $presensi->refresh();

    // BR-18 — menyetujui TIDAK mengubah status maupun menit terlambat: keduanya tetap
    // seperti saat presensi dikirim, bukan dihitung ulang dari waktu keputusan.
    expect($presensi->masuk_validasi)->toBe(PresensiPegawai::DISETUJUI)
        ->and($presensi->dihitungHadir())->toBeTrue()
        ->and($presensi->masuk_status)->toBe(PresensiPegawai::STATUS_TERLAMBAT)
        ->and($presensi->masuk_menit_terlambat)->toBe(30);
});

it('menolak presensi luar radius dan menyimpannya sebagai ditolak (FR-PRS-11)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 08:00:00'));

    $p = siapkanPresensi();
    $presensi = PresensiPegawai::factory()->menunggu()->create(['pegawai_id' => $p['pegawai']->id, 'tanggal' => seninUji()]);

    // Tanpa catatan → ditolak.
    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/putuskan", [
        'keputusan' => 'ditolak',
    ])->assertStatus(422);

    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/putuskan", [
        'keputusan' => 'ditolak', 'catatan_penyetuju' => 'Tidak ada bukti tugas luar.',
    ])->assertOk();

    expect($presensi->refresh()->dihitungHadir())->toBeFalse();
});

/** FR-PRS-07 — setelah ditolak, pegawai boleh presensi ulang pada hari yang sama. */
it('mengizinkan presensi ulang setelah presensi ditolak (FR-PRS-07)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 09:00:00'));

    $p = siapkanPresensi();
    PresensiPegawai::factory()->menunggu()->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal' => seninUji(),
        'masuk_validasi' => PresensiPegawai::DITOLAK,
    ]);

    kirimPresensi($p['user'])->assertCreated();

    // Tetap satu baris untuk hari itu (BR-10), dan presensi lama tercatat di audit.
    expect(PresensiPegawai::where('pegawai_id', $p['pegawai']->id)->count())->toBe(1)
        ->and(PresensiPegawai::firstOrFail()->masuk_validasi)->toBe(PresensiPegawai::VALID);

    $this->assertDatabaseHas('audit_log', ['aksi' => 'presensi_masuk']);
});

/** FR-PRS-13 — koreksi manual wajib beralasan, ditandai, dan tercatat. */
it('mencatat koreksi manual presensi oleh admin (FR-PRS-13)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 16:00:00'));

    $p = siapkanPresensi();
    $presensi = PresensiPegawai::factory()->create([
        'pegawai_id' => $p['pegawai']->id,
        'tanggal' => seninUji(),
        'pulang_waktu' => null,
    ]);

    // Tanpa alasan → ditolak oleh validasi.
    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/koreksi", [
        'pulang_waktu' => seninUji().' 15:00:00',
    ])->assertStatus(422);

    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/koreksi", [
        'pulang_waktu' => seninUji().' 15:00:00',
        'pulang_status' => 'normal',
        'alasan' => 'Lupa presensi pulang, sudah dikonfirmasi ke wali kelas.',
    ])->assertOk();

    $presensi->refresh();
    expect($presensi->dikoreksi_admin)->toBeTrue()
        ->and($presensi->pulang_waktu)->not->toBeNull()
        ->and($presensi->pulang_status)->toBe(PresensiPegawai::PULANG_NORMAL);

    $this->assertDatabaseHas('audit_log', ['aksi' => 'koreksi_presensi']);
});

/** FR-PRS-11 — aksi massal pada antrean persetujuan. */
it('memutuskan banyak presensi sekaligus (FR-PRS-11 massal)', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 10:00:00'));

    siapkanPresensi();

    $satu = PresensiPegawai::factory()->menunggu()->create(['tanggal' => seninUji()]);
    $dua = PresensiPegawai::factory()->menunggu()->create(['tanggal' => seninUji()]);

    $respons = $this->actingAs(sebagaiAdmin())->postJson('/api/v1/monitoring/persetujuan-presensi/massal', [
        'id' => [$satu->id, $dua->id],
        'keputusan' => 'disetujui',
    ]);

    $respons->assertOk()->assertJsonPath('data.berhasil', 2);

    expect($satu->refresh()->masuk_validasi)->toBe(PresensiPegawai::DISETUJUI)
        ->and($dua->refresh()->masuk_validasi)->toBe(PresensiPegawai::DISETUJUI);
});

/** Bagian 2 — wakasek boleh melihat monitoring, tetapi tidak memutuskan. */
it('membatasi pemantauan dan keputusan sesuai matriks Bagian 2', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 10:00:00'));

    $p = siapkanPresensi();
    $presensi = PresensiPegawai::factory()->menunggu()->create(['pegawai_id' => $p['pegawai']->id, 'tanggal' => seninUji()]);

    // Wakasek: lihat saja.
    $this->actingAs(sebagaiWakasek())->getJson('/api/v1/monitoring/presensi-harian?tanggal='.seninUji())->assertOk();
    $this->actingAs(sebagaiWakasek())->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/putuskan", [
        'keputusan' => 'disetujui',
    ])->assertStatus(403);

    // Guru tidak berhak sama sekali.
    $this->actingAs(buatPegawaiDenganAkun())->getJson('/api/v1/monitoring/presensi-harian')->assertStatus(403);

    // Kepala sekolah berhak memutuskan.
    $this->actingAs(sebagaiKepsek())->patchJson("/api/v1/monitoring/presensi-harian/{$presensi->id}/putuskan", [
        'keputusan' => 'disetujui',
    ])->assertOk();
});
