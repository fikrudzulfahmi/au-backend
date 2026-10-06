<?php

declare(strict_types=1);

use App\Models\ProfilSekolah;

/**
 * FR-SCH-02 / BR-34 — endpoint publik hanya memuat info sekolah,
 * tanpa data pegawai, siswa, atau kehadiran.
 */
it('memberi 404 bila info sekolah belum diisi', function (): void {
    $this->getJson('/api/v1/publik/sekolah')
        ->assertStatus(404)
        ->assertJsonPath('code', 'INFO_SEKOLAH_KOSONG');
});

it('mengembalikan info sekolah untuk landing page', function (): void {
    ProfilSekolah::factory()->create();

    $respons = $this->getJson('/api/v1/publik/sekolah');

    $respons->assertOk()
        ->assertJsonStructure(['data' => [
            'nama_sekolah',
            'npsn',
            'tagline',
            'nama_kepala_sekolah',
            'alamat_lengkap',
            'media_sosial',
            'landing' => ['aktif', 'judul_hero', 'tampilkan_peta', 'tampilkan_pengumuman'],
        ]]);
});

it('tidak memuat data pegawai, siswa, maupun kehadiran (BR-34)', function (): void {
    ProfilSekolah::factory()->create();

    $isi = json_encode($this->getJson('/api/v1/publik/sekolah')->json('data'), JSON_THROW_ON_ERROR);

    foreach (['pegawai', 'siswa', 'presensi', 'hadir', 'terlambat', 'no_hp', 'alasan'] as $terlarang) {
        expect($isi)->not->toContain($terlarang);
    }
});

it('tidak memerlukan autentikasi', function (): void {
    ProfilSekolah::factory()->create();

    $this->getJson('/api/v1/publik/sekolah')->assertOk();
});
