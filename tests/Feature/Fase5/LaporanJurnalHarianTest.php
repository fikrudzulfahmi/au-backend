<?php

declare(strict_types=1);

use App\Models\Jurnal;
use App\Models\Pegawai;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
|--------------------------------------------------------------------------
| Fase 5 — dasbor harian pengisian jurnal per kelas
|--------------------------------------------------------------------------
| Yang dipantau: kelas mana yang sesinya sudah diisi guru hari ini, mana
| yang masih kosong, dan mana yang berhalangan (izin disetujui).
|
| Berbeda dari `/laporan/jurnal/kepatuhan` yang menghitung per guru × periode,
| laporan ini memotong satu tanggal lalu mengelompokkan per kelas.
*/

beforeEach(function (): void {
    siapkanPeran();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));
});

/**
 * Rangkaian jurnal Senin (jam 1-3 tergabung) lengkap dengan jam kerja guru.
 *
 * @return array<string, mixed>
 */
function rangkaianHarian(bool $denganPresensi = true): array
{
    $r = siapkanJurnal(denganPresensi: $denganPresensi);
    pasangJamKerja(Pegawai::JENIS_GURU);

    return $r;
}

/** @return array<string, mixed> */
function kelasHarianPertama(TestResponse $res): array
{
    return collect($res->json('data.kelas'))->first() ?? [];
}

/** @return array<string, mixed> */
function sesiHarianPertama(TestResponse $res): array
{
    return (array) (kelasHarianPertama($res)['daftar'][0] ?? []);
}

// =====================================================================
// Pengelompokan per kelas & status sesi
// =====================================================================

it('mengelompokkan sesi jurnal satu tanggal per kelas beserta statusnya', function () {
    $r = rangkaianHarian();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/harian');
    $res->assertOk()
        ->assertJsonPath('data.tanggal', '2026-10-05')
        ->assertJsonPath('data.hari', 'Senin');

    $kelas = kelasHarianPertama($res);
    expect($res->json('data.kelas'))->toHaveCount(1)
        ->and($kelas['nama'])->toBe((string) $r['kelas']->nama)
        ->and($kelas['sesi'])->toBe(1)
        ->and($kelas['sudah'])->toBe(0)
        ->and($kelas['belum'])->toBe(1)
        ->and($kelas['berhalangan'])->toBe(0);

    // Sesi tergabung 1-3 hadir sebagai satu baris, terurut dari jam ke awal.
    $sesi = sesiHarianPertama($res);
    expect($sesi['label_jam'])->toBe('Jam ke-1-3')
        ->and($sesi['guru'])->toBe((string) $r['guru']->nama)
        ->and($sesi['status'])->toBe('belum')
        ->and($sesi['jurnal_id'])->toBeNull();

    expect($res->json('data.hitung'))->toBe([
        'kelas' => 1,
        'sesi' => 1,
        'sudah' => 0,
        'belum' => 1,
        'berhalangan' => 0,
        // 0% di JSON turun sebagai integer; pembandung longgar dipakai sengaja.
        'persen_terisi' => 0,
    ]);
});

it('menandai sesi sudah diisi bila jurnalnya dibuat, lengkap dengan id jurnal', function () {
    $r = rangkaianHarian();
    Jurnal::factory()->denganPlotting($r['plotting'])->padaTanggal('2026-10-05')->jamKe(1, 3)->create();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/harian');
    $res->assertOk();

    $sesi = sesiHarianPertama($res);
    expect($sesi['status'])->toBe('sudah')
        ->and($sesi['jurnal_id'])->not->toBeNull()
        ->and($sesi['materi'])->not->toBeNull()
        ->and($res->json('data.hitung.sudah'))->toBe(1)
        ->and($res->json('data.hitung.belum'))->toBe(0)
        ->and($res->json('data.hitung.persen_terisi'))->toEqual(100);
});

it('menandai sesi berhalangan pada hari izin disetujui dan mengeluarkannya dari persentase', function () {
    $r = rangkaianHarian();
    setujuiIzin($r['guru'], '2026-10-05', null, 'sakit');

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/harian');
    $res->assertOk();

    $sesi = sesiHarianPertama($res);
    expect($sesi['status'])->toBe('berhalangan')
        ->and($sesi['alasan'])->toContain('Berhalangan')
        ->and($res->json('data.hitung.berhalangan'))->toBe(1)
        ->and($res->json('data.hitung.belum'))->toBe(0);

    // Penyebut = sesi - berhalangan = 0 → persentase tidak dihitung.
    expect($res->json('data.hitung.persen_terisi'))->toBeNull();
});

it('menandai sesi belum presensi masuk sebagai alasan belum terisi', function () {
    rangkaianHarian(denganPresensi: false);

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/harian');
    $res->assertOk();

    expect(sesiHarianPertama($res)['alasan'])->toBe('Belum presensi masuk');
});

it('tidak menyertakan alasan bila guru sudah presensi tetapi belum mengisi jurnal', function () {
    rangkaianHarian(denganPresensi: true);

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/harian');

    expect(sesiHarianPertama($res)['alasan'])->toBeNull();
});

// =====================================================================
// Otorisasi & tanggal
// =====================================================================

it('dibuka pemantau (admin) dan ditutup bagi guru', function () {
    $r = rangkaianHarian();

    $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/harian')->assertOk();

    // Guru tidak boleh: laporan ini memperlihatkan jurnal guru lain (L(S)).
    $this->actingAs($r['user'])->getJson('/api/v1/laporan/jurnal/harian')->assertForbidden();
});

it('mengosongkan dasbor ketika tanggal pilihan tidak memiliki jadwal', function () {
    rangkaianHarian();

    // Selasa — jadwal uji hanya dipasang pada hari Senin.
    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/jurnal/harian?tanggal=2026-10-06');
    $res->assertOk()
        ->assertJsonPath('data.tanggal', '2026-10-06')
        ->assertJsonPath('data.hari', 'Selasa');

    expect($res->json('data.kelas'))->toBe([])
        ->and($res->json('data.baris'))->toBe([])
        ->and($res->json('data.hitung.sesi'))->toBe(0);
});

// =====================================================================
// KP-5.1 — ekspor dasbor harian
// =====================================================================

it('dapat dicetak ke PDF berisi judul dan nama kelas', function () {
    $r = rangkaianHarian();

    $res = $this->actingAs(sebagaiAdmin())->get('/api/v1/laporan/jurnal/harian?format=pdf');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');

    $teks = mb_strtoupper(teksPdf((string) $res->getContent()), 'UTF-8');
    expect($teks)->toContain('PENGISIAN JURNAL HARIAN')
        ->and($teks)->toContain(mb_strtoupper((string) $r['guru']->nama, 'UTF-8'));
});

it('dapat diekspor ke Excel berisi kolom dan baris sesi', function () {
    $r = rangkaianHarian();

    $res = $this->actingAs(sebagaiAdmin())->get('/api/v1/laporan/jurnal/harian?format=excel');
    $res->assertOk();
    expect($res->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    /** @var BinaryFileResponse $base */
    $base = $res->baseResponse;
    $jalur = $base->getFile()->getPathname();

    expect(substr((string) file_get_contents($jalur), 0, 4))->toBe("PK\x03\x04");

    $spreadsheet = IOFactory::load($jalur);
    $sheet = $spreadsheet->getActiveSheet();

    expect((string) $sheet->getCell('A1')->getValue())->toBe('Kelas')
        ->and((string) $sheet->getCell('B1')->getValue())->toBe('Jam')
        ->and((string) $sheet->getCell('C1')->getValue())->toBe('Mapel')
        ->and((string) $sheet->getCell('D1')->getValue())->toBe('Guru')
        ->and((string) $sheet->getCell('E1')->getValue())->toBe('Status')
        ->and((string) $sheet->getCell('A2')->getValue())->toBe((string) $r['kelas']->nama)
        ->and((string) $sheet->getCell('D2')->getValue())->toBe((string) $r['guru']->nama);

    $spreadsheet->disconnectWorksheets();
});
