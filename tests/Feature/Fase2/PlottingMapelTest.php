<?php

declare(strict_types=1);

use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\Semester;
use App\Models\TahunPelajaran;

beforeEach(function (): void {
    siapkanPeran();
});

/** KP-2.6 — plotting mapel dengan (semester, mapel, kelas) sama ditolak (BR-03). */
it('menolak plotting mapel ganda pada semester, mapel, dan kelas yang sama (KP-2.6/BR-03)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $guruLain = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-mapel', [
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $guruLain->id,
        'mapel_id' => $a['mapel']->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 3,
    ]);

    $respons->assertStatus(422);
    expect($respons->json('errors.mapel_id.0'))->toContain('BR-03')
        ->and($respons->json('code'))->toBe('BR-03');

    // Plotting lama tidak berubah.
    expect(PlottingMapel::count())->toBe(1)
        ->and($a['plotting']->refresh()->pegawai_id)->toBe($a['guru']->id);
});

/** FR-PLM-03 — hanya pegawai berjenis guru yang dapat menjadi pengampu. */
it('menolak pegawai struktural sebagai pengampu mapel (FR-PLM-03)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $struktural = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_STRUKTURAL]);
    $mapelLain = Mapel::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/plotting-mapel', [
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $struktural->id,
        'mapel_id' => $mapelLain->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 2,
    ])->assertStatus(422)->assertJsonPath('errors.pegawai_id.0', 'Hanya pegawai berjenis guru yang dapat menjadi pengampu mapel (FR-PLM-03).');
});

it('menolak kelas yang bukan bagian dari tahun pelajaran semester tersebut', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $tahunLain = TahunPelajaran::factory()->denganSemester()->create(['nama' => '2033/2034']);
    $kelasLain = Kelas::factory()->create(['tahun_pelajaran_id' => $tahunLain->id, 'nama' => 'X Lain 1']);
    $mapelLain = Mapel::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/plotting-mapel', [
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $a['guru']->id,
        'mapel_id' => $mapelLain->id,
        'kelas_id' => $kelasLain->id,
        'jp_per_minggu' => 2,
    ])->assertStatus(422);
});

it('menyimpan plotting mapel dan menampilkan guru pengampu', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $mapelLain = Mapel::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/plotting-mapel', [
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $a['guru']->id,
        'mapel_id' => $mapelLain->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 4,
    ])->assertCreated()->assertJsonPath('data.guru', $a['guru']->nama);

    expect(PlottingMapel::count())->toBe(2);
});

it('menolak JP per minggu di luar rentang wajar', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $mapelLain = Mapel::factory()->create();

    foreach ([0, 21] as $jp) {
        $this->actingAs($admin)->postJson('/api/v1/plotting-mapel', [
            'semester_id' => $a['semester']->id,
            'pegawai_id' => $a['guru']->id,
            'mapel_id' => $mapelLain->id,
            'kelas_id' => $a['kelas']->id,
            'jp_per_minggu' => $jp,
        ])->assertStatus(422);
    }
});

/**
 * 7.3 catatan — `pegawai_id` pada `jadwal` disalin dari plotting. Karena itu mengubah
 * pengampu wajib ikut memperbarui jadwal, jika tidak pengecekan bentrok (BR-06) salah.
 */
it('ikut memperbarui guru pada jadwal saat pengampu diubah', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1]);
    $guruBaru = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $this->actingAs($admin)->postJson('/api/v1/jadwal', [
        'semester_id' => $a['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $pola['slot'][0]->id,
        'plotting_mapel_id' => $a['plotting']->id,
    ])->assertCreated();

    expect(Jadwal::first()?->pegawai_id)->toBe($a['guru']->id);

    $this->actingAs($admin)->putJson("/api/v1/plotting-mapel/{$a['plotting']->id}", [
        'pegawai_id' => $guruBaru->id,
        'jp_per_minggu' => 4,
    ])->assertOk();

    expect(Jadwal::first()?->pegawai_id)->toBe($guruBaru->id);
});

/** FR-PLM-06 — plotting yang sudah dipakai jadwal tidak boleh dihapus. */
it('menolak menghapus plotting yang sudah dipakai jadwal (FR-PLM-06)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1]);

    $this->actingAs($admin)->postJson('/api/v1/jadwal', [
        'semester_id' => $a['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $pola['slot'][0]->id,
        'plotting_mapel_id' => $a['plotting']->id,
    ])->assertCreated();

    $this->actingAs($admin)->deleteJson("/api/v1/plotting-mapel/{$a['plotting']->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'DIPAKAI_JADWAL');

    expect(PlottingMapel::count())->toBe(1);
});

it('menghapus plotting yang belum dipakai jadwal', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $this->actingAs($admin)->deleteJson("/api/v1/plotting-mapel/{$a['plotting']->id}")->assertOk();

    expect(PlottingMapel::count())->toBe(0);
});

/** FR-PLM-04b — daftar per guru dengan total JP per minggu. */
it('menyajikan total JP per minggu tiap guru (FR-PLM-04b)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $kelasDua = Kelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id, 'tingkat' => 'X',
        'jurusan_id' => $a['jurusan']->id, 'nama' => 'X TKJ 2',
    ]);
    $mapelDua = Mapel::factory()->create();

    PlottingMapel::factory()->create([
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $a['guru']->id,
        'mapel_id' => $mapelDua->id,
        'kelas_id' => $kelasDua->id,
        'jp_per_minggu' => 6,
    ]);

    $respons = $this->actingAs($admin)->getJson('/api/v1/plotting-mapel/per-guru?semester_id='.$a['semester']->id);

    $respons->assertOk();
    $guru = collect($respons->json('data'))->firstWhere('pegawai_id', $a['guru']->id);

    expect($guru['total_jp'])->toBe(10)
        ->and($guru['jumlah_kelas'])->toBe(2)
        ->and($guru['rincian'])->toHaveCount(2);
});

/** FR-PLM-05 — salin plotting dari semester lain. */
it('menyalin plotting dari semester lain dan melewati yang sudah ada (FR-PLM-05)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $genap = semesterGenap($a['tahun']);

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-mapel/salin', [
        'semester_asal_id' => $a['semester']->id,
        'semester_tujuan_id' => $genap->id,
    ]);

    $respons->assertOk()->assertJsonPath('data.dibuat', 1);

    expect(PlottingMapel::where('semester_id', $genap->id)->count())->toBe(1);

    // Dijalankan lagi: tidak menggandakan, seluruhnya dilewati.
    $this->actingAs($admin)->postJson('/api/v1/plotting-mapel/salin', [
        'semester_asal_id' => $a['semester']->id,
        'semester_tujuan_id' => $genap->id,
    ])->assertOk()->assertJsonPath('data.dibuat', 0)->assertJsonPath('data.dilewati', 1);
});

/** Matriks Bagian 2 — guru hanya melihat plotting miliknya (L/S). */
it('membatasi guru hanya pada plotting miliknya sendiri', function (): void {
    $a = siapkanAkademik();

    $guruUser = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    $mapelDua = Mapel::factory()->create();
    PlottingMapel::factory()->create([
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $guruUser->pegawai_id,
        'mapel_id' => $mapelDua->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 2,
    ]);

    $respons = $this->actingAs($guruUser)->getJson('/api/v1/plotting-mapel?semester_id='.$a['semester']->id);

    $respons->assertOk()->assertJsonPath('meta.total', 1);

    $responsAdmin = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/plotting-mapel?semester_id='.$a['semester']->id);
    expect($responsAdmin->json('meta.total'))->toBe(2);
});

/** Bagian 2 — guru boleh melihat, wakasek boleh mengelola, kepala sekolah hanya melihat. */
it('menegakkan hak akses plotting mapel sesuai matriks', function (): void {
    $a = siapkanAkademik();
    $mapelLain = Mapel::factory()->create();

    $muatan = [
        'semester_id' => $a['semester']->id,
        'pegawai_id' => $a['guru']->id,
        'mapel_id' => $mapelLain->id,
        'kelas_id' => $a['kelas']->id,
        'jp_per_minggu' => 2,
    ];

    // Wakasek kurikulum memegang K.
    $this->actingAs(sebagaiWakasek())->postJson('/api/v1/plotting-mapel', $muatan)->assertCreated();

    // Kepala sekolah hanya L.
    $mapelTiga = Mapel::factory()->create();
    $this->actingAs(sebagaiKepsek())->postJson('/api/v1/plotting-mapel', array_merge($muatan, ['mapel_id' => $mapelTiga->id]))
        ->assertStatus(403);

    // Guru hanya L(S).
    $this->actingAs(buatPegawaiDenganAkun())->postJson('/api/v1/plotting-mapel', array_merge($muatan, ['mapel_id' => $mapelTiga->id]))
        ->assertStatus(403);

    expect(PlottingMapel::count())->toBe(2);
});
