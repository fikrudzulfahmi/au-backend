<?php

declare(strict_types=1);

use App\Models\ProfilSekolah;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    siapkanPeran();
});

/** KP-1.6 — Info Sekolah menolak NPSN yang bukan 8 digit angka. */
it('menolak NPSN yang bukan 8 digit angka', function (): void {
    $admin = sebagaiAdmin();

    foreach (['1234567', '123456789', '1234567a'] as $npsn) {
        $respons = $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
            'nama_sekolah' => 'SMK Contoh',
            'nama_kepala_sekolah' => 'Drs. Contoh',
            'npsn' => $npsn,
        ]);

        $respons->assertStatus(422);
        expect($respons->json('errors.npsn.0'))->toBe('NPSN harus tepat 8 digit angka.');
    }

    expect(ProfilSekolah::count())->toBe(0);
});

it('menyimpan Info Sekolah dengan NPSN 8 digit', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'SMK Islam Anharul Ulum',
        'nama_kepala_sekolah' => 'Drs. H. Contoh, M.Pd.',
        'npsn' => '20512345',
        'tagline' => 'SIPANDU — Sistem Presensi & Jurnal Digital',
    ])->assertOk()->assertJsonPath('data.npsn', '20512345');

    $this->assertDatabaseHas('profil_sekolah', ['npsn' => '20512345']);
});

it('mengubah Info Sekolah dan mencatatnya di audit_log (FR-SCH-06)', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'SMA Contoh',
        'nama_kepala_sekolah' => 'Bapak Contoh',
        'npsn' => '20512345',
    ])->assertOk();

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'SMK Islam Anharul Ulum',
        'nama_kepala_sekolah' => 'Bapak Contoh',
        'npsn' => '20512345',
    ])->assertOk();

    $this->assertDatabaseHas('audit_log', ['aksi' => 'ubah_info_sekolah']);
    expect(ProfilSekolah::first()?->nama_sekolah)->toBe('SMK Islam Anharul Ulum');
});

it('hanya mengizinkan admin mengubah Info Sekolah', function (): void {
    $this->actingAs(sebagaiKepsek())->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'Sekolah Lain',
        'nama_kepala_sekolah' => 'Siapa pun',
        'npsn' => '20512345',
    ])->assertStatus(403);
});

it('mengompres logo hingga di bawah 300 KB (FR-SCH-04)', function (): void {
    Storage::fake('local');
    $admin = sebagaiAdmin();

    $logo = UploadedFile::fake()->image('logo.png', 2000, 2000);

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'SMK Contoh',
        'nama_kepala_sekolah' => 'Bapak Contoh',
        'npsn' => '20512345',
        'logo_kiri' => $logo,
    ])->assertOk();

    $berkas = Storage::disk('local')->files('sekolah');
    expect($berkas)->toHaveCount(1)
        ->and(Storage::disk('local')->size($berkas[0]))->toBeLessThanOrEqual(300 * 1024)
        ->and(ProfilSekolah::first()?->logo_kiri_path)->toBe($berkas[0]);
});

it('menolak gambar yang melebihi 1 MB sebelum kompresi', function (): void {
    Storage::fake('local');
    $admin = sebagaiAdmin();

    $besar = UploadedFile::fake()->image('besar.png', 3000, 3000)->size(1500);

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'SMK Contoh',
        'nama_kepala_sekolah' => 'Bapak Contoh',
        'npsn' => '20512345',
        'logo_kiri' => $besar,
    ])->assertStatus(422);
});

it('menampilkan Info Sekolah pada endpoint publik (FR-SCH-02)', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'SMK Islam Anharul Ulum',
        'nama_kepala_sekolah' => 'Bapak Contoh',
        'npsn' => '20512345',
        'alamat_jalan' => 'Jl. Pondok No. 17',
        'kecamatan' => 'Kademangan',
        'kabupaten_kota' => 'Blitar',
    ])->assertOk();

    $this->getJson('/api/v1/publik/sekolah')
        ->assertOk()
        ->assertJsonPath('data.nama_sekolah', 'SMK Islam Anharul Ulum')
        ->assertJsonPath('data.npsn', '20512345');
});

/** FR-SCH-03 — menjadikan kepala sekolah sebagai penandatangan default. */
it('menjadikan kepala sekolah sebagai penandatangan default', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/pengaturan/sekolah', [
        'nama_sekolah' => 'SMK Contoh',
        'nama_kepala_sekolah' => 'Drs. H. Contoh, M.Pd.',
        'nip_kepala_sekolah' => '197001012000031001',
        'npsn' => '20512345',
    ])->assertOk();

    $this->actingAs($admin)
        ->postJson('/api/v1/pengaturan/sekolah/penandatangan-default')
        ->assertOk()
        ->assertJsonPath('data.nama', 'Drs. H. Contoh, M.Pd.');

    $this->assertDatabaseHas('penandatangan', [
        'jabatan' => 'Kepala Sekolah',
        'nama' => 'Drs. H. Contoh, M.Pd.',
        'nip' => '197001012000031001',
        'is_default' => true,
    ]);
});

/** FR-SCH-05 — tab Landing Page pada halaman Info Sekolah. */
it('menyimpan pengaturan Landing Page dan hanya untuk admin', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->putJson('/api/v1/pengaturan/landing', [
        'landing_aktif' => true,
        'landing_judul_hero' => 'SIPANDU — Presensi & Jurnal Digital',
        'landing_tampilkan_peta' => false,
        'landing_tampilkan_pengumuman' => true,
    ])->assertOk();

    $this->actingAs($admin)->getJson('/api/v1/pengaturan/landing')
        ->assertOk()
        ->assertJsonPath('data.landing_judul_hero', 'SIPANDU — Presensi & Jurnal Digital')
        ->assertJsonPath('data.landing_tampilkan_peta', false);

    // Nilai ini juga tercermin pada respons publik untuk landing page.
    ProfilSekolah::factory()->create();
    $this->getJson('/api/v1/publik/sekolah')
        ->assertOk()
        ->assertJsonPath('data.landing.tampilkan_peta', false)
        ->assertJsonPath('data.landing.judul_hero', 'SIPANDU — Presensi & Jurnal Digital');

    $this->actingAs(sebagaiKepsek())
        ->putJson('/api/v1/pengaturan/landing', [
            'landing_aktif' => true,
            'landing_tampilkan_peta' => true,
            'landing_tampilkan_pengumuman' => true,
        ])
        ->assertStatus(403);
});

it('menyimpan pengaturan teknis presensi (FR-LOK-05)', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->putJson('/api/v1/pengaturan/sistem', [
        'gps_max_akurasi_m' => 35,
        'foto_max_sisi_px' => 720,
        'foto_kualitas_jpeg' => 60,
        'foto_target_maks_kb' => 120,
    ])->assertOk();

    $this->assertDatabaseHas('pengaturan', ['kunci' => 'gps_max_akurasi_m', 'nilai' => '35']);

    $this->actingAs($admin)->getJson('/api/v1/pengaturan/sistem')
        ->assertOk()
        ->assertJsonPath('data.gps_max_akurasi_m', 35)
        ->assertJsonPath('data.foto_target_maks_kb', 120);
});
