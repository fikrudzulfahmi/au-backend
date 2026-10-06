<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use App\Support\PenjagaHapus;

beforeEach(function (): void {
    siapkanPeran();
});

it('melakukan CRUD jurusan dan mencatat audit log', function (): void {
    $admin = sebagaiAdmin();

    $id = $this->actingAs($admin)
        ->postJson('/api/v1/jurusan', ['kode' => 'TKJ', 'nama' => 'Teknik Komputer dan Jaringan'])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($admin)->putJson("/api/v1/jurusan/{$id}", [
        'kode' => 'TKJ', 'nama' => 'Teknik Komputer Jaringan',
    ])->assertOk()->assertJsonPath('data.nama', 'Teknik Komputer Jaringan');

    $this->actingAs($admin)->getJson('/api/v1/jurusan?cari=TKJ')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($admin)->deleteJson("/api/v1/jurusan/{$id}")->assertOk();

    $this->assertSoftDeleted('jurusan', ['id' => $id]);
    $this->assertDatabaseHas('audit_log', ['aksi' => 'buat_data']);
    $this->assertDatabaseHas('audit_log', ['aksi' => 'ubah_data']);
    $this->assertDatabaseHas('audit_log', ['aksi' => 'hapus_data']);
});

it('menolak menghapus jurusan yang masih dipakai kelas', function (): void {
    $admin = sebagaiAdmin();
    $jurusan = Jurusan::factory()->create();
    Kelas::factory()->create(['jurusan_id' => $jurusan->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/jurusan/{$jurusan->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KONFLIK_DATA');
});

it('melakukan CRUD mata pelajaran dengan soft delete', function (): void {
    $admin = sebagaiAdmin();
    $jurusan = Jurusan::factory()->create();

    $id = $this->actingAs($admin)->postJson('/api/v1/mapel', [
        'kode' => 'PWEB',
        'nama' => 'Pemrograman Web',
        'kelompok' => Mapel::KELOMPOK_KEJURUAN,
        'jurusan_id' => $jurusan->id,
    ])->assertCreated()->assertJsonPath('data.kelompok', 'kejuruan')->json('data.id');

    $this->actingAs($admin)->putJson("/api/v1/mapel/{$id}", [
        'kode' => 'PWEB', 'nama' => 'Pemrograman Web dan Perangkat Bergerak',
        'kelompok' => Mapel::KELOMPOK_KEJURUAN, 'jurusan_id' => $jurusan->id,
    ])->assertOk();

    $this->actingAs($admin)->deleteJson("/api/v1/mapel/{$id}")->assertOk();
    $this->assertSoftDeleted('mapel', ['id' => $id]);
});

it('menolak kelompok mapel yang tidak dikenali', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/mapel', [
        'kode' => 'X', 'nama' => 'Mapel X', 'kelompok' => 'entah',
    ])->assertStatus(422)->assertJsonPath('errors.kelompok.0', 'Kelompok mapel harus umum, kejuruan, atau muatan_lokal.');
});

it('melakukan CRUD siswa dengan pencarian dan filter status', function (): void {
    $admin = sebagaiAdmin();
    Siswa::factory()->create(['nama' => 'Ahmad Fauzi', 'status' => 'aktif']);
    Siswa::factory()->lulus()->create(['nama' => 'Budi Lulus']);

    $id = $this->actingAs($admin)->postJson('/api/v1/siswa', [
        'nis' => '9999999', 'nisn' => '9999999999', 'nama' => 'Siswa Baru',
        'jenis_kelamin' => 'L', 'status' => 'aktif', 'tahun_masuk' => 2026,
    ])->assertCreated()->json('data.id');

    $this->actingAs($admin)->getJson('/api/v1/siswa?cari=Ahmad')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama', 'Ahmad Fauzi');

    $this->actingAs($admin)->getJson('/api/v1/siswa?status=lulus')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nama', 'Budi Lulus');

    $this->actingAs($admin)->putJson("/api/v1/siswa/{$id}", [
        'nis' => '9999999', 'nama' => 'Siswa Diubah', 'jenis_kelamin' => 'L', 'status' => 'aktif',
    ])->assertOk()->assertJsonPath('data.nama', 'Siswa Diubah');

    $this->actingAs($admin)->deleteJson("/api/v1/siswa/{$id}")->assertOk();
    $this->assertSoftDeleted('siswa', ['id' => $id]);
});

it('menolak NIS dan NISN yang sudah terdaftar', function (): void {
    $admin = sebagaiAdmin();
    Siswa::factory()->create(['nis' => '8888888', 'nisn' => '8888888888']);

    $this->actingAs($admin)->postJson('/api/v1/siswa', [
        'nis' => '8888888', 'nama' => 'Ganda', 'jenis_kelamin' => 'L', 'status' => 'aktif',
    ])->assertStatus(422)->assertJsonPath('errors.nis.0', 'NIS sudah terdaftar.');
});

/**
 * Penjaga penghapusan diuji dengan tabel yang benar-benar ada.
 * Tabel rujukan fase berikutnya (plotting_mapel, presensi_siswa, jurnal) membuat
 * penjaga yang sama otomatis aktif tanpa perubahan kode.
 */
it('penjaga hapus mendeteksi data yang masih dirujuk', function (): void {
    $jurusan = Jurusan::factory()->create();

    expect(PenjagaHapus::periksa($jurusan->id, [
        ['kelas', 'jurusan_id', 'kelas'],
    ]))->toBeNull();

    Kelas::factory()->create(['jurusan_id' => $jurusan->id]);

    expect(PenjagaHapus::periksa($jurusan->id, [
        ['kelas', 'jurusan_id', 'kelas'],
    ]))->toContain('kelas');
});

it('mengabaikan tabel rujukan yang belum ada sehingga penghapusan tetap berjalan', function (): void {
    $siswa = Siswa::factory()->create();

    // `presensi_siswa` baru dibuat pada Fase 4 — penjaga harus melewatinya, bukan galat.
    expect(PenjagaHapus::periksa($siswa->id, [
        ['presensi_siswa', 'siswa_id', 'presensi siswa'],
    ]))->toBeNull();
});

it('menyajikan audit log dan daftar aksi yang dapat difilter', function (): void {
    $admin = sebagaiAdmin();
    AuditLog::create([
        'user_id' => $admin->id, 'aksi' => 'uji_aksi', 'waktu' => now(), 'ip' => '127.0.0.1',
    ]);

    $this->actingAs($admin)->getJson('/api/v1/pengaturan/audit-log')
        ->assertOk()->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total']]);

    $this->actingAs($admin)->getJson('/api/v1/pengaturan/audit-log?aksi=uji_aksi')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.aksi', 'uji_aksi');

    $this->actingAs($admin)->getJson('/api/v1/pengaturan/audit-log/aksi')
        ->assertOk()->assertJsonPath('data.0', 'uji_aksi');
});

it('memperbarui peran akun pengguna dan mencatatnya (A-11)', function (): void {
    $admin = sebagaiAdmin();
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $user = User::factory()->create([
        'pegawai_id' => $pegawai->id, 'username' => $pegawai->nip, 'name' => $pegawai->nama,
    ]);
    $user->roles()->sync([Role::where('kode', 'guru')->value('id')]);

    $this->actingAs($admin)->putJson("/api/v1/pengaturan/pengguna/{$user->id}", [
        'peran' => ['guru', 'wakasek_kurikulum'],
    ])->assertOk();

    expect($user->refresh()->kodePeran())->toEqualCanonicalizing(['guru', 'wakasek_kurikulum']);
    $this->assertDatabaseHas('audit_log', ['aksi' => 'ubah_data']);
});

it('menolak akun tanpa peran', function (): void {
    $admin = sebagaiAdmin();
    $user = User::factory()->create(['username' => 'tanpa-peran']);

    $this->actingAs($admin)->putJson("/api/v1/pengaturan/pengguna/{$user->id}", [
        'peran' => [],
    ])->assertStatus(422);
});
