<?php

declare(strict_types=1);

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\PlottingKelas;
use App\Models\Siswa;
use App\Models\TahunPelajaran;

beforeEach(function (): void {
    siapkanPeran();
});

/**
 * Menyiapkan dua tahun pelajaran: asal (X, XI, XII berisi satu siswa masing-masing)
 * dan tujuan (kelas X, XI, XII sudah dibuat).
 *
 * @return array{asal: TahunPelajaran, kelas: array<string, Kelas>, siswa: array<string, Siswa>, tujuan: TahunPelajaran, tujuanKelas: array<string, Kelas>}
 */
function siapkanWizard(): array
{
    $asal = tahunAktif();
    $jurusan = Jurusan::factory()->create();

    $kelas = [];
    $siswa = [];

    foreach (['X', 'XI', 'XII'] as $tingkat) {
        $kelas[$tingkat] = Kelas::factory()->create([
            'tahun_pelajaran_id' => $asal->id,
            'tingkat' => $tingkat,
            'jurusan_id' => $jurusan->id,
            'nama' => $tingkat.' TKJ 1',
        ]);

        $siswa[$tingkat] = Siswa::factory()->create(['status' => 'aktif', 'nama' => "Siswa {$tingkat}"]);

        PlottingKelas::factory()->create([
            'tahun_pelajaran_id' => $asal->id,
            'siswa_id' => $siswa[$tingkat]->id,
            'kelas_id' => $kelas[$tingkat]->id,
        ]);
    }

    $tujuan = TahunPelajaran::factory()->denganSemester()->create(['nama' => '2035/2036']);

    $tujuanKelas = [];
    foreach (['X', 'XI', 'XII'] as $tingkat) {
        $tujuanKelas[$tingkat] = Kelas::factory()->create([
            'tahun_pelajaran_id' => $tujuan->id,
            'tingkat' => $tingkat,
            'jurusan_id' => $jurusan->id,
            'nama' => $tingkat.' TKJ 1',
        ]);
    }

    return compact('asal', 'kelas', 'siswa', 'tujuan', 'tujuanKelas');
}

function keputusan(int $siswaId, string $status, ?int $kelasTujuanId = null): array
{
    return ['siswa_id' => $siswaId, 'status_akhir' => $status, 'kelas_tujuan_id' => $kelasTujuanId];
}

/** FR-PLK-02 butir 3 — pratinjau memberi status akhir bawaan menurut tingkat. */
it('memberi status akhir bawaan dan saran kelas tujuan pada pratinjau (FR-PLK-02)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/pratinjau', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id, $w['kelas']['XII']->id],
    ]);

    $respons->assertOk();

    $perKelas = collect($respons->json('data.kelas'))->keyBy('tingkat');

    expect($perKelas['X']['siswa'][0]['status_akhir_bawaan'])->toBe('naik_kelas')
        ->and($perKelas['XII']['siswa'][0]['status_akhir_bawaan'])->toBe('lulus')
        // Saran kelas tujuan: tingkat +1 dengan jurusan sama.
        ->and($perKelas['X']['siswa'][0]['kelas_tujuan_saran'])->toBe($w['tujuanKelas']['XI']->id)
        ->and($perKelas['XII']['siswa'][0]['kelas_tujuan_saran'])->toBeNull();
});

/** KP-2.2 — X/XI naik_kelas menghasilkan plotting tingkat +1; XII lulus mengubah status siswa. */
it('menaikkan siswa X ke tingkat XI dan meluluskan siswa XII (KP-2.2)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id, $w['kelas']['XII']->id],
        'keputusan' => [
            keputusan($w['siswa']['X']->id, 'naik_kelas', $w['tujuanKelas']['XI']->id),
            keputusan($w['siswa']['XII']->id, 'lulus'),
        ],
    ]);

    $respons->assertOk()->assertJsonPath('data.diproses', 2);

    // Siswa X kini terplot di kelas XI tahun tujuan.
    $plottingTujuan = PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)
        ->where('siswa_id', $w['siswa']['X']->id)->first();

    expect($plottingTujuan?->kelas_id)->toBe($w['tujuanKelas']['XI']->id)
        // Riwayat dirantai ke plotting sebelumnya (FR-PLK-02 butir 7).
        ->and($plottingTujuan?->plotting_sebelumnya_id)->toBe(
            PlottingKelas::where('tahun_pelajaran_id', $w['asal']->id)->where('siswa_id', $w['siswa']['X']->id)->value('id')
        );

    // Status akhir baris tahun asal diperbarui.
    expect(PlottingKelas::where('tahun_pelajaran_id', $w['asal']->id)->where('siswa_id', $w['siswa']['X']->id)->value('status_akhir'))
        ->toBe('naik_kelas');

    // Siswa XII lulus: status siswa berubah, dan TIDAK ada plotting di tahun tujuan.
    $lulus = $w['siswa']['XII']->refresh();
    expect($lulus->status)->toBe('lulus')
        ->and($lulus->tanggal_status)->not->toBeNull()
        ->and($lulus->tahun_lulus)->not->toBeNull()
        ->and(PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->where('siswa_id', $lulus->id)->exists())->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['aksi' => 'naik_kelas']);
});

/** KP-2.3 — tinggal_kelas pada tingkat sama; pindah/keluar tidak membuat plotting baru. */
it('menempatkan tinggal_kelas pada tingkat sama dan tidak memplot pindah/keluar (KP-2.3)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id, $w['kelas']['XI']->id, $w['kelas']['XII']->id],
        'keputusan' => [
            keputusan($w['siswa']['X']->id, 'tinggal_kelas', $w['tujuanKelas']['X']->id),
            keputusan($w['siswa']['XI']->id, 'pindah'),
            keputusan($w['siswa']['XII']->id, 'keluar'),
        ],
    ])->assertOk()->assertJsonPath('data.diproses', 3);

    expect(PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->where('siswa_id', $w['siswa']['X']->id)->value('kelas_id'))
        ->toBe($w['tujuanKelas']['X']->id);

    expect($w['siswa']['XI']->refresh()->status)->toBe('pindah')
        ->and($w['siswa']['XII']->refresh()->status)->toBe('keluar')
        // pindah/keluar tidak menghasilkan plotting baru, dan tahun_lulus hanya untuk lulus.
        ->and(PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->count())->toBe(1)
        ->and($w['siswa']['XII']->refresh()->tahun_lulus)->toBeNull();
});

/** KP-2.4 — menjalankan wizard dua kali tidak menggandakan data (idempotent). */
it('tidak menggandakan data saat wizard dijalankan dua kali (KP-2.4)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $muatan = [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id],
        'keputusan' => [keputusan($w['siswa']['X']->id, 'naik_kelas', $w['tujuanKelas']['XI']->id)],
    ];

    $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', $muatan)
        ->assertOk()->assertJsonPath('data.diproses', 1);

    $kedua = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', $muatan);

    $kedua->assertOk()->assertJsonPath('data.diproses', 0);
    expect($kedua->json('data.dilewati.0.alasan'))->toContain('Sudah diproses');

    // Tetap satu baris di tahun tujuan dan satu di tahun asal.
    expect(PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->where('siswa_id', $w['siswa']['X']->id)->count())->toBe(1);
    expect(PlottingKelas::where('tahun_pelajaran_id', $w['asal']->id)->where('siswa_id', $w['siswa']['X']->id)->count())->toBe(1);
});

/** KP-2.5 — seluruh eksekusi transaksional: satu galat membatalkan semuanya. */
it('membatalkan seluruh proses bila ada satu keputusan tidak sah (KP-2.5)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $tahunLain = TahunPelajaran::factory()->create(['nama' => '2037/2038']);
    $kelasTahunLain = Kelas::factory()->create([
        'tahun_pelajaran_id' => $tahunLain->id,
        'tingkat' => 'XI',
        'nama' => 'XI TKJ 9',
    ]);

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id, $w['kelas']['XI']->id],
        'keputusan' => [
            // Sah.
            keputusan($w['siswa']['X']->id, 'naik_kelas', $w['tujuanKelas']['XI']->id),
            // Tidak sah: kelas tujuan berada di tahun pelajaran lain.
            keputusan($w['siswa']['XI']->id, 'naik_kelas', $kelasTahunLain->id),
        ],
    ]);

    $respons->assertStatus(422);
    expect($respons->json('code'))->toBe('VALIDASI_BERKAS');

    // Tidak ada satu pun perubahan yang tersimpan.
    expect(PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->count())->toBe(0)
        ->and(PlottingKelas::where('tahun_pelajaran_id', $w['asal']->id)->where('status_akhir', 'berjalan')->count())->toBe(3)
        ->and($w['siswa']['X']->refresh()->status)->toBe('aktif');
});

/** FR-PLK-04 / A-10 — validasi pemetaan kelas tujuan. */
it('menolak kelas tujuan yang tingkatnya tidak sesuai (FR-PLK-04)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id],
        // Naik dari X harus ke XI, bukan ke XII.
        'keputusan' => [keputusan($w['siswa']['X']->id, 'naik_kelas', $w['tujuanKelas']['XII']->id)],
    ]);

    $respons->assertStatus(422);
    expect(implode(' ', $respons->json('errors.keputusan')))->toContain('XI');
    expect(PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->count())->toBe(0);
});

/** A-10 — kelas XII tidak dapat naik_kelas. */
it('menolak siswa kelas XII berstatus naik_kelas (A-10)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $respons = $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['XII']->id],
        'keputusan' => [keputusan($w['siswa']['XII']->id, 'naik_kelas', $w['tujuanKelas']['X']->id)],
    ]);

    $respons->assertStatus(422);
    expect(implode(' ', $respons->json('errors.keputusan')))->toContain('XII tidak dapat naik kelas');
    expect($w['siswa']['XII']->refresh()->status)->toBe('aktif');
});

it('menolak status akhir yang tidak dikenali', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id],
        'keputusan' => [['siswa_id' => $w['siswa']['X']->id, 'status_akhir' => 'entah']],
    ])->assertStatus(422);
});

/** FR-PLK-06 — membatalkan hasil naik kelas. */
it('membatalkan hasil naik kelas dan mengembalikan keadaan semula (FR-PLK-06)', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['XII']->id, $w['kelas']['X']->id],
        'keputusan' => [
            keputusan($w['siswa']['XII']->id, 'lulus'),
            keputusan($w['siswa']['X']->id, 'naik_kelas', $w['tujuanKelas']['XI']->id),
        ],
    ])->assertOk();

    expect($w['siswa']['XII']->refresh()->status)->toBe('lulus');

    // Batalkan kelulusan (baris asal, tanpa plotting tujuan).
    $plottingAsalXII = PlottingKelas::where('tahun_pelajaran_id', $w['asal']->id)->where('siswa_id', $w['siswa']['XII']->id)->first();

    $this->actingAs($admin)->postJson("/api/v1/plotting-kelas/{$plottingAsalXII->id}/batalkan")
        ->assertOk()
        ->assertJsonPath('data.status_dikembalikan', 'lulus');

    expect($plottingAsalXII->refresh()->status_akhir)->toBe('berjalan')
        ->and($w['siswa']['XII']->refresh()->status)->toBe('aktif')
        ->and($w['siswa']['XII']->refresh()->tahun_lulus)->toBeNull();

    // Batalkan kenaikan kelas: baris tujuan terhapus, baris asal kembali berjalan.
    $plottingTujuan = PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->where('siswa_id', $w['siswa']['X']->id)->first();

    $this->actingAs($admin)->postJson("/api/v1/plotting-kelas/{$plottingTujuan->id}/batalkan")->assertOk();

    expect(PlottingKelas::where('tahun_pelajaran_id', $w['tujuan']->id)->count())->toBe(0)
        ->and(PlottingKelas::where('tahun_pelajaran_id', $w['asal']->id)->where('siswa_id', $w['siswa']['X']->id)->value('status_akhir'))->toBe('berjalan');

    $this->assertDatabaseHas('audit_log', ['aksi' => 'batal_naik_kelas']);
});

it('menolak wizard dengan tahun pelajaran asal sama dengan tujuan', function (): void {
    $admin = sebagaiAdmin();
    $w = siapkanWizard();

    $this->actingAs($admin)->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['asal']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id],
        'keputusan' => [keputusan($w['siswa']['X']->id, 'naik_kelas', $w['tujuanKelas']['XI']->id)],
    ])->assertStatus(422)->assertJsonPath('errors.tahun_pelajaran_tujuan_id.0', 'Tahun pelajaran asal dan tujuan tidak boleh sama.');
});

it('membatasi wizard hanya untuk admin', function (): void {
    $w = siapkanWizard();

    $this->actingAs(sebagaiKepsek())->postJson('/api/v1/plotting-kelas/wizard/eksekusi', [
        'tahun_pelajaran_asal_id' => $w['asal']->id,
        'tahun_pelajaran_tujuan_id' => $w['tujuan']->id,
        'kelas_asal_ids' => [$w['kelas']['X']->id],
        'keputusan' => [keputusan($w['siswa']['X']->id, 'naik_kelas', $w['tujuanKelas']['XI']->id)],
    ])->assertStatus(403);
});
