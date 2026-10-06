<?php

declare(strict_types=1);

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Pegawai;
use App\Models\TahunPelajaran;

beforeEach(function (): void {
    siapkanPeran();
});

/** KP-1.2 — satu guru tidak dapat menjadi wali dua kelas pada tahun pelajaran yang sama (BR-02). */
it('menolak guru yang sama menjadi wali dua kelas pada tahun pelajaran yang sama (BR-02)', function (): void {
    $admin = sebagaiAdmin();
    $tahun = tahunAktif();
    $jurusan = Jurusan::factory()->create();
    $guru = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $this->actingAs($admin)->postJson('/api/v1/kelas', [
        'tahun_pelajaran_id' => $tahun->id,
        'nama' => 'X TKJ 1',
        'tingkat' => 'X',
        'jurusan_id' => $jurusan->id,
        'wali_kelas_id' => $guru->id,
    ])->assertCreated();

    $respons = $this->actingAs($admin)->postJson('/api/v1/kelas', [
        'tahun_pelajaran_id' => $tahun->id,
        'nama' => 'X TKJ 2',
        'tingkat' => 'X',
        'jurusan_id' => $jurusan->id,
        'wali_kelas_id' => $guru->id,
    ]);

    $respons->assertStatus(422);
    expect($respons->json('errors.wali_kelas_id.0'))->toContain('BR-02');
    expect(Kelas::count())->toBe(1);
});

it('mengizinkan guru yang sama menjadi wali kelas pada tahun pelajaran berbeda', function (): void {
    $admin = sebagaiAdmin();
    $guru = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $jurusan = Jurusan::factory()->create();

    $tahunA = tahunAktif();
    $tahunB = TahunPelajaran::factory()->create(['nama' => '2027/2028']);

    foreach ([$tahunA, $tahunB] as $tahun) {
        $this->actingAs($admin)->postJson('/api/v1/kelas', [
            'tahun_pelajaran_id' => $tahun->id,
            'nama' => 'X TKJ 1',
            'tingkat' => 'X',
            'jurusan_id' => $jurusan->id,
            'wali_kelas_id' => $guru->id,
        ])->assertCreated();
    }

    expect(Kelas::count())->toBe(2);
});

it('menolak nama kelas yang sama pada satu tahun pelajaran', function (): void {
    $admin = sebagaiAdmin();
    $tahun = tahunAktif();
    $jurusan = Jurusan::factory()->create();

    Kelas::factory()->create([
        'tahun_pelajaran_id' => $tahun->id, 'nama' => 'X TKJ 1', 'jurusan_id' => $jurusan->id,
    ]);

    $this->actingAs($admin)->postJson('/api/v1/kelas', [
        'tahun_pelajaran_id' => $tahun->id,
        'nama' => 'X TKJ 1',
        'tingkat' => 'X',
        'jurusan_id' => $jurusan->id,
    ])->assertStatus(422);
});

it('menolak wali kelas yang bukan guru', function (): void {
    $admin = sebagaiAdmin();
    $tahun = tahunAktif();
    $jurusan = Jurusan::factory()->create();
    $struktural = Pegawai::factory()->struktural()->create();

    $this->actingAs($admin)->postJson('/api/v1/kelas', [
        'tahun_pelajaran_id' => $tahun->id,
        'nama' => 'X TKJ 1',
        'tingkat' => 'X',
        'jurusan_id' => $jurusan->id,
        'wali_kelas_id' => $struktural->id,
    ])->assertStatus(422);
});

it('menolak tingkat kelas yang tidak dikenali', function (): void {
    $admin = sebagaiAdmin();
    $tahun = tahunAktif();
    $jurusan = Jurusan::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/kelas', [
        'tahun_pelajaran_id' => $tahun->id,
        'nama' => 'XIII TKJ 1',
        'tingkat' => 'XIII',
        'jurusan_id' => $jurusan->id,
    ])->assertStatus(422);
});

/** FR-KLS-04 — salin kelas dari tahun pelajaran lain dengan penyesuaian tingkat. */
it('menyalin kelas dengan penyesuaian tingkat dan melewati kelas XII', function (): void {
    $admin = sebagaiAdmin();
    $jurusan = Jurusan::factory()->create();

    $asal = TahunPelajaran::factory()->create(['nama' => '2025/2026']);
    $tujuan = TahunPelajaran::factory()->create(['nama' => '2026/2027']);

    foreach ([['X TKJ 1', 'X'], ['XI TKJ 1', 'XI'], ['XII TKJ 1', 'XII']] as [$nama, $tingkat]) {
        Kelas::factory()->create([
            'tahun_pelajaran_id' => $asal->id, 'nama' => $nama, 'tingkat' => $tingkat, 'jurusan_id' => $jurusan->id,
        ]);
    }

    $respons = $this->actingAs($admin)->postJson('/api/v1/kelas/salin', [
        'tahun_pelajaran_asal_id' => $asal->id,
        'tahun_pelajaran_id' => $tujuan->id,
    ]);

    $respons->assertOk()->assertJsonPath('data.dibuat', 2);

    $namaDiTujuan = Kelas::where('tahun_pelajaran_id', $tujuan->id)->pluck('nama')->sort()->values()->all();
    expect($namaDiTujuan)->toBe(['XI TKJ 1', 'XII TKJ 1'])
        ->and(Kelas::where('tahun_pelajaran_id', $tujuan->id)->where('tingkat', 'XII')->count())->toBe(1);

    // Kelas tujuan tidak mewarisi wali kelas (penugasan berbeda tiap tahun, A-04).
    expect(Kelas::where('tahun_pelajaran_id', $tujuan->id)->whereNotNull('wali_kelas_id')->count())->toBe(0);
});
