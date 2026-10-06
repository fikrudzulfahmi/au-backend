<?php

declare(strict_types=1);

use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\Semester;
use App\Models\SlotJam;

beforeEach(function (): void {
    siapkanPeran();
});

/** Kelas kedua + plotting dengan GURU YANG SAMA (untuk menguji BR-06). */
function plottingGuruSama(array $a): PlottingMapel
{
    $kelas = Kelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'tingkat' => 'X',
        'jurusan_id' => $a['jurusan']->id,
        'nama' => 'X TKJ 2',
    ]);

    return PlottingMapel::factory()->create([
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $a['guru']->id,
        'mapel_id' => Mapel::factory()->create()->id,
        'kelas_id' => $kelas->id,
        'jp_per_minggu' => 4,
    ]);
}

/** Kelas SAMA + mapel lain + guru lain (untuk menguji BR-07). */
function plottingKelasSama(array $a): PlottingMapel
{
    return PlottingMapel::factory()->create([
        'semester_id' => $a['semester']->id,
        'pegawai_id' => Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU])->id,
        'mapel_id' => Mapel::factory()->create()->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 4,
    ]);
}

function tambahJadwal(array $a, int $hari, int $slotJamId, int $plottingId)
{
    return test()->actingAs(sebagaiAdmin())->postJson('/api/v1/jadwal', [
        'semester_id' => $a['semester']->id,
        'hari' => $hari,
        'slot_jam_id' => $slotJamId,
        'plotting_mapel_id' => $plottingId,
    ]);
}

it('menyimpan jadwal pada slot pelajaran yang sah', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    $this->actingAs($admin)->postJson('/api/v1/jadwal', [
        'semester_id' => $a['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $pola['slot'][0]->id,
        'plotting_mapel_id' => $a['plotting']->id,
    ])->assertCreated()
        ->assertJsonPath('data.hari', 1)
        ->assertJsonPath('data.nama_hari', 'Senin')
        ->assertJsonPath('data.guru', $a['guru']->nama)
        ->assertJsonPath('data.kelas', $a['kelas']->nama);

    expect(Jadwal::count())->toBe(1);
});

/** KP-2.7 / BR-06 — guru tidak boleh mengajar dua kelas pada hari+slot yang sama. */
it('menolak jadwal yang membuat guru bentrok (KP-2.7/BR-06)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);
    $lain = plottingGuruSama($a);

    tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id)->assertCreated();

    $respons = tambahJadwal($a, 1, $pola['slot'][0]->id, $lain->id);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BR-06');
    expect($respons->json('errors.plotting_mapel_id.0'))->toContain('Bentrok guru')
        ->and($respons->json('errors.plotting_mapel_id.0'))->toContain($a['kelas']->nama);

    expect(Jadwal::count())->toBe(1);
});

/** KP-2.7 / BR-07 — kelas tidak boleh punya dua mapel pada hari+slot yang sama. */
it('menolak jadwal yang membuat kelas bentrok (KP-2.7/BR-07)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);
    $lain = plottingKelasSama($a);

    tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id)->assertCreated();

    $respons = tambahJadwal($a, 1, $pola['slot'][0]->id, $lain->id);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BR-07');
    expect($respons->json('errors.plotting_mapel_id.0'))->toContain('Bentrok kelas');

    expect(Jadwal::count())->toBe(1);
});

it('mengizinkan jadwal yang sama pada slot berbeda', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id)->assertCreated();
    tambahJadwal($a, 1, $pola['slot'][1]->id, $a['plotting']->id)->assertCreated();

    expect(Jadwal::count())->toBe(2);
});

/** KP-2.7 / BR-08 — hanya slot bertipe pelajaran yang dapat dijadwalkan. */
it('menolak slot istirahat (KP-2.7/BR-08)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    $istirahat = $pola['slot']->firstWhere('tipe', SlotJam::ISTIRAHAT);

    $respons = tambahJadwal($a, 1, $istirahat->id, $a['plotting']->id);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BR-08');
    expect($respons->json('errors.slot_jam_id.0'))->toContain('pelajaran');

    expect(Jadwal::count())->toBe(0);
});

it('menolak slot yang bukan milik pola jam hari tersebut (BR-08)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    // Pola hanya berlaku hari Senin; mencoba memakai slotnya untuk hari Selasa.
    $pola = pasangPolaJam($a['semester'], [1], 2);

    $respons = tambahJadwal($a, 2, $pola['slot'][0]->id, $a['plotting']->id);

    $respons->assertStatus(422);
    expect($respons->json('errors.hari.0'))->toContain('Selasa');
});

it('menolak penjadwalan pada hari yang belum punya pola jam (BR-08)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    $respons = tambahJadwal($a, 5, $pola['slot'][0]->id, $a['plotting']->id);

    $respons->assertStatus(422);
    expect($respons->json('errors.hari.0'))->toContain('belum memiliki pola jam');
});

/** BR-09 — jumlah entri jadwal tidak boleh melebihi jp_per_minggu plotting. */
it('menolak jadwal yang melebihi JP per minggu plotting (BR-09)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $a['plotting']->update(['jp_per_minggu' => 1]);

    $pola = pasangPolaJam($a['semester'], [1], 3);

    tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id)->assertCreated();

    $respons = tambahJadwal($a, 1, $pola['slot'][1]->id, $a['plotting']->id);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BR-09');
    expect($respons->json('errors.plotting_mapel_id.0'))->toContain('JP per minggu');

    expect(Jadwal::count())->toBe(1);
});

it('menolak plotting dari semester lain', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    // Tahun pelajaran dari helper sudah punya semester ganjil + genap; memakai yang ada.
    $genap = Semester::where('tahun_pelajaran_id', $a['tahun']->id)->where('jenis', 'genap')->firstOrFail();
    $plottingGenap = PlottingMapel::factory()->create([
        'semester_id' => $genap->id,
        'pegawai_id' => $a['guru']->id,
        'mapel_id' => $a['mapel']->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 2,
    ]);

    $respons = tambahJadwal($a, 1, $pola['slot'][0]->id, $plottingGenap->id);

    $respons->assertStatus(422);
    expect($respons->json('errors.plotting_mapel_id.0'))->toContain('semester ini');
});

/** FR-JDW-04 — grid jadwal beserta sel kosong yang dapat diklik. */
it('menyajikan grid jadwal per hari dan slot (FR-JDW-04)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id)->assertCreated();

    $respons = $this->actingAs($admin)->getJson(
        '/api/v1/jadwal?semester_id='.$a['semester']->id.'&kelas_id='.$a['kelas']->id
    );

    $respons->assertOk()->assertJsonCount(1, 'data.hari');

    $hari = $respons->json('data.hari.0');
    expect($hari['nama_hari'])->toBe('Senin')
        // 2 pelajaran + 1 istirahat.
        ->and($hari['slot'])->toHaveCount(3)
        ->and($hari['slot'][0]['isi'])->toHaveCount(1)
        ->and($hari['slot'][0]['isi'][0]['mapel'])->toBe($a['mapel']->nama)
        // Sel kosong tetap dikirim agar antarmuka dapat menyediakan tombol tambah.
        ->and($hari['slot'][1]['isi'])->toBe([])
        ->and($hari['slot'][1]['dapat_dijadwalkan'])->toBeTrue()
        ->and($hari['slot'][2]['dapat_dijadwalkan'])->toBeFalse();
});

/** FR-JDW-07 — peringatan (bukan blokir) bila JP terjadwal kurang dari plotting. */
it('memperingatkan JP terjadwal yang kurang tanpa memblokir (FR-JDW-07)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $a['plotting']->update(['jp_per_minggu' => 4]);

    $pola = pasangPolaJam($a['semester'], [1], 2);

    $respons = tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id);

    $respons->assertCreated();
    $peringatan = collect($respons->json('peringatan'))->firstWhere('plotting_mapel_id', $a['plotting']->id);

    expect($peringatan)->not->toBeNull()
        ->and($peringatan['jp_per_minggu'])->toBe(4)
        ->and($peringatan['terjadwal'])->toBe(1);

    // Setelah JP terpenuhi, peringatan hilang.
    tambahJadwal($a, 1, $pola['slot'][1]->id, $a['plotting']->id)->assertCreated();
    $a['plotting']->update(['jp_per_minggu' => 2]);

    $this->actingAs($admin)->getJson('/api/v1/jadwal/peringatan?semester_id='.$a['semester']->id)
        ->assertOk()->assertJsonCount(0, 'data');
});

it('menghapus jadwal', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id)->assertCreated();
    $jadwal = Jadwal::first();

    $this->actingAs($admin)->deleteJson("/api/v1/jadwal/{$jadwal->id}")->assertOk();

    expect(Jadwal::count())->toBe(0);
    $this->assertDatabaseHas('audit_log', ['aksi' => 'hapus_data']);
});

/** FR-JDW-06 — guru melihat jadwal hari ini dan mingguan miliknya. */
it('menyajikan jadwal hari ini dan mingguan milik guru (FR-JDW-06)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    $guruUser = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    $plotting = PlottingMapel::factory()->create([
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $guruUser->pegawai_id,
        'mapel_id' => Mapel::factory()->create()->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 2,
    ]);

    $this->actingAs($admin)->postJson('/api/v1/jadwal', [
        'semester_id' => $a['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $pola['slot'][0]->id,
        'plotting_mapel_id' => $plotting->id,
    ])->assertCreated();

    $this->actingAs($guruUser)->getJson('/api/v1/jadwal/mingguan?semester_id='.$a['semester']->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.hari')
        ->assertJsonPath('data.hari.0.nama_hari', 'Senin')
        ->assertJsonPath('data.hari.0.jumlah_jp', 1);

    $this->actingAs($guruUser)->getJson('/api/v1/jadwal/hari-ini?semester_id='.$a['semester']->id)
        ->assertOk()
        ->assertJsonStructure(['data' => ['hari', 'nama_hari', 'jadwal']]);
});

it('menolak guru mengintip jadwal guru lain lewat parameter pegawai_id', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    // Jadwal milik guru lain (bukan pengguna yang masuk).
    tambahJadwal($a, 1, $pola['slot'][0]->id, $a['plotting']->id)->assertCreated();

    $guruUser = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $respons = $this->actingAs($guruUser)->getJson(
        '/api/v1/jadwal?semester_id='.$a['semester']->id.'&pegawai_id='.$a['guru']->id
    );

    $respons->assertOk();

    // Batas L/S: jadwal guru lain tidak boleh ikut terbawa.
    $isi = collect($respons->json('data.hari'))->flatMap(fn (array $h): array => collect($h['slot'])->flatMap(fn (array $s): array => $s['isi'])->all());
    expect($isi)->toBeEmpty();
});

it('membatasi pengelolaan jadwal sesuai matriks Bagian 2', function (): void {
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    $muatan = [
        'semester_id' => $a['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $pola['slot'][0]->id,
        'plotting_mapel_id' => $a['plotting']->id,
    ];

    $this->actingAs(sebagaiKepsek())->getJson('/api/v1/jadwal')->assertOk();
    $this->actingAs(sebagaiKepsek())->postJson('/api/v1/jadwal', $muatan)->assertStatus(403);
    $this->actingAs(sebagaiWakasek())->postJson('/api/v1/jadwal', $muatan)->assertCreated();
    $this->actingAs(buatPegawaiDenganAkun())->postJson('/api/v1/jadwal', $muatan)->assertStatus(403);

    expect(Jadwal::count())->toBe(1);
});
