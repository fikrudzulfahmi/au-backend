<?php

declare(strict_types=1);

use App\Models\Jurnal;
use App\Models\JurnalFoto;
use App\Models\Kelas;
use App\Models\Pegawai;
use App\Models\PresensiSiswa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
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

/** FR-LAP-07 — kolom Guru menyebut guru piket yang mengisi sesi guru pengampu. */
it('FR-LAP-07 menampilkan guru piket yang mengisi jurnal pada kolom Guru', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 09:00:00'));

    $r = siapkanJurnal(jamKe: [1, 2, 3]);

    /** @var Pegawai $piket */
    $piket = Pegawai::factory()->create(['nama' => 'Budi Piket']);

    // Jurnal milik guru pengampu tetapi diisi guru piket: kolom Guru harus memuat keduanya.
    Jurnal::factory()
        ->denganPlotting($r['plotting'])
        ->jamKe(1, 3)
        ->padaTanggal(seninUji())
        ->create([
            'pegawai_id' => $r['guru']->id,
            'diisi_oleh_pegawai_id' => $piket->id,
        ]);

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/daftar?periode=hari_ini');
    $res->assertOk();

    $guruPengampu = (string) $r['guru']->nama;

    expect($res->json('data.baris.0.4'))->toBe("{$guruPengampu} (diisi guru piket: Budi Piket)")
        ->and($res->json('data.jurnal.0.diisi_oleh'))->toBe('Budi Piket')
        ->and($res->json('data.jurnal.0.guru_keterangan'))->toBe("{$guruPengampu} (diisi guru piket: Budi Piket)");
});

it('FR-LAP-07 membiarkan kolom Guru apa adanya bila diisi pengampu sendiri', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 09:00:00'));

    $r = siapkanJurnal(jamKe: [1, 2, 3]);

    Jurnal::factory()
        ->denganPlotting($r['plotting'])
        ->jamKe(1, 3)
        ->padaTanggal(seninUji())
        ->create(['pegawai_id' => $r['guru']->id, 'diisi_oleh_pegawai_id' => null]);

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/daftar?periode=hari_ini');
    $res->assertOk();

    expect($res->json('data.baris.0.4'))->toBe((string) $r['guru']->nama)
        ->and($res->json('data.jurnal.0.diisi_oleh'))->toBeNull();
});

// =====================================================================
// FR-LAP-07 — detail read-only satu jurnal untuk admin & kepala sekolah
// =====================================================================

/** Menyiapkan satu jurnal Senin lengkap dengan presensi siswa dan satu foto. */
function jurnalUntukDetail(): array
{
    $r = siapkanJurnal(jamKe: [1, 2, 3]);

    /** @var Jurnal $jurnal */
    $jurnal = Jurnal::factory()
        ->denganPlotting($r['plotting'])
        ->jamKe(1, 3)
        ->padaTanggal(seninUji())
        ->create([
            'pegawai_id' => $r['guru']->id,
            'materi' => 'Struktur algoritma',
            'kegiatan' => 'Latihan pseudocode',
            'catatan' => 'Kelas kondusif',
        ]);

    $jurnal->presensiSiswa()->create([
        'siswa_id' => $r['siswa'][0]->id,
        'status' => PresensiSiswa::HADIR,
    ]);
    $jurnal->presensiSiswa()->create([
        'siswa_id' => $r['siswa'][1]->id,
        'status' => PresensiSiswa::SAKIT,
        'keterangan' => 'Demam',
    ]);

    Storage::fake('local');
    Storage::disk('local')->put('jurnal/uji/detail.jpg', 'isi-foto');
    JurnalFoto::factory()->create([
        'jurnal_id' => $jurnal->id,
        'foto_path' => 'jurnal/uji/detail.jpg',
        'urutan' => 1,
    ]);

    return ['rangkaian' => $r, 'jurnal' => $jurnal];
}

it('FR-LAP-07 membuka detail jurnal beserta presensi siswa untuk kepala sekolah', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 09:00:00'));
    ['rangkaian' => $r, 'jurnal' => $jurnal] = jurnalUntukDetail();

    $res = $this->actingAs(sebagaiKepsek())->getJson('/api/v1/laporan/jurnal/'.$jurnal->id);
    $res->assertOk();

    expect($res->json('data.materi'))->toBe('Struktur algoritma')
        ->and($res->json('data.kegiatan'))->toBe('Latihan pseudocode')
        ->and($res->json('data.guru'))->toBe((string) $r['guru']->nama)
        ->and($res->json('data.kelas'))->toBe((string) $r['kelas']->nama)
        ->and($res->json('data.ringkasan.H'))->toBe(1)
        ->and($res->json('data.ringkasan.S'))->toBe(1)
        ->and($res->json('data.presensi_siswa'))->toHaveCount(2)
        ->and($res->json('data.foto.0.url'))->toContain('/api/v1/laporan/jurnal/'.$jurnal->id.'/foto/');

    // Berkas foto juga boleh dibuka kepala sekolah lewat rute laporan.
    $fotoId = (int) $res->json('data.foto.0.id');
    $this->actingAs(sebagaiKepsek())
        ->get('/api/v1/laporan/jurnal/'.$jurnal->id.'/foto/'.$fotoId)
        ->assertOk();

    // Admin juga boleh.
    $this->actingAs(sebagaiAdmin())
        ->getJson('/api/v1/laporan/jurnal/'.$jurnal->id)
        ->assertOk();
});

it('FR-LAP-07 menolak detail jurnal milik guru lain, tetapi mengizinkan pengisinya', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 09:00:00'));
    ['rangkaian' => $r, 'jurnal' => $jurnal] = jurnalUntukDetail();

    $lain = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $this->actingAs($lain)
        ->getJson('/api/v1/laporan/jurnal/'.$jurnal->id)
        ->assertStatus(403);

    $this->actingAs($lain)
        ->getJson('/api/v1/laporan/jurnal/'.$jurnal->id.'/foto/'.(int) $jurnal->foto()->value('id'))
        ->assertStatus(403);

    // Guru pengampu sendiri tetap boleh membaca detailnya.
    $this->actingAs($r['user'])
        ->getJson('/api/v1/laporan/jurnal/'.$jurnal->id)
        ->assertOk();

    // Guru piket yang mengisi sesi ini juga boleh (jejak diisi_oleh_pegawai_id).
    $piket = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    $jurnal->update(['diisi_oleh_pegawai_id' => $piket->pegawai->id]);

    $this->actingAs($piket)
        ->getJson('/api/v1/laporan/jurnal/'.$jurnal->id)
        ->assertOk();
});

it('FR-LAP-07 mengirim id jurnal pada daftar agar tabel dapat membuka detail', function (): void {
    $this->travelTo(CarbonImmutable::parse(seninUji().' 09:00:00'));
    ['jurnal' => $jurnal] = jurnalUntukDetail();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/daftar?periode=hari_ini');
    $res->assertOk();

    expect($res->json('data.jurnal.0.id'))->toBe($jurnal->id)
        // Bentuk `baris[]` tidak berubah: tetap 13 kolom seperti kontrak laporan.
        ->and($res->json('data.baris.0'))->toHaveCount(13);
});
