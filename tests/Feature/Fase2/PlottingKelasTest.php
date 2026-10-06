<?php

declare(strict_types=1);

use App\Models\Kelas;
use App\Models\MutasiKelas;
use App\Models\PlottingKelas;
use App\Models\Siswa;
use App\Models\TahunPelajaran;

beforeEach(function (): void {
    siapkanPeran();
});

/** KP-2.1 — siswa tidak dapat terplot di dua kelas pada tahun pelajaran yang sama (BR-04). */
it('menolak siswa terplot pada dua kelas di tahun pelajaran yang sama (KP-2.1/BR-04)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $kelasLain = Kelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'tingkat' => 'X',
        'jurusan_id' => $a['jurusan']->id,
        'nama' => 'X TKJ 2',
    ]);
    $siswa = Siswa::factory()->create(['status' => 'aktif']);

    $this->actingAs($admin)->postJson('/api/v1/plotting-kelas', [
        'kelas_id' => $a['kelas']->id,
        'siswa_ids' => [$siswa->id],
    ])->assertCreated()->assertJsonPath('data.dibuat', 1);

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas', [
        'kelas_id' => $kelasLain->id,
        'siswa_ids' => [$siswa->id],
    ]);

    $respons->assertOk()->assertJsonPath('data.dibuat', 0);
    expect($respons->json('data.dilewati.0.alasan'))->toContain('BR-04');

    // Hanya satu baris plotting yang boleh ada.
    expect(PlottingKelas::where('siswa_id', $siswa->id)->count())->toBe(1)
        ->and(PlottingKelas::where('siswa_id', $siswa->id)->first()?->kelas_id)->toBe($a['kelas']->id);
});

it('menolak menempatkan siswa yang tidak aktif (FR-PLK-07)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $lulus = Siswa::factory()->lulus()->create();

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas', [
        'kelas_id' => $a['kelas']->id,
        'siswa_ids' => [$lulus->id],
    ]);

    $respons->assertOk()->assertJsonPath('data.dibuat', 0);
    expect($respons->json('data.dilewati.0.alasan'))->toContain('aktif');
    expect(PlottingKelas::count())->toBe(0);
});

it('mendaftarkan siswa aktif yang belum terplot (FR-PLK-08)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $terplot = Siswa::factory()->create(['status' => 'aktif', 'nama' => 'Sudah Terplot']);
    Siswa::factory()->create(['status' => 'aktif', 'nama' => 'Belum Terplot']);
    Siswa::factory()->lulus()->create(['nama' => 'Sudah Lulus']);

    PlottingKelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'siswa_id' => $terplot->id,
        'kelas_id' => $a['kelas']->id,
    ]);

    $respons = $this->actingAs($admin)->getJson('/api/v1/plotting-kelas/belum-terplot?tahun_pelajaran_id='.$a['tahun']->id);

    $respons->assertOk()->assertJsonCount(1, 'data');
    expect($respons->json('data.0.nama'))->toBe('Belum Terplot');
});

it('menyajikan ringkasan jumlah siswa per kelas beserta L/P (FR-PLK-08)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    foreach (['L', 'L', 'P'] as $jk) {
        PlottingKelas::factory()->create([
            'tahun_pelajaran_id' => $a['tahun']->id,
            'siswa_id' => Siswa::factory()->create(['jenis_kelamin' => $jk, 'status' => 'aktif'])->id,
            'kelas_id' => $a['kelas']->id,
        ]);
    }

    $respons = $this->actingAs($admin)->getJson('/api/v1/plotting-kelas/ringkasan?tahun_pelajaran_id='.$a['tahun']->id);

    $respons->assertOk()->assertJsonPath('data.0.jumlah', 3);
    expect($respons->json('data.0.jumlah_l'))->toBe(2)
        ->and($respons->json('data.0.jumlah_p'))->toBe(1);
});

/** FR-PLK-05 — mutasi kelas dalam tahun berjalan; alasan wajib. */
it('memindahkan siswa ke kelas lain dan mencatat alasan mutasi', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $tujuan = Kelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'tingkat' => 'X',
        'jurusan_id' => $a['jurusan']->id,
        'nama' => 'X RPL 1',
    ]);

    $siswa = Siswa::factory()->create(['status' => 'aktif']);
    $plotting = PlottingKelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'siswa_id' => $siswa->id,
        'kelas_id' => $a['kelas']->id,
    ]);

    $this->actingAs($admin)->postJson("/api/v1/plotting-kelas/{$plotting->id}/mutasi", [
        'kelas_tujuan_id' => $tujuan->id,
        'tanggal' => now()->toDateString(),
        'alasan' => 'Penyesuaian jumlah rombel',
    ])->assertCreated();

    expect($plotting->refresh()->kelas_id)->toBe($tujuan->id)
        ->and(MutasiKelas::count())->toBe(1)
        ->and(MutasiKelas::first()?->kelas_asal_id)->toBe($a['kelas']->id)
        ->and(MutasiKelas::first()?->kelas_tujuan_id)->toBe($tujuan->id);

    $this->assertDatabaseHas('audit_log', ['aksi' => 'mutasi_kelas']);
});

it('menolak mutasi tanpa alasan (FR-PLK-05)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $tujuan = Kelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'tingkat' => 'X',
        'jurusan_id' => $a['jurusan']->id,
        'nama' => 'X RPL 1',
    ]);
    $plotting = PlottingKelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'kelas_id' => $a['kelas']->id,
        'siswa_id' => Siswa::factory()->create(['status' => 'aktif'])->id,
    ]);

    $this->actingAs($admin)->postJson("/api/v1/plotting-kelas/{$plotting->id}/mutasi", [
        'kelas_tujuan_id' => $tujuan->id,
        'tanggal' => now()->toDateString(),
        'alasan' => '',
    ])->assertStatus(422)->assertJsonPath('errors.alasan.0', 'Alasan mutasi wajib diisi.');

    expect($plotting->refresh()->kelas_id)->toBe($a['kelas']->id);
});

it('menolak mutasi ke kelas pada tahun pelajaran berbeda', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $tahunLain = TahunPelajaran::factory()->create(['nama' => '2031/2032']);
    $kelasLain = Kelas::factory()->create([
        'tahun_pelajaran_id' => $tahunLain->id,
        'tingkat' => 'X',
        'nama' => 'X TKJ 9',
    ]);

    $plotting = PlottingKelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'kelas_id' => $a['kelas']->id,
        'siswa_id' => Siswa::factory()->create(['status' => 'aktif'])->id,
    ]);

    $this->actingAs($admin)->postJson("/api/v1/plotting-kelas/{$plotting->id}/mutasi", [
        'kelas_tujuan_id' => $kelasLain->id,
        'tanggal' => now()->toDateString(),
        'alasan' => 'Coba pindah tahun',
    ])->assertStatus(422);

    expect($plotting->refresh()->kelas_id)->toBe($a['kelas']->id);
});

it('menyajikan riwayat kelas seorang siswa (FR-PLK-08)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $siswa = Siswa::factory()->create(['status' => 'aktif']);

    PlottingKelas::factory()->create([
        'tahun_pelajaran_id' => $a['tahun']->id,
        'siswa_id' => $siswa->id,
        'kelas_id' => $a['kelas']->id,
    ]);

    $this->actingAs($admin)->getJson("/api/v1/siswa/{$siswa->id}/riwayat-kelas")
        ->assertOk()
        ->assertJsonPath('data.riwayat.0.kelas', $a['kelas']->nama)
        ->assertJsonPath('data.siswa.nama', $siswa->nama);
});

it('mengimport penempatan siswa dari berkas Excel (FR-PLK-01)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $satu = Siswa::factory()->create(['nis' => '8800001', 'status' => 'aktif']);
    $dua = Siswa::factory()->create(['nis' => '8800002', 'status' => 'aktif']);

    $berkas = berkasExcel(['nis', 'nama_kelas'], [
        ['8800001', $a['kelas']->nama],
        ['8800002', 'Kelas Tidak Ada'],
    ]);

    $respons = $this->actingAs($admin)->postJson(
        '/api/v1/plotting-kelas/import?tahun_pelajaran_id='.$a['tahun']->id,
        ['berkas' => $berkas],
    );

    $respons->assertOk()->assertJsonPath('data.berhasil', 1)->assertJsonPath('data.gagal', 1);
    expect(PlottingKelas::where('siswa_id', $satu->id)->count())->toBe(1)
        ->and(PlottingKelas::where('siswa_id', $dua->id)->count())->toBe(0);
});

it('membatasi plotting kelas hanya untuk admin (matriks Bagian 2)', function (): void {
    $a = siapkanAkademik();

    foreach ([sebagaiKepsek(), sebagaiWakasek()] as $pengguna) {
        // Kepala sekolah & wakasek boleh melihat, tetapi tidak boleh menempatkan siswa.
        $this->actingAs($pengguna)->getJson('/api/v1/plotting-kelas')->assertOk();
        $this->actingAs($pengguna)->postJson('/api/v1/plotting-kelas', [
            'kelas_id' => $a['kelas']->id,
            'siswa_ids' => [Siswa::factory()->create(['status' => 'aktif'])->id],
        ])->assertStatus(403);
    }

    // Guru sama sekali tidak punya akses.
    $this->actingAs(buatPegawaiDenganAkun())->getJson('/api/v1/plotting-kelas')->assertStatus(403);
});
