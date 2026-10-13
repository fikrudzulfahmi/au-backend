<?php

declare(strict_types=1);

use App\Models\JamKerja;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PresensiPegawai;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
|--------------------------------------------------------------------------
| Fase 5 — rekap datang & pulang seluruh pegawai (matriks ala mesin absensi)
|--------------------------------------------------------------------------
| Satu baris per pegawai, satu kolom per tanggal. Mencakup seluruh tanggal
| dalam periode; hari libur sengaja tetap ada sebagai kolom kosong agar
| seluruh baris memiliki lebar yang sama.
*/

beforeEach(function (): void {
    siapkanPeran();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));
});

/**
 * Dua pegawai berbeda jenis, masing-masing dengan satu presensi pada hari
 * berbeda, plus satu pengajuan izin disetujui.
 *
 * @return array<string, mixed>
 */
function rangkaianMatriks(): array
{
    $semester = semesterAktif(tahunAktif());

    foreach (range(1, 7) as $hari) {
        $kerja = $hari <= 5;
        foreach ([Pegawai::JENIS_GURU, Pegawai::JENIS_STRUKTURAL] as $jenis) {
            JamKerja::factory()->create([
                'jenis_pegawai' => $jenis,
                'hari' => $hari,
                'is_hari_kerja' => $kerja,
                'buka_presensi' => $kerja ? '06:30:00' : null,
                'jam_masuk' => $kerja ? '07:00:00' : null,
                'jam_pulang' => $kerja ? '15:00:00' : null,
            ]);
        }
    }

    $guru = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_GURU]);
    $struktural = Pegawai::factory()->create(['jenis_pegawai' => Pegawai::JENIS_STRUKTURAL]);

    // Senin 28 September: guru hadir lengkap (masuk 07:01, pulang 15:02).
    PresensiPegawai::factory()->create([
        'pegawai_id' => $guru->id,
        'tanggal' => '2026-09-28',
        'semester_id' => $semester->id,
        'masuk_waktu' => '2026-09-28 07:01:00',
        'pulang_waktu' => '2026-09-28 15:02:00',
        'pulang_status' => 'normal',
        'pulang_validasi' => PresensiPegawai::VALID,
    ]);

    // Selasa: struktural berhalangan sakit.
    setujuiIzin($struktural, '2026-09-29', null, PengajuanIzin::JENIS_SAKIT);

    return compact('guru', 'struktural', 'semester');
}

/** @return array<string, mixed> */
function barisMatriks(TestResponse $res, string $nip): array
{
    return collect($res->json('data.pegawai'))->firstWhere('nip', $nip) ?? [];
}

/** Sel matriks untuk pegawai & tanggal tertentu. */
function selMatriks(TestResponse $res, string $nip, string $tanggal): array
{
    $sel = collect(barisMatriks($res, $nip)['sel'])->firstWhere('tanggal', $tanggal);

    return is_array($sel) ? $sel : [];
}

// =====================================================================
// Bentuk matriks
// =====================================================================

it('menyusun satu baris per pegawai dan satu kolom per tanggal', function () {
    $r = rangkaianMatriks();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-28&sampai=2026-10-04');
    $res->assertOk();

    // Senin..Sabtu: tanggal di rentang 5-11 Oktober 2026.
    expect($res->json('data.pegawai'))->toHaveCount(2)
        ->and($res->json('data.tanggal'))->toHaveCount(7)
        ->and($res->json('data.kolom'))->toHaveCount(10); // NIP + Nama + Jenis + 7 tanggal

    $baris = collect($res->json('data.baris'))->firstWhere('0', $r['guru']->nip);
    expect($baris)->not->toBeNull()
        ->and($baris[1])->toBe($r['guru']->nama)
        ->and($baris[2])->toBe('guru');
});

it('mengisi sel dengan jam datang dan pulang bila pegawainya sudah presensi', function () {
    rangkaianMatriks();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-28&sampai=2026-10-02');

    // Baris guru ada; sel Senin memuat jam masuk & pulang.
    $baris = collect($res->json('data.pegawai'))->first(fn (array $m): bool => $m['jenis_pegawai'] === 'guru');
    $senin = collect($baris['sel'])->firstWhere('tanggal', '2026-09-28');

    expect($senin['masuk'])->toBe('07:01')
        ->and($senin['pulang'])->toBe('15:02')
        ->and($senin['teks'])->toBe('07:01-15:02')
        ->and($senin['hari_kerja'])->toBeTrue();
});

it('mengisi sel dengan nama izin pada hari pengajuan disetujui', function () {
    rangkaianMatriks();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-29&sampai=2026-09-29');

    $baris = collect($res->json('data.pegawai'))->first(fn (array $m): bool => $m['jenis_pegawai'] === 'struktural');
    $selasa = collect($baris['sel'])->firstWhere('tanggal', '2026-09-29');

    expect($selasa['teks'])->toBe('Sakit')
        ->and($selasa['masuk'])->toBeNull();
});

it('menandai hari kerja tanpa catatan dan mengosongkan hari libur', function () {
    $r = rangkaianMatriks();

    $res = $this->actingAs(sebagaiAdmin())->getJson('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-28&sampai=2026-10-04');
    $res->assertOk();

    $sel = collect($res->json('data.tanggal'))->map(fn (array $t): string => $t['hari'])->all();
    expect($sel)->toBe(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']);

    // Jumat: hari kerja tetapi guru tidak punya catatan.
    $jumat = selMatriks($res, (string) $r['guru']->nip, '2026-10-02');
    expect($jumat['hari_kerja'])->toBeTrue()
        ->and($jumat['teks'])->toBe('—');

    // Sabtu & Minggu: bukan hari kerja → sel tetap ada tetapi kosong.
    $sabtu = selMatriks($res, (string) $r['guru']->nip, '2026-10-03');
    expect($sabtu['hari_kerja'])->toBeFalse()
        ->and($sabtu['teks'])->toBe('');
});

// =====================================================================
// FR-LAP-12 — pembatasan data per peran
// =====================================================================

it('membatasi peran L(S) hanya pada barisnya sendiri', function () {
    $r = rangkaianMatriks();
    $akun = buatPegawaiDenganAkun(Pegawai::JENIS_GURU);

    $res = $this->actingAs($akun)
        ->getJson('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-28&sampai=2026-10-02');
    $res->assertOk();

    expect($res->json('data.pegawai'))->toHaveCount(1)
        ->and($res->json('data.pegawai.0.pegawai_id'))->toBe((int) $akun->pegawai_id);

    // Meminta pegawai lain ditolak.
    $this->actingAs($akun)
        ->getJson('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-28&sampai=2026-10-02&pegawai_id='.$r['guru']->id)
        ->assertForbidden();
});

// =====================================================================
// KP-5.1 — ekspor matriks
// =====================================================================

it('dapat dicetak ke PDF berisi nama pegawai dan tanggal', function () {
    $r = rangkaianMatriks();

    $res = $this->actingAs(sebagaiAdmin())->get('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-28&sampai=2026-10-02&format=pdf');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');

    $teks = mb_strtoupper(teksPdf((string) $res->getContent()), 'UTF-8');
    expect($teks)->toContain('REKAP DATANG')
        ->and($teks)->toContain(mb_strtoupper((string) $r['guru']->nama, 'UTF-8'));
});

it('dapat diekspor ke Excel dengan kolom tanggal pada baris judul', function () {
    $r = rangkaianMatriks();

    $res = $this->actingAs(sebagaiAdmin())->get('/api/v1/laporan/presensi/datang-pulang?periode=rentang&dari=2026-09-28&sampai=2026-10-02&format=excel');
    $res->assertOk();

    /** @var BinaryFileResponse $base */
    $base = $res->baseResponse;
    $jalur = $base->getFile()->getPathname();

    expect(substr((string) file_get_contents($jalur), 0, 4))->toBe("PK\x03\x04");

    $spreadsheet = IOFactory::load($jalur);
    $sheet = $spreadsheet->getActiveSheet();

    expect((string) $sheet->getCell('A1')->getValue())->toBe('NIP')
        ->and((string) $sheet->getCell('D1')->getValue())->toBe('28/09');

    // Baris diurutkan menurut nama, jadi nip dicari dulu — bukan diasumsikan
    // berada pada baris kedua.
    $barisGuru = null;
    for ($i = 2; $i <= $sheet->getHighestDataRow(); $i++) {
        if ((string) $sheet->getCell('A'.$i)->getValue() === (string) $r['guru']->nip) {
            $barisGuru = $i;
            break;
        }
    }

    expect($barisGuru)->not->toBeNull()
        // Sel Senin milik guru memuat jam datang & pulang.
        ->and((string) $sheet->getCell('D'.$barisGuru)->getValue())->toBe('07:01-15:02');

    $spreadsheet->disconnectWorksheets();
});
