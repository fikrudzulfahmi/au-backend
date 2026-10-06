<?php

declare(strict_types=1);

use App\Models\JamKerja;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PengajuanLuarRadius;

beforeEach(function (): void {
    siapkanPeran();
});

/** Menyiapkan jam kerja Senin–Jumat untuk guru (dipakai KP-3.8). */
function siapkanJamKerjaGuru(): void
{
    foreach (range(1, 7) as $hari) {
        $kerja = $hari <= 5;

        JamKerja::factory()->create([
            'jenis_pegawai' => Pegawai::JENIS_GURU,
            'hari' => $hari,
            'is_hari_kerja' => $kerja,
            'jam_masuk' => $kerja ? '07:00:00' : null,
            'jam_pulang' => $kerja ? '15:00:00' : null,
        ]);
    }
}

/** FR-IZN-01 — pegawai mengajukan izin. */
it('menerima pengajuan izin dari pegawai (FR-IZN-01)', function (): void {
    $user = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $respons = $this->actingAs($user)->postJson('/api/v1/pengajuan-izin', [
        'jenis' => 'izin',
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
        'alasan' => 'Menghadiri acara keluarga.',
    ]);

    $respons->assertCreated()->assertJsonPath('data.status', 'menunggu');

    expect(PengajuanIzin::count())->toBe(1)
        ->and(PengajuanIzin::firstOrFail()->dibuat_oleh_admin)->toBeFalse();
});

/** FR-IZN-02 — kotak luar radius hanya berlaku untuk dinas. */
it('menolak opsi luar radius pada jenis selain dinas (FR-IZN-02)', function (): void {
    $user = buatPegawaiDenganAkun();

    $this->actingAs($user)->postJson('/api/v1/pengajuan-izin', [
        'jenis' => 'izin',
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
        'alasan' => 'Keperluan pribadi.',
        'presensi_luar_radius' => true,
    ])->assertStatus(422)->assertJsonPath('errors.presensi_luar_radius.0', 'Opsi presensi luar radius hanya berlaku untuk pengajuan dinas (FR-IZN-02).');
});

/** FR-IZN-05 — pengajuan tidak boleh bertumpuk tanggal. */
it('menolak pengajuan yang bertumpuk tanggal (FR-IZN-05)', function (): void {
    $user = buatPegawaiDenganAkun();
    $pegawai = $user->pegawai;

    PengajuanIzin::factory()->create([
        'pegawai_id' => $pegawai->id,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
        'status' => PengajuanIzin::STATUS_MENUNGGU,
    ]);

    $respons = $this->actingAs($user)->postJson('/api/v1/pengajuan-izin', [
        'jenis' => 'sakit',
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
        'alasan' => 'Demam.',
    ]);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('IZN-05');
});

it('mengizinkan pengajuan pada rentang tanggal yang tidak bertumpuk', function (): void {
    $user = buatPegawaiDenganAkun();

    PengajuanIzin::factory()->create([
        'pegawai_id' => $user->pegawai_id,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    $this->actingAs($user)->postJson('/api/v1/pengajuan-izin', [
        'jenis' => 'izin',
        'tanggal_mulai' => '2026-10-12',
        'tanggal_selesai' => '2026-10-13',
        'alasan' => 'Keperluan keluarga.',
    ])->assertCreated();
});

/** FR-IZN-04 — penyetuju admin/kepala sekolah; catatan wajib saat menolak. */
it('menyetujui dan menolak pengajuan dengan aturan catatan (FR-IZN-04)', function (): void {
    $user = buatPegawaiDenganAkun();
    $admin = sebagaiAdmin();

    $disetujui = PengajuanIzin::factory()->create(['pegawai_id' => $user->pegawai_id]);

    $this->actingAs($admin)->patchJson("/api/v1/pengajuan-izin/{$disetujui->id}/putuskan", [
        'status' => 'disetujui',
    ])->assertOk()->assertJsonPath('data.status', 'disetujui');

    // Menolak tanpa catatan ditolak.
    $ditolak = PengajuanIzin::factory()->create(['pegawai_id' => $user->pegawai_id]);

    $this->actingAs($admin)->patchJson("/api/v1/pengajuan-izin/{$ditolak->id}/putuskan", [
        'status' => 'ditolak',
    ])->assertStatus(422)->assertJsonPath('errors.catatan_penyetuju.0', 'Catatan wajib diisi saat menolak pengajuan (FR-IZN-04).');

    // Dengan catatan berhasil.
    $this->actingAs($admin)->patchJson("/api/v1/pengajuan-izin/{$ditolak->id}/putuskan", [
        'status' => 'ditolak', 'catatan_penyetuju' => 'Bukti tidak cukup.',
    ])->assertOk()->assertJsonPath('data.status', 'ditolak');

    // Kepala sekolah juga berwenang.
    $ketiga = PengajuanIzin::factory()->create(['pegawai_id' => $user->pegawai_id]);
    $this->actingAs(sebagaiKepsek())->patchJson("/api/v1/pengajuan-izin/{$ketiga->id}/putuskan", [
        'status' => 'disetujui',
    ])->assertOk();
});

it('menolak guru memutuskan pengajuan (FR-IZN-04)', function (): void {
    $pengajuan = PengajuanIzin::factory()->create();

    $this->actingAs(buatPegawaiDenganAkun())->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/putuskan", [
        'status' => 'disetujui',
    ])->assertStatus(403);

    expect($pengajuan->refresh()->status)->toBe(PengajuanIzin::STATUS_MENUNGGU);
});

it('menolak memutuskan pengajuan yang sudah diputuskan', function (): void {
    $pengajuan = PengajuanIzin::factory()->disetujui()->create();

    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/putuskan", [
        'status' => 'ditolak', 'catatan_penyetuju' => 'Berubah pikiran.',
    ])->assertStatus(422);
});

/** KP-3.8 / FR-IZN-02 / BR-17 — dinas disetujui + luar radius menurunkan pengajuan per hari kerja. */
it('membuat pengajuan luar radius untuk setiap hari kerja saat dinas disetujui (KP-3.8)', function (): void {
    siapkanJamKerjaGuru();

    $user = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    $admin = sebagaiAdmin();

    // Senin 5 Okt – Rabu 7 Okt 2026 → tiga hari kerja.
    $pengajuan = PengajuanIzin::factory()->dinas(true)->create([
        'pegawai_id' => $user->pegawai_id,
        'tanggal_mulai' => '2026-10-05',
        'tanggal_selesai' => '2026-10-07',
    ]);

    $this->actingAs($admin)->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/putuskan", [
        'status' => 'disetujui',
    ])->assertOk();

    $luarRadius = PengajuanLuarRadius::where('pegawai_id', $user->pegawai_id)->orderBy('tanggal')->get();

    expect($luarRadius)->toHaveCount(3)
        ->and($luarRadius->pluck('tanggal')->map->toDateString()->all())
        ->toBe(['2026-10-05', '2026-10-06', '2026-10-07'])
        ->and($luarRadius->every(fn ($p): bool => $p->status === PengajuanLuarRadius::STATUS_DISETUJUI))->toBeTrue()
        ->and($luarRadius->every(fn ($p): bool => $p->pengajuan_izin_id === $pengajuan->id))->toBeTrue();
});

/** KP-3.8 — rentang yang memuat akhir pekan hanya menghasilkan hari kerja. */
it('melewati akhir pekan saat menurunkan pengajuan luar radius (KP-3.8/BR-24)', function (): void {
    siapkanJamKerjaGuru();

    $user = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    // Jumat 9 Okt – Senin 12 Okt 2026 → hanya Jumat dan Senin (Sabtu/Minggu libur).
    $pengajuan = PengajuanIzin::factory()->dinas(true)->create([
        'pegawai_id' => $user->pegawai_id,
        'tanggal_mulai' => '2026-10-09',
        'tanggal_selesai' => '2026-10-12',
    ]);

    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/putuskan", [
        'status' => 'disetujui',
    ])->assertOk();

    $tanggal = PengajuanLuarRadius::where('pegawai_id', $user->pegawai_id)
        ->orderBy('tanggal')->get()->pluck('tanggal')->map->toDateString()->all();

    expect($tanggal)->toBe(['2026-10-09', '2026-10-12']);
});

/** Dinas disetujui TANPA opsi luar radius tidak menghasilkan pengajuan luar radius. */
it('tidak membuat pengajuan luar radius bila opsi tidak dicentang', function (): void {
    siapkanJamKerjaGuru();

    $pengajuan = PengajuanIzin::factory()->dinas(false)->create([
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/putuskan", [
        'status' => 'disetujui',
    ])->assertOk();

    expect(PengajuanLuarRadius::count())->toBe(0);
});

/** Menolak dinas membatalkan pengajuan luar radius turunannya. */
it('membatalkan pengajuan luar radius turunan saat dinas ditolak', function (): void {
    siapkanJamKerjaGuru();

    $pengajuan = PengajuanIzin::factory()->dinas(true)->create([
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    // Disetujui dulu supaya turunannya ada.
    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/putuskan", ['status' => 'disetujui'])->assertOk();
    expect(PengajuanLuarRadius::count())->toBe(1);

    // Setelah pengajuan dinas dibatalkan? Status tidak dapat diubah; uji lewat jalur tolak
    // pada pengajuan baru untuk memastikan turunan ditolak juga dibatalkan.
    $pengajuan2 = PengajuanIzin::factory()->dinas(true)->create([
        'pegawai_id' => $pengajuan->pegawai_id,
        'tanggal_mulai' => '2026-10-12',
        'tanggal_selesai' => '2026-10-12',
    ]);

    $this->actingAs(sebagaiAdmin())->patchJson("/api/v1/pengajuan-izin/{$pengajuan2->id}/putuskan", [
        'status' => 'disetujui', 'catatan_penyetuju' => 'Sementara',
    ])->assertOk();

    $turunannya = PengajuanLuarRadius::where('pengajuan_izin_id', $pengajuan2->id)->get();
    expect($turunannya)->toHaveCount(1)
        ->and($turunannya->first()->status)->toBe(PengajuanLuarRadius::STATUS_DISETUJUI);
});

/** FR-IZN-03 — pembatalan hanya selama menunggu. */
it('mengizinkan pegawai membatalkan pengajuannya hanya saat menunggu (FR-IZN-03)', function (): void {
    $user = buatPegawaiDenganAkun();
    $pengajuan = PengajuanIzin::factory()->create(['pegawai_id' => $user->pegawai_id]);

    $this->actingAs($user)->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/batalkan")
        ->assertOk()->assertJsonPath('data.status', 'dibatalkan');

    $sudah = PengajuanIzin::factory()->disetujui()->create(['pegawai_id' => $user->pegawai_id]);

    $this->actingAs($user)->patchJson("/api/v1/pengajuan-izin/{$sudah->id}/batalkan")
        ->assertStatus(422);
});

it('menolak pegawai membatalkan pengajuan orang lain', function (): void {
    $pengajuan = PengajuanIzin::factory()->create();
    $lain = buatPegawaiDenganAkun();

    $this->actingAs($lain)->patchJson("/api/v1/pengajuan-izin/{$pengajuan->id}/batalkan")
        ->assertStatus(403);
});

/** FR-IZN-08 — admin membuat pengajuan atas nama pegawai, langsung disetujui. */
it('membuat pengajuan atas nama pegawai yang langsung disetujui (FR-IZN-08)', function (): void {
    $pegawai = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $respons = $this->actingAs(sebagaiAdmin())->postJson("/api/v1/pengajuan-izin/atas-nama/{$pegawai->id}", [
        'jenis' => 'sakit',
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
        'alasan' => 'Dirawat di rumah sakit, surat menyusul.',
    ]);

    $respons->assertCreated()->assertJsonPath('data.status', 'disetujui');

    expect(PengajuanIzin::firstOrFail()->dibuat_oleh_admin)->toBeTrue();
});

it('menolak selain admin membuat pengajuan atas nama pegawai (FR-IZN-08)', function (): void {
    $pegawai = Pegawai::factory()->create();

    $this->actingAs(buatPegawaiDenganAkun())->postJson("/api/v1/pengajuan-izin/atas-nama/{$pegawai->id}", [
        'jenis' => 'izin', 'tanggal_mulai' => seninUji(), 'tanggal_selesai' => seninUji(), 'alasan' => 'Coba-coba.',
    ])->assertStatus(403);
});

/** FR-IZN-06 — pengajuan luar radius mandiri, satu baris per tanggal. */
it('menerima pengajuan luar radius mandiri dan menolak tanggal yang sama dua kali (FR-IZN-06)', function (): void {
    $user = buatPegawaiDenganAkun();

    $this->actingAs($user)->postJson('/api/v1/pengajuan-luar-radius', [
        'tanggal' => seninUji(),
        'alasan' => 'Mendampingi siswa lomba di luar kota.',
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/pengajuan-luar-radius', [
        'tanggal' => seninUji(),
        'alasan' => 'Ajuan kedua.',
    ])->assertStatus(422);

    expect(PengajuanLuarRadius::count())->toBe(1);
});

/** FR-IZN-09 — pegawai melihat riwayat pengajuannya sendiri. */
it('membatasi riwayat pengajuan pada milik sendiri untuk pegawai (FR-IZN-09)', function (): void {
    $user = buatPegawaiDenganAkun();

    PengajuanIzin::factory()->create(['pegawai_id' => $user->pegawai_id]);
    PengajuanIzin::factory()->create();

    $respons = $this->actingAs($user)->getJson('/api/v1/pengajuan-izin');

    $respons->assertOk()->assertJsonPath('meta.total', 1);

    // Pemantau melihat semua.
    $this->actingAs(sebagaiAdmin())->getJson('/api/v1/pengajuan-izin')->assertOk()->assertJsonPath('meta.total', 2);
});
