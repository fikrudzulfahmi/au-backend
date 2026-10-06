<?php

declare(strict_types=1);

use App\Models\Jurusan;
use App\Models\Pegawai;

beforeEach(function (): void {
    siapkanPeran();
});

/**
 * KP-1.5 — pengguna non-admin tidak dapat membuka halaman master.
 * Diuji lewat permintaan HTTP langsung, bukan hanya penyembunyian menu di UI.
 */
it('menolak guru mengakses master data', function (): void {
    $guru = buatPegawaiDenganAkun();

    foreach (['/api/v1/jurusan', '/api/v1/kelas', '/api/v1/siswa', '/api/v1/pegawai', '/api/v1/mapel', '/api/v1/tahun-pelajaran'] as $jalur) {
        $this->actingAs($guru)->getJson($jalur)->assertStatus(403);
    }
});

it('menolak pegawai struktural mengakses master data', function (): void {
    $pegawai = buatPegawaiDenganAkun(Pegawai::JENIS_STRUKTURAL);

    $this->actingAs($pegawai)->getJson('/api/v1/siswa')->assertStatus(403);
});

it('menolak permintaan master data tanpa token', function (): void {
    $this->getJson('/api/v1/jurusan')->assertStatus(401);
});

it('mengizinkan kepala sekolah dan wakasek MELIHAT master data', function (): void {
    foreach ([sebagaiKepsek(), sebagaiWakasek()] as $pengguna) {
        $this->actingAs($pengguna)->getJson('/api/v1/jurusan')->assertOk();
        $this->actingAs($pengguna)->getJson('/api/v1/kelas')->assertOk();
        $this->actingAs($pengguna)->getJson('/api/v1/siswa')->assertOk();
        $this->actingAs($pengguna)->getJson('/api/v1/pegawai')->assertOk();
    }
});

it('menolak kepala sekolah dan wakasek MENGUBAH master data', function (): void {
    foreach ([sebagaiKepsek(), sebagaiWakasek()] as $pengguna) {
        $this->actingAs($pengguna)
            ->postJson('/api/v1/jurusan', ['kode' => 'XYZ', 'nama' => 'Jurusan Baru'])
            ->assertStatus(403);
    }

    expect(Jurusan::count())->toBe(0);
});

it('mengizinkan admin mengelola master data', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/jurusan', ['kode' => 'TKJ', 'nama' => 'Teknik Komputer dan Jaringan'])
        ->assertCreated()
        ->assertJsonPath('data.kode', 'TKJ');
});

it('membatasi pengaturan, pengguna, dan audit log hanya untuk admin', function (): void {
    foreach (['/api/v1/pengaturan/sekolah', '/api/v1/pengaturan/sistem', '/api/v1/pengaturan/pengguna', '/api/v1/pengaturan/audit-log'] as $jalur) {
        $this->actingAs(sebagaiKepsek())->getJson($jalur)->assertStatus(403);
        $this->actingAs(sebagaiWakasek())->getJson($jalur)->assertStatus(403);
    }
});

it('menyajikan meta paginasi pada respons daftar (3.4)', function (): void {
    $admin = sebagaiAdmin();
    Jurusan::factory()->count(3)->create();

    $respons = $this->actingAs($admin)->getJson('/api/v1/jurusan?per_page=2');

    $respons->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total']])
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(2, 'data');
});
