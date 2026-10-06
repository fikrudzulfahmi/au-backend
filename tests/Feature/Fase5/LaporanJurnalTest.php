<?php

declare(strict_types=1);

use App\Models\Jurnal;
use App\Models\Kelas;
use App\Models\Pegawai;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Fase 5 — laporan jurnal & presensi siswa (5.14 B, FR-LAP-06..09)
|--------------------------------------------------------------------------
| Fokus: BR-26 (kepatuhan jurnal), KP-5.5 (wali kelas), otorisasi per peran.
*/

beforeEach(function (): void {
    siapkanPeran();
});

/**
 * Menyiapkan sesi Senin (jam 1-3 tergabung) milik seorang guru, lengkap dengan
 * jam kerja dan akun login.
 *
 * @return array<string, mixed>
 */
function rangkaianKepatuhan(): array
{
    $r = siapkanJurnal(jamKe: [1, 2, 3]);
    pasangJamKerja(Pegawai::JENIS_GURU);

    return $r;
}

/**
 * Baris kepatuhan milik seorang guru tertentu (laporan memuat SEMUA guru aktif,
 * sehingga tidak boleh memakai indeks tetap).
 *
 * @return array<string, mixed>
 */
function barisKepatuhan(TestResponse $res, int $guruId): array
{
    return collect($res->json('data.guru'))->firstWhere('pegawai_id', $guruId) ?? [];
}

// =====================================================================
// BR-26 — kepatuhan jurnal: berhalangan & libur dikecualikan
// =====================================================================

it('BR-26 menghitung sesi belum terisi dari sesi terjadwal pada hari kerja', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = rangkaianKepatuhan();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/kepatuhan?periode=hari_ini');
    $res->assertOk();

    $baris = barisKepatuhan($res, (int) $r['guru']->id);

    expect($baris['terjadwal'])->toBe(1)
        ->and($baris['terisi'])->toBe(0)
        ->and($baris['belum_terisi'])->toBe(1)
        ->and($baris['berhalangan'])->toBe(0)
        ->and($baris['persen_kepatuhan'])->toEqual(0);

    // Daftar rinci sesi belum terisi.
    $rinci = collect($res->json('data.belum_terisi'))->firstWhere('nip', $r['guru']->nip);
    expect($rinci)->not->toBeNull()
        ->and($rinci['tanggal'])->toBe('2026-10-05')
        ->and($rinci['label_jam'])->toBe('Jam ke-1-3')
        ->and($rinci['hari'])->toBe('Senin');
});

it('BR-26 menghitung sesi terisi bila jurnalnya sudah dibuat', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = rangkaianKepatuhan();

    Jurnal::factory()->denganPlotting($r['plotting'])->padaTanggal('2026-10-05')->jamKe(1, 3)->create();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/kepatuhan?periode=hari_ini');
    $res->assertOk();

    $baris = barisKepatuhan($res, (int) $r['guru']->id);

    expect($baris['terjadwal'])->toBe(1)
        ->and($baris['terisi'])->toBe(1)
        ->and($baris['belum_terisi'])->toBe(0)
        ->and($baris['persen_kepatuhan'])->toEqual(100);

    // Guru ini tidak lagi muncul pada daftar sesi belum terisi.
    expect(collect($res->json('data.belum_terisi'))->firstWhere('nip', $r['guru']->nip))->toBeNull();
});

it('BR-26 tidak menghitung sesi pada hari izin disetujui sebagai belum terisi (Berhalangan)', function (string $jenis) {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = rangkaianKepatuhan();

    setujuiIzin($r['guru'], '2026-10-05', null, $jenis);

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/kepatuhan?periode=hari_ini');
    $res->assertOk();

    $baris = barisKepatuhan($res, (int) $r['guru']->id);

    expect($baris['terjadwal'])->toBe(1)
        ->and($baris['terisi'])->toBe(0)
        ->and($baris['belum_terisi'])->toBe(0)
        ->and($baris['berhalangan'])->toBe(1);

    // Penyebut = terjadwal - berhalangan = 0 → tidak ada persentase.
    expect($baris['persen_kepatuhan'])->toBeNull()
        ->and(collect($res->json('data.belum_terisi'))->firstWhere('nip', $r['guru']->nip))->toBeNull();
})->with(['izin', 'sakit', 'cuti', 'dinas']);

it('BR-26 tidak menghitung sesi pada hari libur sebagai belum terisi', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = rangkaianKepatuhan();

    tandaiLibur($r['tahun'], '2026-10-05', null, 'Libur Uji');

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/kepatuhan?periode=hari_ini');
    $res->assertOk();

    $baris = barisKepatuhan($res, (int) $r['guru']->id);

    expect($baris['terjadwal'])->toBe(0)
        ->and($baris['belum_terisi'])->toBe(0)
        ->and($baris['berhalangan'])->toBe(0)
        ->and($baris['persen_kepatuhan'])->toBeNull();
});

it('BR-26 tidak menghitung sesi pada hari yang belum lewat', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00')); // Senin

    $r = rangkaianKepatuhan();

    // Periode sebulan penuh, tetapi hari Senin berikutnya (12 Okt) belum lewat.
    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/kepatuhan?periode=bulan_ini');
    $res->assertOk();

    $baris = barisKepatuhan($res, (int) $r['guru']->id);

    // Hanya satu sesi Senin yang jatuh pada/ sebelum 5 Oktober.
    expect($baris['terjadwal'])->toBe(1);
});

// =====================================================================
// FR-LAP-07 — daftar jurnal (guru hanya jurnalnya sendiri)
// =====================================================================

it('membatasi guru ke jurnalnya sendiri dan menolak 403 atas guru lain', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = siapkanJurnal();
    $lain = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    // Tanpa guru_id: server memaksa guru_id = dirinya sendiri.
    $res = $this->actingAs($r['user'])->getJson('/api/v1/laporan/jurnal/daftar?periode=hari_ini');
    $res->assertOk();
    expect($res->json('data.jurnal'))->toBe([]); // belum ada jurnal dibuat

    $this->actingAs($r['user'])
        ->getJson('/api/v1/laporan/jurnal/daftar?periode=hari_ini&guru_id='.$lain->pegawai->id)
        ->assertStatus(403);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/laporan/jurnal/kepatuhan?periode=hari_ini&guru_id='.$lain->pegawai->id)
        ->assertStatus(403);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/laporan/jurnal/jam-mengajar?periode=hari_ini&guru_id='.$lain->pegawai->id)
        ->assertStatus(403);
});

it('membiarkan kepala sekolah dan wakasek membaca laporan jurnal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    rangkaianKepatuhan();

    foreach ([sebagaiKepsek(), sebagaiWakasek()] as $pengguna) {
        $this->actingAs($pengguna)
            ->getJson('/api/v1/laporan/jurnal/daftar?periode=hari_ini')
            ->assertOk();
        $this->actingAs($pengguna)
            ->getJson('/api/v1/laporan/jurnal/kepatuhan?periode=hari_ini')
            ->assertOk();
    }
});

it('tetap menolak kepala sekolah dan wakasek pada endpoint /jurnal Fase 4', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    rangkaianKepatuhan();

    foreach ([sebagaiKepsek(), sebagaiWakasek()] as $pengguna) {
        $this->actingAs($pengguna)
            ->getJson('/api/v1/jurnal/kelas-wali')
            ->assertStatus(403);
    }
});

it('menolak pegawai struktural pada seluruh laporan jurnal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    rangkaianKepatuhan();
    $struktural = buatPegawaiDenganAkun(Pegawai::JENIS_STRUKTURAL);

    foreach (['daftar', 'kepatuhan', 'jam-mengajar'] as $rute) {
        $this->actingAs($struktural)
            ->getJson("/api/v1/laporan/jurnal/{$rute}?periode=hari_ini")
            ->assertStatus(403);
    }
});

// =====================================================================
// KP-5.5 — wali kelas hanya kelasnya sendiri
// =====================================================================

it('KP-5.5 mengizinkan wali kelas membuka rekap kelasnya sendiri', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = siapkanJurnal();
    pasangJamKerja(Pegawai::JENIS_GURU);
    $r['kelas']->update(['wali_kelas_id' => $r['guru']->id]);

    // Tanpa kelas_id: server memilih kelas wali miliknya.
    $res = $this->actingAs($r['user'])
        ->getJson('/api/v1/laporan/kelas-wali/rekap-siswa?periode=hari_ini');

    $res->assertOk();
    expect($res->json('data.kelas_id'))->toBe((int) $r['kelas']->id);

    // Dengan kelas_id miliknya: tetap boleh.
    $this->actingAs($r['user'])
        ->getJson('/api/v1/laporan/kelas-wali/rekap-siswa?periode=hari_ini&kelas_id='.$r['kelas']->id)
        ->assertOk();
});

it('KP-5.5 menolak 403 wali kelas yang meminta rekap kelas lain', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = siapkanJurnal();
    pasangJamKerja(Pegawai::JENIS_GURU);
    $r['kelas']->update(['wali_kelas_id' => $r['guru']->id]);

    $lain = Kelas::factory()->create([
        'tahun_pelajaran_id' => $r['tahun']->id,
        'jurusan_id' => $r['jurusan']->id,
    ]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/laporan/kelas-wali/rekap-siswa?periode=hari_ini&kelas_id='.$lain->id)
        ->assertStatus(403);
});

it('KP-5.5 menolak 403 guru yang bukan wali kelas sama sekali', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    siapkanJurnal();
    pasangJamKerja(Pegawai::JENIS_GURU);
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $this->actingAs($guru)
        ->getJson('/api/v1/laporan/kelas-wali/rekap-siswa?periode=hari_ini')
        ->assertStatus(403);
});

it('KP-5.5 membiarkan admin memilih kelas mana pun', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = siapkanJurnal();
    pasangJamKerja(Pegawai::JENIS_GURU);

    $lain = Kelas::factory()->create([
        'tahun_pelajaran_id' => $r['tahun']->id,
        'jurusan_id' => $r['jurusan']->id,
    ]);

    foreach ([$r['kelas'], $lain] as $kelas) {
        $res = $this->actingAs(sebagaiAdmin())
            ->getJson('/api/v1/laporan/jurnal/rekap-siswa?periode=hari_ini&kelas_id='.$kelas->id);
        $res->assertOk();
        expect($res->json('data.kelas_id'))->toBe((int) $kelas->id);
    }

    // Admin tanpa kelas_id ditolak karena harus memilih kelas lebih dahulu.
    $this->actingAs(sebagaiAdmin())
        ->getJson('/api/v1/laporan/jurnal/rekap-siswa?periode=hari_ini')
        ->assertStatus(422);
});

// =====================================================================
// FR-LAP-09 — jam mengajar terlaksana
// =====================================================================

it('FR-LAP-09 menghitung jumlah JP terlaksana dari sesi jurnal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = rangkaianKepatuhan();

    Jurnal::factory()->denganPlotting($r['plotting'])->padaTanggal('2026-10-05')->jamKe(1, 3)->create();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/jam-mengajar?periode=hari_ini');
    $res->assertOk();

    $baris = collect($res->json('data.guru'))->firstWhere('pegawai_id', (int) $r['guru']->id);

    expect($baris['jumlah_sesi'])->toBe(1)
        ->and($baris['jumlah_jp'])->toBe(3);
});
