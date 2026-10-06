<?php

declare(strict_types=1);

use App\Models\Jurnal;
use App\Models\Kelas;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PlottingKelas;
use App\Models\PresensiSiswa;
use App\Models\Siswa;
use App\Services\JurnalService;

/*
|--------------------------------------------------------------------------
| Fase 4 — Presensi siswa pada jurnal (KP-4.3, KP-4.6)
|--------------------------------------------------------------------------
| FR-JRN-03, FR-JRN-08, FR-JRN-10, BR-22, BR-23, BR-26.
*/

beforeEach(function (): void {
    siapkanPeran();
});

// =====================================================================
// KP-4.3 / BR-22 — hanya siswa aktif yang terplot di kelas itu
// =====================================================================

it('hanya memuat siswa aktif yang terplot di kelas pada tahun pelajaran itu', function () {
    $r = siapkanJurnal();

    // Siswa yang sudah lulus tetapi masih terplot di kelas yang sama.
    $lulus = Siswa::factory()->create(['status' => Siswa::STATUS_LULUS]);
    PlottingKelas::factory()->create([
        'tahun_pelajaran_id' => $r['tahun']->id,
        'kelas_id' => $r['kelas']->id,
        'siswa_id' => $lulus->id,
    ]);

    // Siswa aktif tetapi terplot di kelas lain.
    $kelasLain = Kelas::factory()->create(['tahun_pelajaran_id' => $r['tahun']->id]);
    $lain = Siswa::factory()->create(['status' => Siswa::STATUS_AKTIF]);
    PlottingKelas::factory()->create([
        'tahun_pelajaran_id' => $r['tahun']->id,
        'kelas_id' => $kelasLain->id,
        'siswa_id' => $lain->id,
    ]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/siswa-kelas?kelas_id='.$r['kelas']->id)
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $id = collect($this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/siswa-kelas?kelas_id='.$r['kelas']->id)
        ->json('data'))->pluck('siswa_id');

    expect($id)->not->toContain($lulus->id)
        ->and($id)->not->toContain($lain->id);
});

it('semua siswa default hadir bila guru tidak mengubahnya', function () {
    $r = siapkanJurnal();

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertCreated();

    $jurnal = Jurnal::first();

    expect($jurnal->presensiSiswa()->count())->toBe(4)
        ->and($jurnal->presensiSiswa()->where('status', PresensiSiswa::HADIR)->count())->toBe(4);
});

it('menyimpan status S, I, A beserta keterangannya', function () {
    $r = siapkanJurnal();
    $s = $r['siswa'];

    $presensi = [
        (string) $s[0]->id => ['status' => PresensiSiswa::HADIR],
        (string) $s[1]->id => ['status' => PresensiSiswa::SAKIT, 'keterangan' => 'Demam'],
        (string) $s[2]->id => ['status' => PresensiSiswa::IZIN, 'keterangan' => 'Acara keluarga'],
        (string) $s[3]->id => ['status' => PresensiSiswa::ALPA],
    ];

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, [], ['presensi' => $presensi]))
        ->assertCreated();

    $jurnal = Jurnal::first();
    $jelas = $jurnal->presensiSiswa->keyBy('siswa_id');

    expect($jelas[$s[0]->id]->status)->toBe(PresensiSiswa::HADIR)
        ->and($jelas[$s[1]->id]->status)->toBe(PresensiSiswa::SAKIT)
        ->and($jelas[$s[1]->id]->keterangan)->toBe('Demam')
        ->and($jelas[$s[2]->id]->status)->toBe(PresensiSiswa::IZIN)
        ->and($jelas[$s[3]->id]->status)->toBe(PresensiSiswa::ALPA);
});

it('menolak siswa yang bukan anggota kelas itu', function () {
    $r = siapkanJurnal();

    $penyusup = Siswa::factory()->create(['status' => Siswa::STATUS_AKTIF]);

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, [], [
            'presensi' => [(string) $penyusup->id => ['status' => PresensiSiswa::HADIR]],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'SISWA_DI_LUAR_KELAS');

    expect(Jurnal::count())->toBe(0);
});

it('menolak status di luar H, S, I, A', function () {
    $r = siapkanJurnal();

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, [], [
            'presensi' => [(string) $r['siswa'][0]->id => ['status' => 'X']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['presensi.'.$r['siswa'][0]->id.'.status']);

    expect(Jurnal::count())->toBe(0);
});

it('mengganti presensi siswa secara utuh saat jurnal diubah', function () {
    $r = siapkanJurnal();
    $s = $r['siswa'];

    $id = $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $s->all()))
        ->json('data.id');

    // Ubah satu siswa menjadi alpa, sisanya tetap hadir.
    $this->actingAs($r['user'])
        ->putJson('/api/v1/jurnal/'.$id, [
            'materi' => 'Materi uji jurnal',
            'kegiatan' => 'Kegiatan pembelajaran uji.',
            'presensi' => [
                (string) $s[0]->id => ['status' => PresensiSiswa::ALPA, 'keterangan' => 'Tanpa kabar'],
                (string) $s[1]->id => ['status' => PresensiSiswa::HADIR],
            ],
        ])
        ->assertOk();

    $jurnal = Jurnal::find($id);

    expect($jurnal->presensiSiswa()->count())->toBe(2)
        ->and($jurnal->presensiSiswa()->where('status', PresensiSiswa::ALPA)->count())->toBe(1);
});

it('menghitung ringkasan H S I A pada daftar riwayat', function () {
    $r = siapkanJurnal();
    $s = $r['siswa'];

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, [], [
            'presensi' => [
                (string) $s[0]->id => ['status' => PresensiSiswa::HADIR],
                (string) $s[1]->id => ['status' => PresensiSiswa::SAKIT],
                (string) $s[2]->id => ['status' => PresensiSiswa::IZIN],
                (string) $s[3]->id => ['status' => PresensiSiswa::ALPA],
            ],
        ]))
        ->assertCreated();

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal')
        ->assertOk()
        ->assertJsonPath('data.0.ringkasan.H', 1)
        ->assertJsonPath('data.0.ringkasan.S', 1)
        ->assertJsonPath('data.0.ringkasan.I', 1)
        ->assertJsonPath('data.0.ringkasan.A', 1);
});

it('menyajikan presensi siswa pada endpoint khusus', function () {
    $r = siapkanJurnal();

    $id = $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->json('data.id');

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/'.$id.'/presensi-siswa')
        ->assertOk()
        ->assertJsonCount(4, 'data');
});

// =====================================================================
// KP-4.6 / BR-26 / FR-IZN-07 — hari izin disetujui = Berhalangan
// =====================================================================

it('menandai sesi berhalangan pada hari izin disetujui dan menolak pengisian', function () {
    $r = siapkanJurnal();

    PengajuanIzin::factory()->disetujui()->create([
        'pegawai_id' => $r['guru']->id,
        'jenis' => PengajuanIzin::JENIS_IZIN,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.sesi.0.status', JurnalService::BERHALANGAN)
        ->assertJsonPath('data.sesi.0.boleh_isi', false)
        ->assertJsonPath('data.ringkasan.berhalangan', 1)
        ->assertJsonPath('data.ringkasan.belum', 0);

    // KP-4.6 — bukan hanya tampilan: server tetap menolak pengisiannya.
    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, $r['siswa']->all()))
        ->assertStatus(422)
        ->assertJsonPath('code', 'BERHALANGAN');

    expect(Jurnal::count())->toBe(0);
});

it('tidak menandai berhalangan bila pengajuannya belum disetujui', function () {
    $r = siapkanJurnal();

    PengajuanIzin::factory()->create([
        'pegawai_id' => $r['guru']->id,
        'jenis' => PengajuanIzin::JENIS_IZIN,
        'status' => PengajuanIzin::STATUS_MENUNGGU,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.sesi.0.status', JurnalService::BELUM);
});

it('menandai berhalangan juga untuk dinas yang disetujui (FR-IZN-07)', function () {
    $r = siapkanJurnal();

    PengajuanIzin::factory()->dinas()->disetujui()->create([
        'pegawai_id' => $r['guru']->id,
        'tanggal_mulai' => seninUji(),
        'tanggal_selesai' => seninUji(),
    ]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.sesi.0.status', JurnalService::BERHALANGAN);
});

it('tidak menandai berhalangan di luar rentang tanggal pengajuan', function () {
    $r = siapkanJurnal();

    PengajuanIzin::factory()->disetujui()->create([
        'pegawai_id' => $r['guru']->id,
        'jenis' => PengajuanIzin::JENIS_SAKIT,
        'tanggal_mulai' => '2026-10-12',
        'tanggal_selesai' => '2026-10-13',
    ]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/sesi-hari-ini?tanggal='.seninUji())
        ->assertOk()
        ->assertJsonPath('data.sesi.0.status', JurnalService::BELUM);
});

// =====================================================================
// FR-JRN-10 — rekap presensi siswa kelas wali
// =====================================================================

it('mengizinkan wali kelas melihat rekap presensi kelasnya', function () {
    $r = siapkanJurnal();
    $s = $r['siswa'];

    $r['kelas']->update(['wali_kelas_id' => $r['guru']->id]);

    $this->actingAs($r['user'])
        ->postJson('/api/v1/jurnal', badanJurnal($r, [], [
            'presensi' => [
                (string) $s[0]->id => ['status' => PresensiSiswa::HADIR],
                (string) $s[1]->id => ['status' => PresensiSiswa::HADIR],
                (string) $s[2]->id => ['status' => PresensiSiswa::ALPA],
                (string) $s[3]->id => ['status' => PresensiSiswa::SAKIT],
            ],
        ]))
        ->assertCreated();

    $balasan = $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/rekap-siswa?kelas_id='.$r['kelas']->id)
        ->assertOk()
        ->json('data');

    expect($balasan['sesi'])->toBe(1)
        ->and($balasan['ringkasan']['H'])->toBe(2)
        ->and($balasan['ringkasan']['S'])->toBe(1)
        ->and($balasan['ringkasan']['A'])->toBe(1)
        ->and($balasan['siswa'])->toHaveCount(4);

    // Persentase kehadiran dihitung dari sesi yang benar-benar ada: yang alpa 0%.
    // Nilainya melalui JSON, sehingga 0.0 terserialisasi menjadi 0 — bandingkan longgar.
    expect(collect($balasan['siswa'])->pluck('persen_hadir')->filter(fn ($v) => $v !== null)->min())->toEqual(0.0);
});

it('menolak guru yang bukan wali kelas itu melihat rekap', function () {
    $r = siapkanJurnal();

    // Kelas diampu orang lain sebagai wali kelas.
    $waliLain = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $r['kelas']->update(['wali_kelas_id' => $waliLain->id]);

    $this->actingAs($r['user'])
        ->getJson('/api/v1/jurnal/rekap-siswa?kelas_id='.$r['kelas']->id)
        ->assertStatus(403);
});

it('mengizinkan admin melihat rekap kelas mana pun', function () {
    $r = siapkanJurnal();
    $r['kelas']->update(['wali_kelas_id' => null]);

    $this->actingAs(sebagaiAdmin())
        ->getJson('/api/v1/jurnal/rekap-siswa?kelas_id='.$r['kelas']->id)
        ->assertOk()
        ->assertJsonPath('data.siswa.0.total', 0);
});
