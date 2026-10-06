<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Jurnal;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\PresensiPegawai;
use App\Models\Role;
use App\Models\Semester;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\JurnalService;

/*
|--------------------------------------------------------------------------
| Fase 4 — Jurnal pembelajaran (KP-4.1, KP-4.2, KP-4.4, KP-4.5)
|--------------------------------------------------------------------------
| FR-JRN-01..09, BR-19..BR-21.
*/

beforeEach(function (): void {
    siapkanPeran();
});

// =====================================================================
// KP-4.1 / BR-19 / FR-JRN-04 — gerbang presensi masuk
// =====================================================================

it('menolak jurnal bila guru belum presensi masuk pada tanggal itu', function () {
    $r = siapkanJurnal(denganPresensi: false);

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BELUM_PRESENSI_MASUK')
        ->assertJsonPath('message', 'Lakukan presensi masuk terlebih dahulu.');

    // KP-4.1 menuntut penegakan di server: tidak boleh ada jurnal yang tersimpan.
    expect(Jurnal::count())->toBe(0);
});

it('membuka pengisian jurnal untuk presensi masuk yang dihitung (A-01)', function (string $validasi) {
    $r = siapkanJurnal(validasi: $validasi);

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated();

    expect(Jurnal::count())->toBe(1);
})->with([
    'valid' => PresensiPegawai::VALID,
    'disetujui' => PresensiPegawai::DISETUJUI,
    'menunggu (luar radius)' => PresensiPegawai::MENUNGGU,
]);

it('menolak jurnal bila presensi masuknya ditolak', function () {
    $r = siapkanJurnal(validasi: PresensiPegawai::DITOLAK);

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BELUM_PRESENSI_MASUK');

    expect(Jurnal::count())->toBe(0);
});

// =====================================================================
// KP-4.2 / FR-JRN-01 — sesi terbentuk dari jadwal, yang berurutan digabung
// =====================================================================

it('menggabungkan jadwal berurutan pada plotting yang sama menjadi satu sesi', function () {
    $r = siapkanJurnal(jamKe: [1, 2, 3]);

    $sesi = app(JurnalService::class)->sesi($r['guru'], $r['semester'], seninUji());

    expect($sesi)->toHaveCount(1)
        ->and($sesi[0]['jam_ke_mulai'])->toBe(1)
        ->and($sesi[0]['jam_ke_selesai'])->toBe(3)
        ->and($sesi[0]['label_jam'])->toBe('Jam ke-1-3');
});

it('tidak menggabungkan jam yang tidak berurutan', function () {
    $r = siapkanJurnal(jamKe: [1, 3]);

    $sesi = app(JurnalService::class)->sesi($r['guru'], $r['semester'], seninUji());

    // Jam 1 dan jam 3 bukan sesi yang sama walau plottingnya identik.
    expect($sesi)->toHaveCount(2)
        ->and($sesi[0]['jam_ke_mulai'])->toBe(1)
        ->and($sesi[0]['jam_ke_selesai'])->toBe(1)
        ->and($sesi[1]['jam_ke_mulai'])->toBe(3);
});

it('tidak menggabungkan dua plotting mapel berbeda pada jam berurutan', function () {
    $r = siapkanJurnal(jamKe: [1]);

    // Plotting mapel kedua untuk guru yang sama, pada jam ke-2 (berurutan).
    $mapelLain = Mapel::factory()->create();
    $plottingLain = PlottingMapel::factory()->create([
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $r['guru']->id,
        'mapel_id' => $mapelLain->id,
        'kelas_id' => $r['kelas']->id,
    ]);

    addJadwal($r, $plottingLain->id, 2);

    $sesi = app(JurnalService::class)->sesi($r['guru'], $r['semester'], seninUji());

    expect($sesi)->toHaveCount(2)
        ->and($sesi[0]['jam_ke_selesai'])->toBe(1)
        ->and($sesi[1]['jam_ke_mulai'])->toBe(2);
});

it('menolak jurnal untuk sesi yang bukan pada jadwal guru', function () {
    $r = siapkanJurnal();

    // Plotting mapel milik guru lain.
    $guruLain = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $plottingLain = PlottingMapel::factory()->create([
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $guruLain->id,
        'mapel_id' => Mapel::factory()->create()->id,
        'kelas_id' => $r['kelas']->id,
    ]);

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all(), [
            'plotting_mapel_id' => $plottingLain->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SESI_BUKAN_MILIK_ANDA');

    expect(Jurnal::count())->toBe(0);
});

it('mengambil jam selesai dari jadwal, bukan dari kiriman klien', function () {
    $r = siapkanJurnal(jamKe: [1, 2, 3]);

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all(), [
            // Klien mencoba mempersempit sesi; server harus mengabaikannya.
            'jam_ke_selesai' => 1,
        ]))
        ->assertCreated();

    $jurnal = Jurnal::first();

    expect($jurnal->jam_ke_mulai)->toBe(1)
        ->and($jurnal->jam_ke_selesai)->toBe(3);
});

// =====================================================================
// KP-4.5 / BR-21 / FR-JRN-07 — satu sesi satu jurnal
// =====================================================================

it('menolak jurnal kedua pada sesi yang sama', function () {
    $r = siapkanJurnal();
    $badan = badanJurnal($r, $r['siswa']->all());

    $this->actingAs($r['user'])->postJson('/api/v1/jurnal', $badan)->assertCreated();

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', $badan)
        ->assertStatus(409)
        ->assertJsonPath('code', 'JURNAL_SUDAH_ADA');

    expect(Jurnal::count())->toBe(1);
});

it('menandai sesi yang sudah diisi sebagai sudah pada daftar sesi', function () {
    $r = siapkanJurnal();

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated();

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.sesi.0.status', JurnalService::SUDAH)
        ->assertJsonPath('data.sesi.0.boleh_isi', false)
        ->assertJsonPath('data.ringkasan.sudah', 1)
        ->assertJsonPath('data.ringkasan.belum', 0);
});

// =====================================================================
// KP-4.4 / BR-20 / FR-JRN-05 — edit kapan pun, perubahan tercatat
// =====================================================================

it('mengizinkan pemilik mengubah jurnal kapan pun tanpa batas waktu', function () {
    $r = siapkanJurnal();
    $badan = badanJurnal($r, $r['siswa']->all());

    $id = $this->actingAs($r['user'])->postJson('/api/v1/jurnal', $badan)->json('data.id');

    // Jauh melewati akhir semester: BR-20 menegaskan tidak ada batas waktu.
    $this->travel(120)->days();

    $this->actingAs($r['user'])
        ->putJson('/api/v1/jurnal/'.$id, [
            'materi' => 'Materi setelah direvisi',
            'kegiatan' => 'Kegiatan setelah direvisi.',
            'catatan' => 'Ada kendala proyektor.',
        ])
        ->assertOk();

    $jurnal = Jurnal::find($id);

    expect($jurnal->materi)->toBe('Materi setelah direvisi')
        ->and($jurnal->catatan)->toBe('Ada kendala proyektor.')
        ->and($jurnal->diubah_oleh)->toBe($r['user']->id);
});

it('mencatat nilai lama dan baru setiap kali jurnal diubah', function () {
    $r = siapkanJurnal();
    $badan = badanJurnal($r, $r['siswa']->all());

    $id = $this->actingAs($r['user'])->postJson('/api/v1/jurnal', $badan)->json('data.id');

    $this->actingAs($r['user'])
        ->putJson('/api/v1/jurnal/'.$id, [
            'materi' => 'Materi baru',
            'kegiatan' => 'Kegiatan baru.',
        ])
        ->assertOk();

    $catatan = AuditLog::query()
        ->where('aksi', AuditLogService::AKSI_UBAH_JURNAL)
        ->where('objek_id', $id)
        ->first();

    expect($catatan)->not->toBeNull()
        ->and($catatan->user_id)->toBe($r['user']->id)
        // Ringkasan lama-baru harus benar-benar memuat perubahan, bukan kosong.
        ->and($catatan->data_lama['materi'])->toBe('Materi uji jurnal')
        ->and($catatan->data_baru['materi'])->toBe('Materi baru');
});

it('mencatat pembuatan jurnal di audit log', function () {
    $r = siapkanJurnal();

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated();

    expect(AuditLog::query()->where('aksi', AuditLogService::AKSI_ISI_JURNAL)->count())->toBe(1);
});

// =====================================================================
// Otorisasi (matriks Bagian 2: admin K**, guru K(S), kepsek & wakasek -)
// =====================================================================

it('menolak kepala sekolah dan wakasek mengakses jurnal', function (string $peran) {
    $r = siapkanJurnal();
    $pengguna = buatPengguna([$peran]);

    $this->actingAs($pengguna)->getJson('/api/v1/jurnal')->assertStatus(403);
    $this->actingAs($pengguna)->getJson('/api/v1/jurnal/sesi-hari-ini')->assertStatus(403);
})->with([Role::KEPALA_SEKOLAH, Role::WAKASEK_KURIKULUM]);

it('menolak guru membuka jurnal milik guru lain', function () {
    $r = siapkanJurnal();
    $id = $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->json('data.id');

    $guruLain = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $akunLain = User::factory()->create(['pegawai_id' => $guruLain->id]);
    $akunLain->roles()->sync(Role::query()->where('kode', Role::GURU)->pluck('id')->all());

    $this->actingAs($akunLain->fresh(['roles', 'pegawai']))
        ->getJson('/api/v1/jurnal/'.$id)
        ->assertStatus(403);
});

it('mengizinkan admin membuka dan mengoreksi jurnal guru', function () {
    $r = siapkanJurnal();
    $id = $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->json('data.id');

    $admin = sebagaiAdmin();

    $this->actingAs($admin)->getJson('/api/v1/jurnal/'.$id)->assertOk();

    $this->actingAs($admin)
        ->putJson('/api/v1/jurnal/'.$id, [
            'materi' => 'Dikoreksi admin',
            'kegiatan' => 'Kegiatan hasil koreksi.',
        ])
        ->assertOk();

    expect(Jurnal::find($id)->materi)->toBe('Dikoreksi admin');
});

it('menolak pengguna tanpa data pegawai membuat jurnal', function () {
    $r = siapkanJurnal();

    // Admin memang boleh mengoreksi, tetapi membuat jurnal menuntut data pegawai.
    $this->actingAs(sebagaiAdmin())
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertStatus(422);
});

// =====================================================================
// FR-JRN-02 / A-12 — foto kegiatan
// =====================================================================

it('menyimpan paling banyak tiga foto dan menolak yang keempat', function () {
    $r = siapkanJurnal();
    $badan = badanJurnal($r, $r['siswa']->all());

    $this->actingAs($r['user'])
        ->post('/api/v1/jurnal', [...$badan, 'foto' => [fotoUji(), fotoUji(), fotoUji()]])
        ->assertCreated();

    expect(Jurnal::first()->foto()->count())->toBe(3);

    // Sesi lain (jam ke-4 belum ada di jadwal, jadi pakai hari berbeda lewat sesi baru).
    $r2 = siapkanJurnal(jamKe: [4]);

    $this->actingAs($r2['user'])
        ->post('/api/v1/jurnal', [
            ...badanJurnal($r2, $r2['siswa']->all()),
            'jam_ke_mulai' => 4,
            'foto' => [fotoUji(), fotoUji(), fotoUji(), fotoUji()],
        ])
        ->assertStatus(422);
});

// =====================================================================
// FR-JRN-09 — riwayat
// =====================================================================

it('menampilkan riwayat jurnal hanya milik guru yang masuk', function () {
    $r = siapkanJurnal();
    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated();

    $guruLain = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $akunLain = User::factory()->create(['pegawai_id' => $guruLain->id]);
    $akunLain->roles()->sync(Role::query()->where('kode', Role::GURU)->pluck('id')->all());

    $this->actingAs($r['user'])->getJson('/api/v1/jurnal')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($akunLain->fresh(['roles', 'pegawai']))
        ->getJson('/api/v1/jurnal')->assertOk()->assertJsonCount(0, 'data');
});

// =====================================================================
// Semester aktif wajib ada
// =====================================================================

it('menolak permintaan bila belum ada semester aktif', function () {
    $r = siapkanJurnal();

    Semester::query()->update(['is_active' => false]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini')
        ->assertStatus(422)
        ->assertJsonPath('code', 'TANPA_SEMESTER_AKTIF');
});
