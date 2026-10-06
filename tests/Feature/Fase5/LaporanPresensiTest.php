<?php

declare(strict_types=1);

use App\Models\Pegawai;
use App\Models\PresensiPegawai;
use App\Support\PeriodeLaporan;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Fase 5 — laporan presensi pegawai (5.14 A, FR-LAP-01..05)
|--------------------------------------------------------------------------
| Fokus: BR-24 (alpa), periode laporan, dan otorisasi per peran (FR-LAP-12).
*/

beforeEach(function (): void {
    siapkanPeran();
});

/**
 * Rangkaian acuan BR-24:
 *  - Senin 28 Sep, Rabu 30 Sep, Kamis 1 Okt, Jumat 2 Okt, Senin 5 Okt = hari kerja.
 *  - Selasa 29 Sep = hari LIBUR (tidak dihitung alpa, tidak masuk hari kerja).
 *  - Rabu 30 Sep = SAKIT disetujui (bukan alpa).
 *  - Kamis 1 Okt = presensi valid (hadir).
 *  - Sisanya (28 Sep, 2 Okt, 5 Okt) = alpa.
 *  - 6 Okt..12 Okt BELUM lewat (sejak "hari ini" = 5 Okt) → tidak dihitung.
 *
 * @return array<string, mixed>
 */
function rangkaianAlpa(): array
{
    $tahun = tahunAktif();
    $semester = semesterAktif($tahun);
    $r = siapkanPresensi(); // guru + jam kerja Senin–Jumat + lokasi

    tandaiLibur($tahun, '2026-09-29', null, 'Cuti Bersama');
    setujuiIzin($r['pegawai'], '2026-09-30', null, 'sakit');

    PresensiPegawai::factory()->create([
        'pegawai_id' => $r['pegawai']->id,
        'semester_id' => $semester->id,
        'tanggal' => '2026-10-01',
        'masuk_validasi' => PresensiPegawai::VALID,
    ]);

    return [...$r, 'tahun' => $tahun, 'semester' => $semester];
}

// =====================================================================
// BR-24 — alpa dihitung saat laporan dibuat, bukan disimpan
// =====================================================================

it('BR-24 menghitung alpa persis: libur & izin dikecualikan, hari belum lewat diabaikan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    rangkaianAlpa();

    $res = $this->actingAs(sebagaiAdmin())->getJson(
        '/api/v1/laporan/presensi/rekap-pegawai?periode=rentang&dari=2026-09-28&sampai=2026-10-12'
    );

    $res->assertOk()
        ->assertJsonPath('data.pegawai.0.hari_kerja', 5)
        ->assertJsonPath('data.pegawai.0.hadir', 1)
        ->assertJsonPath('data.pegawai.0.sakit', 1)
        ->assertJsonPath('data.pegawai.0.izin', 0)
        ->assertJsonPath('data.pegawai.0.dinas', 0)
        ->assertJsonPath('data.pegawai.0.alpa', 3);

    // Persentase kehadiran 1/5 = 20%; JSON menormalkan 20.0 menjadi 20.
    expect($res->json('data.pegawai.0.persen_kehadiran'))->toEqual(20.0)
        ->and($res->json('data.pegawai.0.persen_kehadiran'))->toEqual(20);
});

it('BR-24 tidak menghitung alpa pada hari libur', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $tahun = tahunAktif();
    semesterAktif($tahun);
    siapkanPresensi();

    // Seluruh pekan 28 Sep–2 Okt ditandai libur.
    tandaiLibur($tahun, '2026-09-28', '2026-10-02', 'Libur Panjang');

    $this->actingAs(sebagaiAdmin())->getJson(
        '/api/v1/laporan/presensi/rekap-pegawai?periode=rentang&dari=2026-09-28&sampai=2026-10-02'
    )->assertOk()
        ->assertJsonPath('data.pegawai.0.hari_kerja', 0)
        ->assertJsonPath('data.pegawai.0.alpa', 0)
        ->assertJsonPath('data.pegawai.0.persen_kehadiran', null);
});

it('BR-24 tidak menghitung alpa pada hari izin/sakit/cuti yang disetujui', function (string $jenis) {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    $r = siapkanPresensi();

    setujuiIzin($r['pegawai'], '2026-09-28', null, $jenis);

    $this->actingAs(sebagaiAdmin())->getJson(
        '/api/v1/laporan/presensi/rekap-pegawai?periode=rentang&dari=2026-09-28&sampai=2026-09-28'
    )->assertOk()
        ->assertJsonPath('data.pegawai.0.alpa', 0)
        ->assertJsonPath("data.pegawai.0.{$jenis}", 1);
})->with(['izin', 'sakit', 'cuti']);

it('BR-25 izin yang masih menunggu TIDAK membebaskan dan tetap dihitung alpa', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    $r = siapkanPresensi();

    setujuiIzin($r['pegawai'], '2026-09-28', null, 'izin', 'menunggu');

    $this->actingAs(sebagaiAdmin())->getJson(
        '/api/v1/laporan/presensi/rekap-pegawai?periode=rentang&dari=2026-09-28&sampai=2026-09-28'
    )->assertOk()
        ->assertJsonPath('data.pegawai.0.alpa', 1)
        ->assertJsonPath('data.pegawai.0.izin', 0);
});

it('BR-24/BR-25 hari dinas disetujui tidak dihitung alpa dan tercatat sebagai dinas', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    $r = siapkanPresensi();

    setujuiIzin($r['pegawai'], '2026-09-28', null, 'dinas');

    $this->actingAs(sebagaiAdmin())->getJson(
        '/api/v1/laporan/presensi/rekap-pegawai?periode=rentang&dari=2026-09-28&sampai=2026-09-28'
    )->assertOk()
        ->assertJsonPath('data.pegawai.0.dinas', 1)
        ->assertJsonPath('data.pegawai.0.hadir', 0)
        ->assertJsonPath('data.pegawai.0.alpa', 0);
});

it('BR-24 alpa dihitung saat laporan dibuat dan TIDAK disimpan sebagai baris', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = rangkaianAlpa();

    // Sebelum laporan: hanya satu baris presensi (Kamis hadir).
    expect(PresensiPegawai::query()->count())->toBe(1);

    $this->actingAs(sebagaiAdmin())->getJson(
        '/api/v1/laporan/presensi/rekap-pegawai?periode=rentang&dari=2026-09-28&sampai=2026-10-12'
    )->assertOk()->assertJsonPath('data.pegawai.0.alpa', 3);

    // Sesudah laporan: jumlah baris presensi tidak berubah — alpa tidak pernah disimpan.
    expect(PresensiPegawai::query()->count())->toBe(1)
        ->and(PresensiPegawai::query()->where('pegawai_id', $r['pegawai']->id)->count())->toBe(1);
});

it('BR-24 hanya menghitung pegawai aktif', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    siapkanPresensi();
    Pegawai::factory()->nonaktif()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $res = $this->actingAs(sebagaiAdmin())->getJson(
        '/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini'
    );

    $res->assertOk();
    expect($res->json('data.pegawai'))->toHaveCount(1);
});

// =====================================================================
// Periode laporan (FR-LAP-11)
// =====================================================================

it('menyelesaikan periode hari ini / minggu ini / bulan ini / rentang dengan benar', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00')); // Rabu

    $hariIni = PeriodeLaporan::buat(['periode' => 'hari_ini']);
    expect($hariIni->dari->toDateString())->toBe('2026-10-07')
        ->and($hariIni->sampai->toDateString())->toBe('2026-10-07')
        ->and($hariIni->label)->toBe('7 Oktober 2026');

    $minggu = PeriodeLaporan::buat(['periode' => 'minggu_ini']);
    expect($minggu->dari->toDateString())->toBe('2026-10-05')
        ->and($minggu->sampai->toDateString())->toBe('2026-10-11')
        ->and($minggu->label)->toBe('5 Oktober 2026 s.d. 11 Oktober 2026');

    $bulan = PeriodeLaporan::buat(['periode' => 'bulan_ini']);
    expect($bulan->dari->toDateString())->toBe('2026-10-01')
        ->and($bulan->sampai->toDateString())->toBe('2026-10-31')
        ->and($bulan->label)->toBe('Oktober 2026');

    // Tanpa `periode` tetapi dengan `dari`/`sampai` diperlakukan sebagai rentang.
    $rentang = PeriodeLaporan::buat(['dari' => '2026-09-28', 'sampai' => '2026-10-02']);
    expect($rentang->dari->toDateString())->toBe('2026-09-28')
        ->and($rentang->sampai->toDateString())->toBe('2026-10-02')
        ->and($rentang->label)->toBe('28 September 2026 s.d. 2 Oktober 2026')
        ->and($rentang->tanggal())->toHaveCount(5);

    // Rentang terbalik dibalik, bukan ditolak.
    $terbalik = PeriodeLaporan::buat(['periode' => 'rentang', 'dari' => '2026-10-02', 'sampai' => '2026-09-28']);
    expect($terbalik->dari->toDateString())->toBe('2026-09-28')
        ->and($terbalik->sampai->toDateString())->toBe('2026-10-02');
});

it('menolak format periode yang tidak dikenal', function () {
    $this->actingAs(sebagaiAdmin())
        ->getJson('/api/v1/laporan/presensi/rekap-pegawai?periode=triwulan')
        ->assertStatus(422)
        ->assertJsonValidationErrors('periode');
});

// =====================================================================
// Otorisasi per peran (matriks Bagian 2 / FR-LAP-12)
// =====================================================================

it('mengizinkan admin, kepsek, dan wakasek melihat seluruh laporan presensi pegawai', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    siapkanPresensi();

    foreach ([sebagaiAdmin(), sebagaiKepsek(), sebagaiWakasek()] as $pengguna) {
        $res = $this->actingAs($pengguna)->getJson('/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini');
        $res->assertOk();
        // Peran pemantau tidak dibatasi ke dirinya sendiri.
        expect($res->json('data.pegawai'))->toHaveCount(1);
    }
});

it('membatasi guru dan pegawai struktural ke laporan dirinya sendiri (L(S))', function (string $jenis) {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    siapkanPresensi();

    $orang = buatPegawaiDenganAkun($jenis);

    $res = $this->actingAs($orang)->getJson('/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini');
    $res->assertOk();

    $daftar = $res->json('data.pegawai');
    expect($daftar)->toHaveCount(1)
        ->and($daftar[0]['pegawai_id'])->toBe((int) $orang->pegawai->id);
})->with([
    'guru' => Pegawai::JENIS_GURU,
    'struktural' => Pegawai::JENIS_STRUKTURAL,
]);

it('menolak 403 bila guru meminta laporan pegawai lain', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    $lain = siapkanPresensi();
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $this->actingAs($guru)
        ->getJson('/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini&pegawai_id='.$lain['pegawai']->id)
        ->assertStatus(403);

    $this->actingAs($guru)
        ->getJson('/api/v1/laporan/presensi/detail-pegawai?periode=hari_ini&pegawai_id='.$lain['pegawai']->id)
        ->assertStatus(403);
});
