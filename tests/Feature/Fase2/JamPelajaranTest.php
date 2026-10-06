<?php

declare(strict_types=1);

use App\Models\PolaJam;
use App\Models\PolaJamHari;
use App\Models\Semester;
use App\Models\SlotJam;
use App\Models\TahunPelajaran;

beforeEach(function (): void {
    siapkanPeran();
});

/** BR-05 — satu hari hanya masuk satu pola jam dalam satu semester. */
it('menolak hari yang sudah dipakai pola lain pada semester yang sama (BR-05)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();

    $this->actingAs($admin)->postJson('/api/v1/jam-pelajaran', [
        'semester_id' => $a['semester']->id,
        'nama' => 'Senin–Kamis',
        'hari' => [1, 2, 3, 4],
    ])->assertCreated();

    $respons = $this->actingAs($admin)->postJson('/api/v1/jam-pelajaran', [
        'semester_id' => $a['semester']->id,
        'nama' => 'Pola Baru',
        'hari' => [4, 5],
    ]);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('BR-05');
    expect(implode(' ', $respons->json('errors.hari')))->toContain('Kamis');

    expect(PolaJam::count())->toBe(1)
        ->and(PolaJamHari::count())->toBe(4);
});

it('mengizinkan hari yang sama pada semester berbeda', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $genap = semesterGenap($a['tahun']);

    $this->actingAs($admin)->postJson('/api/v1/jam-pelajaran', [
        'semester_id' => $a['semester']->id, 'nama' => 'Pola Ganjil', 'hari' => [1, 2],
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/v1/jam-pelajaran', [
        'semester_id' => $genap->id, 'nama' => 'Pola Genap', 'hari' => [1, 2],
    ])->assertCreated();

    expect(PolaJam::count())->toBe(2);
});

it('menyajikan pola jam beserta hari dan slotnya', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    pasangPolaJam($a['semester'], [1, 2], 2);

    $respons = $this->actingAs($admin)->getJson('/api/v1/jam-pelajaran?semester_id='.$a['semester']->id);

    $respons->assertOk()->assertJsonCount(1, 'data');
    expect($respons->json('data.0.hari'))->toHaveCount(2)
        // 2 slot pelajaran + 1 istirahat, dan istirahat tidak dihitung sebagai JP.
        ->and($respons->json('data.0.jumlah_jp'))->toBe(2);
});

/** FR-JAM-03 — validasi slot. */
it('menolak slot yang tumpang tindih (FR-JAM-03)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    $respons = $this->actingAs($admin)->postJson("/api/v1/jam-pelajaran/{$pola['pola']->id}/slot", [
        'urutan' => 9,
        'tipe' => SlotJam::PELAJARAN,
        'label' => 'Bentrok',
        'jam_mulai' => '07:30',
        'jam_selesai' => '08:15',
        'jam_ke' => 9,
    ]);

    $respons->assertStatus(422);
    expect($respons->json('errors.jam_mulai.0'))->toContain('tumpang tindih');
});

it('menolak jam mulai yang tidak lebih awal daripada jam selesai (FR-JAM-03)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    $this->actingAs($admin)->postJson("/api/v1/jam-pelajaran/{$pola['pola']->id}/slot", [
        'urutan' => 9,
        'tipe' => SlotJam::PELAJARAN,
        'label' => 'Terbalik',
        'jam_mulai' => '10:00',
        'jam_selesai' => '09:00',
        'jam_ke' => 9,
    ])->assertStatus(422)->assertJsonPath('errors.jam_selesai.0', 'Jam mulai harus lebih awal daripada jam selesai.');
});

it('menolak jam ke- yang bolong pada slot pelajaran (FR-JAM-03)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    // jam_ke 5 membuat urutan 1, 2, 5 → tidak berurutan.
    $respons = $this->actingAs($admin)->postJson("/api/v1/jam-pelajaran/{$pola['pola']->id}/slot", [
        'urutan' => 9,
        'tipe' => SlotJam::PELAJARAN,
        'label' => 'Bolong',
        'jam_mulai' => '13:00',
        'jam_selesai' => '13:45',
        'jam_ke' => 5,
    ]);

    $respons->assertStatus(422);
    expect($respons->json('errors.jam_ke.0'))->toContain('berurutan');
});

it('mewajibkan jam ke- untuk slot pelajaran dan mengosongkannya untuk istirahat', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    $this->actingAs($admin)->postJson("/api/v1/jam-pelajaran/{$pola['pola']->id}/slot", [
        'urutan' => 8, 'tipe' => SlotJam::PELAJARAN, 'label' => 'Tanpa Jam Ke',
        'jam_mulai' => '13:00', 'jam_selesai' => '13:45',
    ])->assertStatus(422)->assertJsonPath('errors.jam_ke.0', 'Jam ke- wajib diisi untuk slot bertipe pelajaran.');

    $this->actingAs($admin)->postJson("/api/v1/jam-pelajaran/{$pola['pola']->id}/slot", [
        'urutan' => 9, 'tipe' => SlotJam::KEGIATAN, 'label' => 'Upacara',
        'jam_mulai' => '13:45', 'jam_selesai' => '14:30', 'jam_ke' => 4,
    ])->assertCreated()->assertJsonPath('data.jam_ke', null);
});

it('menolak tipe slot yang tidak dikenali', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    $this->actingAs($admin)->postJson("/api/v1/jam-pelajaran/{$pola['pola']->id}/slot", [
        'urutan' => 9, 'tipe' => 'entah', 'label' => 'Salah',
        'jam_mulai' => '13:00', 'jam_selesai' => '13:45',
    ])->assertStatus(422);
});

/** FR-JAM-05 — slot yang dipakai jadwal tidak boleh dihapus / diubah jam ke-nya. */
it('menolak menghapus slot yang dipakai jadwal (FR-JAM-05)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 1);

    $this->actingAs($admin)->postJson('/api/v1/jadwal', [
        'semester_id' => $a['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $pola['slot'][0]->id,
        'plotting_mapel_id' => $a['plotting']->id,
    ])->assertCreated();

    $this->actingAs($admin)->deleteJson("/api/v1/jam-pelajaran/slot/{$pola['slot'][0]->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'DIPAKAI_JADWAL');

    expect(SlotJam::count())->toBe(2);
});

it('menolak mengubah jam ke- slot yang dipakai jadwal tetapi mengizinkan jamnya', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    $pola = pasangPolaJam($a['semester'], [1], 2);

    $slot = $pola['slot'][0];

    $this->actingAs($admin)->postJson('/api/v1/jadwal', [
        'semester_id' => $a['semester']->id,
        'hari' => 1,
        'slot_jam_id' => $slot->id,
        'plotting_mapel_id' => $a['plotting']->id,
    ])->assertCreated();

    // Mengubah jam ke- ditolak.
    $this->actingAs($admin)->putJson("/api/v1/jam-pelajaran/slot/{$slot->id}", [
        'urutan' => 1, 'tipe' => SlotJam::PELAJARAN, 'label' => $slot->label,
        'jam_mulai' => $slot->jamMulaiPendek(), 'jam_selesai' => $slot->jamSelesaiPendek(), 'jam_ke' => 7,
    ])->assertStatus(409)->assertJsonPath('code', 'DIPAKAI_JADWAL');

    // Mengubah jam mulai/selesai diperbolehkan (FR-JAM-05).
    $this->actingAs($admin)->putJson("/api/v1/jam-pelajaran/slot/{$slot->id}", [
        'urutan' => 1, 'tipe' => SlotJam::PELAJARAN, 'label' => $slot->label,
        'jam_mulai' => '06:30', 'jam_selesai' => '07:15', 'jam_ke' => 1,
    ])->assertOk()->assertJsonPath('data.jam_mulai', '06:30');
});

/** FR-JAM-04 — salin pola jam dari semester lain. */
it('menyalin pola jam beserta slotnya dan melewati hari yang bentrok (FR-JAM-04)', function (): void {
    $admin = sebagaiAdmin();
    $a = siapkanAkademik();
    pasangPolaJam($a['semester'], [1, 2], 2);

    $genap = semesterGenap($a['tahun']);
    $polaGenap = PolaJam::factory()->create(['semester_id' => $genap->id, 'nama' => 'Pola Tujuan']);
    PolaJamHari::create(['pola_jam_id' => $polaGenap->id, 'semester_id' => $genap->id, 'hari' => 2]);

    $respons = $this->actingAs($admin)->postJson('/api/v1/jam-pelajaran/salin', [
        'semester_asal_id' => $a['semester']->id,
        'semester_tujuan_id' => $genap->id,
    ]);

    $respons->assertOk()->assertJsonPath('data.dibuat', 0);
    expect($respons->json('data.dilewati.0.alasan'))->toContain('BR-05');

    // Tanpa bentrok hari, penyalinan berhasil beserta slotnya.
    // Tujuan kedua memakai tahun pelajaran lain, karena satu tahun pelajaran hanya
    // memiliki dua semester (UQ tahun_pelajaran_id + jenis).
    $tahunLain = TahunPelajaran::factory()->denganSemester()->create(['nama' => '2040/2041']);
    $tujuanBebas = Semester::where('tahun_pelajaran_id', $tahunLain->id)->where('jenis', 'ganjil')->firstOrFail();

    $this->actingAs($admin)->postJson('/api/v1/jam-pelajaran/salin', [
        'semester_asal_id' => $a['semester']->id,
        'semester_tujuan_id' => $tujuanBebas->id,
    ])->assertOk()->assertJsonPath('data.dibuat', 1);

    $polaBaru = PolaJam::where('semester_id', $tujuanBebas->id)->first();
    expect($polaBaru?->slot()->count())->toBe(3)
        ->and($polaBaru?->hari()->count())->toBe(2);
});

it('membatasi pengelolaan jam pelajaran sesuai matriks Bagian 2', function (): void {
    $a = siapkanAkademik();

    $muatan = ['semester_id' => $a['semester']->id, 'nama' => 'Pola', 'hari' => [1]];

    // Kepala sekolah hanya melihat.
    $this->actingAs(sebagaiKepsek())->getJson('/api/v1/jam-pelajaran')->assertOk();
    $this->actingAs(sebagaiKepsek())->postJson('/api/v1/jam-pelajaran', $muatan)->assertStatus(403);

    // Wakasek kurikulum boleh mengelola.
    $this->actingAs(sebagaiWakasek())->postJson('/api/v1/jam-pelajaran', $muatan)->assertCreated();

    // Guru tidak punya akses sama sekali.
    $this->actingAs(buatPegawaiDenganAkun())->getJson('/api/v1/jam-pelajaran')->assertStatus(403);
});
