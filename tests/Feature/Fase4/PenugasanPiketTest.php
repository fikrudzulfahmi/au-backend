<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Jurnal;
use App\Models\Pegawai;
use App\Models\PenugasanPiket;
use App\Models\PolaJamHari;
use App\Models\PresensiPegawai;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\JurnalService;

/*
|--------------------------------------------------------------------------
| Penugasan guru piket (menu admin) — siapa piket pada hari apa
|--------------------------------------------------------------------------
|
| Piket bukan sekadar catatan seremonial: penetapan inilah yang memberi seorang
| guru hak menulis jurnal kelas yang pengampunya berhalangan. Karena itu ujinya
| tidak berhenti pada CRUD, tetapi sampai ke pembuktian hak mengisi jurnal
| (termasuk batasnya: guru yang tidak dipiketkan tetap ditolak).
|
*/

beforeEach(function (): void {
    siapkanPeran();
});

/**
 * Guru kedua (bukan pengampu sesi) yang ditugaskan piket, sudah presensi masuk
 * agar lolos BR-19 seperti guru biasa.
 *
 * @return array{user: User, pegawai: Pegawai, piket: PenugasanPiket}
 */
function siapkanGuruPiket(array $rangkaian, int $hari = 1): array
{
    $user = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    PresensiPegawai::factory()->create([
        'pegawai_id' => $user->pegawai_id,
        'semester_id' => $rangkaian['semester']->id,
        'tanggal' => seninUji(),
        'masuk_validasi' => PresensiPegawai::VALID,
    ]);

    $piket = PenugasanPiket::factory()
        ->untukSemester($rangkaian['semester'])
        ->oleh($user->pegawai)
        ->padaHari($hari)
        ->create();

    return ['user' => $user, 'pegawai' => $user->pegawai, 'piket' => $piket];
}

/* ------------------------------- CRUD admin ------------------------------- */

it('menetapkan guru piket pada hari tertentu lewat API', function (): void {
    $admin = sebagaiAdmin();
    $r = siapkanJurnal();
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $this->actingAs($admin)->postJson('/api/v1/penugasan-piket', [
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $guru->pegawai_id,
        'hari' => 3,
        'catatan' => 'Ruang piket utama',
    ])
        ->assertCreated()
        ->assertJsonPath('data.hari', 3)
        ->assertJsonPath('data.nama_hari', PolaJamHari::NAMA[3])
        ->assertJsonPath('data.nama', $guru->pegawai->nama);

    expect(PenugasanPiket::count())->toBe(1)
        ->and(PenugasanPiket::firstOrFail()->is_active)->toBeTrue();

    expect(AuditLog::where('aksi', AuditLogService::AKSI_BUAT)->count())->toBe(1);
});

it('menampilkan daftar penugasan beserta label hari dari server', function (): void {
    $admin = sebagaiAdmin();
    $r = siapkanJurnal();
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    PenugasanPiket::factory()->untukSemester($r['semester'])->oleh($guru->pegawai)->padaHari(5)->create();
    // Semester lain tidak boleh ikut terbawa.
    PenugasanPiket::factory()->untukSemester(semesterGenap($r['tahun']))->padaHari(1)->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/penugasan-piket?semester_id='.$r['semester']->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nama_hari', PolaJamHari::NAMA[5])
        ->assertJsonPath('meta.semester_id', $r['semester']->id)
        ->assertJsonPath('meta.hari.0.nama', PolaJamHari::NAMA[1]);

    $this->actingAs($admin)
        ->getJson('/api/v1/penugasan-piket?semester_id='.$r['semester']->id.'&hari=1')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('hanya menawarkan guru aktif sebagai pilihan piket', function (): void {
    $admin = sebagaiAdmin();
    $aktif = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);
    $nonaktif = buatPegawaiDenganAkun(Pegawai::JENIS_GURU, ['is_active' => false]);
    $struktural = buatPegawaiDenganAkun(Pegawai::JENIS_STRUKTURAL);

    $respons = $this->actingAs($admin)->getJson('/api/v1/penugasan-piket/pegawai')->assertOk();

    $id = collect($respons->json('data'))->pluck('id');

    expect($id)->toContain($aktif->pegawai_id)
        ->and($id)->not->toContain($nonaktif->pegawai_id)
        ->and($id)->not->toContain($struktural->pegawai_id);
});

it('mengubah dan menghapus penugasan dengan jejak audit', function (): void {
    $admin = sebagaiAdmin();
    $r = siapkanJurnal();
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $piket = PenugasanPiket::factory()->untukSemester($r['semester'])->oleh($guru->pegawai)->padaHari(2)->create();

    $this->actingAs($admin)->putJson('/api/v1/penugasan-piket/'.$piket->id, [
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $guru->pegawai_id,
        'hari' => 4,
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.hari', 4)
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($admin)
        ->deleteJson('/api/v1/penugasan-piket/'.$piket->id)
        ->assertOk();

    expect(PenugasanPiket::count())->toBe(0)
        ->and(AuditLog::where('aksi', AuditLogService::AKSI_UBAH)->count())->toBe(1)
        ->and(AuditLog::where('aksi', AuditLogService::AKSI_HAPUS)->count())->toBe(1);
});

/* ---------------------------- Batas kewenangan ---------------------------- */

it('hanya admin yang boleh menetapkan guru piket', function (): void {
    $r = siapkanJurnal();
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $muatan = [
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $guru->pegawai_id,
        'hari' => 1,
    ];

    foreach ([sebagaiKepsek(), sebagaiWakasek(), $r['user']] as $pengguna) {
        $this->actingAs($pengguna)
            ->postJson('/api/v1/penugasan-piket', $muatan)
            ->assertStatus(403);

        $this->actingAs($pengguna)->getJson('/api/v1/penugasan-piket')->assertStatus(403);
    }

    expect(PenugasanPiket::count())->toBe(0);
});

it('menolak pegawai tanpa peran guru karena tidak akan berhak mengisi jurnal', function (): void {
    $admin = sebagaiAdmin();
    $r = siapkanJurnal();

    // Pegawai berjenis guru tetapi belum punya akun/peran guru.
    $tanpaAkun = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);

    $this->actingAs($admin)->postJson('/api/v1/penugasan-piket', [
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $tanpaAkun->id,
        'hari' => 1,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PIKET_TANPA_PERAN_GURU');

    expect(PenugasanPiket::count())->toBe(0);
});

it('menolak penugasan ganda pada semester dan hari yang sama', function (): void {
    $admin = sebagaiAdmin();
    $r = siapkanJurnal();
    $guru = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $muatan = [
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $guru->pegawai_id,
        'hari' => 2,
    ];

    $this->actingAs($admin)->postJson('/api/v1/penugasan-piket', $muatan)->assertCreated();

    $this->actingAs($admin)->postJson('/api/v1/penugasan-piket', $muatan)
        ->assertStatus(409)
        ->assertJsonPath('code', 'PIKET_SUDAH_ADA');

    expect(PenugasanPiket::count())->toBe(1);
});

it('menolak penugasan untuk pegawai atau semester yang tidak ada', function (): void {
    $admin = sebagaiAdmin();
    $r = siapkanJurnal();

    $this->actingAs($admin)->postJson('/api/v1/penugasan-piket', [
        'semester_id' => $r['semester']->id,
        'pegawai_id' => 999_999,
        'hari' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('pegawai_id');

    $this->actingAs($admin)->postJson('/api/v1/penugasan-piket', [
        'semester_id' => 999_999,
        'pegawai_id' => $r['guru']->id,
        'hari' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('semester_id');

    $this->actingAs($admin)->postJson('/api/v1/penugasan-piket', [
        'semester_id' => $r['semester']->id,
        'pegawai_id' => $r['guru']->id,
        'hari' => 8,
    ])->assertStatus(422)->assertJsonValidationErrors('hari');
});

/* ------------------------- Hak mengisi jurnal (BR-26) ------------------------- */

it('membuat guru piket melihat seluruh sesi sekolah pada hari tugasnya', function (): void {
    $r = siapkanJurnal();
    $piket = siapkanGuruPiket($r);

    // Hari lain: tetap sesi milik sendiri saja.
    $this->actingAs($piket['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal=2026-10-06')
        ->assertOk()
        ->assertJsonPath('data.piket.bertugas', false)
        ->assertJsonCount(0, 'data.sesi');

    $respons = $this->actingAs($piket['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.piket.bertugas', true)
        ->assertJsonPath('data.piket.nama_hari', PolaJamHari::NAMA[1])
        ->assertJsonCount(1, 'data.sesi');

    // Sesi milik pengampu, tetapi guru piket boleh mengisinya.
    expect($respons->json('data.sesi.0.pegawai_id'))->toBe($r['guru']->id)
        ->and($respons->json('data.sesi.0.boleh_isi'))->toBeTrue()
        ->and($respons->json('data.sesi.0.status'))->toBe(JurnalService::BELUM);
});

it('mencatat jurnal yang diisi guru piket atas nama pengampu', function (): void {
    $r = siapkanJurnal();
    $piket = siapkanGuruPiket($r);

    $id = $this->actingAs($piket['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated()
        ->json('data.id');

    $jurnal = Jurnal::findOrFail($id);

    // Kepatuhan jurnal (BR-26) menilai pengampu, jadi kolom itu tetap miliknya.
    expect($jurnal->pegawai_id)->toBe($r['guru']->id)
        ->and($jurnal->diisi_oleh_pegawai_id)->toBe($piket['pegawai']->id);

    // Guru piket juga boleh mengubah jurnal yang ia isi, dan pengampunya boleh
    // mengubah jurnal kelasnya sendiri.
    $this->actingAs($piket['user'])
        ->putJson('/api/v1/jurnal/'.$id, [
            'materi' => 'Diperbaiki guru piket',
            'kegiatan' => 'Perbaikan oleh guru piket.',
        ])
        ->assertOk();

    $this->actingAs($r['user'])->getJson('/api/v1/jurnal/'.$id)->assertOk();
});

it('membiarkan kolom pengisi kosong bila jurnal diisi pengampu sendiri', function (): void {
    $r = siapkanJurnal();

    $id = $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated()
        ->json('data.id');

    expect(Jurnal::findOrFail($id)->diisi_oleh_pegawai_id)->toBeNull();
});

it('tetap menolak guru yang tidak dipiketkan mengisi sesi guru lain', function (): void {
    $r = siapkanJurnal();
    $lain = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    PresensiPegawai::factory()->create([
        'pegawai_id' => $lain->pegawai_id,
        'semester_id' => $r['semester']->id,
        'tanggal' => seninUji(),
        'masuk_validasi' => PresensiPegawai::VALID,
    ]);

    $this->actingAs($lain)
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.piket.bertugas', false)
        ->assertJsonCount(0, 'data.sesi');

    $this->actingAs($lain)
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SESI_BUKAN_MILIK_ANDA');

    expect(Jurnal::count())->toBe(0);
});

it('mencabut hak mengisi jurnal kelas lain saat penugasan dinonaktifkan', function (): void {
    $r = siapkanJurnal();
    $piket = siapkanGuruPiket($r);

    $piket['piket']->update(['is_active' => false]);

    $this->actingAs($piket['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.piket.bertugas', false)
        ->assertJsonCount(0, 'data.sesi');

    $this->actingAs($piket['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SESI_BUKAN_MILIK_ANDA');
});

it('tidak memberi hak pada hari tugas yang berbeda', function (): void {
    $r = siapkanJurnal();
    // Ditetapkan piket hari Selasa, sedangkan sesinya hari Senin.
    $piket = siapkanGuruPiket($r, hari: 2);

    $this->actingAs($piket['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.piket.bertugas', false);

    $this->actingAs($piket['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SESI_BUKAN_MILIK_ANDA');
});

it('menandai sesi sudah terisi bagi guru piket setelah diisi guru lain', function (): void {
    $r = siapkanJurnal();
    $piket = siapkanGuruPiket($r);

    // Pengampu mengisi lebih dulu; daftar guru piket harus melihatnya "sudah",
    // bukan menawarkan pengisian ulang.
    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated();

    $this->actingAs($piket['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.sesi.0.status', JurnalService::SUDAH)
        ->assertJsonPath('data.sesi.0.boleh_isi', false);
});
