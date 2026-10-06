<?php

declare(strict_types=1);

use App\Models\Jurnal;
use App\Models\Pegawai;
use App\Models\ProfilSekolah;
use App\Services\Laporan\DokumenResmiService;
use App\Support\DokumenPdf;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
|--------------------------------------------------------------------------
| Fase 5 — ekspor dokumen & kop surat (KP-5.1/KP-5.2, FR-KOP-02..06)
|--------------------------------------------------------------------------
| Uji ini benar-benar MEMBUAT berkas PDF dan Excel, lalu memeriksa isinya.
| Isi PDF diperiksa dengan mengembang (inflate) aliran dokumen (Opsi A, K-70),
| bukan dengan mematikan kompresi.
*/

beforeEach(function (): void {
    siapkanPeran();
});

/**
 * Dokumen contoh untuk uji tata letak & kop (tanpa basis data).
 *
 * @return array<string, mixed>
 */
function laporanContohUji(): array
{
    return [
        'kode' => 'FR-LAP-01',
        'judul' => 'Rekap Presensi Pegawai',
        'periode' => '5 Oktober 2026',
        'kolom' => ['NIP', 'Nama', 'Hadir', 'Alpa'],
        'baris' => [
            ['197801012006041002', 'Budi Santoso', 20, 2],
            ['198002022007011003', 'Siti Aminah', 21, 1],
        ],
        'ringkasan' => [['label' => 'Alpa', 'nilai' => 3]],
    ];
}

/** Teks PDF dalam huruf besar — kop & judul memakai CSS text-transform. */
function teksPdfBesar(string $pdf): string
{
    return mb_strtoupper(teksPdf($pdf), 'UTF-8');
}

// =====================================================================
// KP-5.1 — ekspor PDF nyata
// =====================================================================

it('menghasilkan berkas PDF sah, terkompresi, dan berukuran wajar', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    $r = siapkanPresensi();
    ProfilSekolah::factory()->create([
        'nama_sekolah' => 'SMK Uji SIPANDU',
        'kop_baris1' => 'DINAS PENDIDIKAN',
        'kop_baris2' => 'SMK UJI SIPANDU',
    ]);
    penandatangan(['nama' => 'Drs. Kepala Sekolah']);

    $res = $this->actingAs(sebagaiAdmin())
        ->get('/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini&format=pdf');

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');

    $pdf = (string) $res->getContent();
    $ukuran = strlen($pdf);

    // Berkas PDF sah.
    expect(substr($pdf, 0, 5))->toBe('%PDF-');

    // K-70 — berkas kecil: turun dari ~1,6 MB menjadi puluhan KB (< 300 KB).
    expect($ukuran)->toBeLessThan(300 * 1024);

    // Kompresi aktif: aliran memakai FlateDecode dan teks TIDAK terbaca langsung.
    expect($pdf)->toContain('FlateDecode')
        ->not->toContain('Rekap Presensi Pegawai')
        ->not->toContain('REKAP PRESENSI PEGAWAI');

    // … tetapi tetap dapat dibaca setelah aliran PDF dikembangkan.
    $teks = teksPdfBesar($pdf);
    expect($teks)->toContain('REKAP PRESENSI PEGAWAI')
        ->toContain('DICETAK')
        ->toContain('SMK UJI SIPANDU')
        ->toContain('DRS. KEPALA SEKOLAH')
        ->toContain(mb_strtoupper((string) $r['pegawai']->nama, 'UTF-8'));

    // Bukti ukuran tercetak pada laporan akhir.
    fwrite(STDERR, "\n[ukuran PDF rekap-pegawai] {$ukuran} byte\n");
});

it('menghasilkan PDF laporan jurnal yang juga kecil', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    $r = siapkanJurnal(jamKe: [1, 2, 3]);
    pasangJamKerja(Pegawai::JENIS_GURU);
    ProfilSekolah::factory()->create();

    Jurnal::factory()->denganPlotting($r['plotting'])->padaTanggal('2026-10-05')->jamKe(1, 3)->create();

    $res = $this->actingAs(sebagaiAdmin())
        ->get('/api/v1/laporan/jurnal/daftar?periode=hari_ini&format=pdf');

    $res->assertOk();
    $pdf = (string) $res->getContent();
    $ukuran = strlen($pdf);

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and($ukuran)->toBeLessThan(300 * 1024)
        ->and(teksPdfBesar($pdf))->toContain('DAFTAR JURNAL PEMBELAJARAN');

    fwrite(STDERR, "\n[ukuran PDF jurnal/daftar] {$ukuran} byte\n");
});

it('FR-LAP-02 detail presensi pegawai juga menghasilkan PDF', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    $r = siapkanPresensi();
    ProfilSekolah::factory()->create();

    $res = $this->actingAs(sebagaiAdmin())
        ->get('/api/v1/laporan/presensi/detail-pegawai?periode=hari_ini&pegawai_id='.$r['pegawai']->id.'&format=pdf');

    $res->assertOk();
    $pdf = (string) $res->getContent();
    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(teksPdfBesar($pdf))->toContain('DETAIL PRESENSI HARIAN');
});

// =====================================================================
// KP-5.1 — ekspor Excel nyata (dimuat ulang dengan IOFactory)
// =====================================================================

it('menghasilkan berkas Excel yang isinya dapat dimuat ulang dan diperiksa', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    $r = siapkanPresensi();

    $res = $this->actingAs(sebagaiAdmin())
        ->get('/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini&format=excel');

    $res->assertOk();
    expect($res->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    /** @var BinaryFileResponse $base */
    $base = $res->baseResponse;
    $jalur = $base->getFile()->getPathname();

    expect(file_exists($jalur))->toBeTrue();

    // Magic ZIP — xlsx sesungguhnya adalah arsip ZIP.
    expect(substr((string) file_get_contents($jalur), 0, 4))->toBe("PK\x03\x04");

    $spreadsheet = IOFactory::load($jalur);
    $sheet = $spreadsheet->getActiveSheet();

    // Baris judul kolom persis mengikuti laporan.
    expect((string) $sheet->getCell('A1')->getValue())->toBe('NIP')
        ->and((string) $sheet->getCell('B1')->getValue())->toBe('Nama')
        ->and((string) $sheet->getCell('C1')->getValue())->toBe('Hari Kerja')
        ->and((string) $sheet->getCell('D1')->getValue())->toBe('Hadir');

    // Satu baris data: pegawai uji.
    expect((string) $sheet->getCell('A2')->getValue())->toBe((string) $r['pegawai']->nip)
        ->and((string) $sheet->getCell('B2')->getValue())->toBe((string) $r['pegawai']->nama);

    $spreadsheet->disconnectWorksheets();
});

// =====================================================================
// KP-5.2 — dokumen membaca kop/penandatangan SAAT DICETAK
// =====================================================================

it('KP-5.2 mencerminkan perubahan kop & penandatangan pada cetak berikutnya', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    siapkanPresensi();

    $sekolah = ProfilSekolah::factory()->create(['kop_baris1' => 'SMK SATU']);
    $ttdLama = penandatangan(['nama' => 'Kepala Satu']);
    $admin = sebagaiAdmin();

    // Cetak pertama.
    $pdf1 = (string) $this->actingAs($admin)
        ->get('/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini&format=pdf')
        ->getContent();
    $teks1 = teksPdf($pdf1);

    expect($teks1)->toContain('SMK SATU')
        ->toContain('Kepala Satu');

    // Ubah kop (lewat pengaturan sekolah) & penandatangan (lewat API pengaturan).
    $sekolah->update(['kop_baris1' => 'SMK DUA']);

    $this->actingAs($admin)
        ->putJson('/api/v1/pengaturan/penandatangan/'.$ttdLama->id, [
            'jabatan' => $ttdLama->jabatan,
            'nama' => 'Kepala Dua',
        ])
        ->assertOk();

    // Cetak ulang — dokumen HARUS memakai nilai baru.
    $pdf2 = (string) $this->actingAs($admin)
        ->get('/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini&format=pdf')
        ->getContent();
    $teks2 = teksPdf($pdf2);

    expect($teks2)->toContain('SMK DUA')
        ->toContain('Kepala Dua')
        ->not->toContain('SMK SATU')
        ->not->toContain('Kepala Satu');
});

it('FR-KOP-02 menyediakan endpoint baca pengaturan dokumen bagi pembuka laporan', function () {
    profilSekolah();
    penandatangan();

    $res = $this->actingAs(sebagaiWakasek())->getJson('/api/v1/laporan/pengaturan-dokumen');

    $res->assertOk()
        ->assertJsonPath('data.maks_penandatangan', 2);

    expect($res->json('data.penandatangan'))->toHaveCount(1)
        ->and($res->json('data.kop.baris1'))->toBe('PEMERINTAH PROVINSI JAWA TIMUR');
});

// =====================================================================
// FR-KOP-03 — maksimal dua penandatangan per dokumen
// =====================================================================

it('FR-KOP-03 hanya menanamkan maksimal dua penandatangan pada satu dokumen', function () {
    penandatangan(['nama' => 'Penandatangan Satu', 'urutan' => 1, 'is_default' => true]);
    penandatangan(['nama' => 'Penandatangan Dua', 'urutan' => 2, 'is_default' => false]);
    penandatangan(['nama' => 'Penandatangan Tiga', 'urutan' => 3, 'is_default' => false]);

    $html = app(DokumenPdf::class)->html(laporanContohUji(), app(DokumenResmiService::class)->konteks());

    expect($html)->toContain('Penandatangan Satu')
        ->toContain('Penandatangan Dua')
        ->not->toContain('Penandatangan Tiga');
});

it('FR-KOP-03 menolak 422 bila memilih lebih dari dua penandatangan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

    semesterAktif(tahunAktif());
    siapkanPresensi();
    $a = penandatangan(['nama' => 'A', 'urutan' => 1, 'is_default' => false]);
    $b = penandatangan(['nama' => 'B', 'urutan' => 2, 'is_default' => false]);
    $c = penandatangan(['nama' => 'C', 'urutan' => 3, 'is_default' => false]);

    $this->actingAs(sebagaiAdmin())
        ->getJson(
            '/api/v1/laporan/presensi/rekap-pegawai?periode=hari_ini&format=pdf'
            .'&penandatangan_ids[]='.$a->id
            .'&penandatangan_ids[]='.$b->id
            .'&penandatangan_ids[]='.$c->id
        )
        ->assertStatus(422)
        ->assertJsonValidationErrors('penandatangan_ids');
});

// =====================================================================
// FR-KOP-04 — tata letak tanda tangan (kanan/kiri/dua kolom + Mengetahui)
// =====================================================================

it('FR-KOP-04 menata blok tanda tangan sesuai posisi dan opsi Mengetahui', function (string $posisi, string $kelas) {
    penandatangan(['nama' => 'Kepala Sekolah Uji']);
    tataTtd(['posisi' => $posisi, 'tampilkan_mengetahui' => true, 'kota_penetapan' => 'Surabaya']);

    $html = app(DokumenPdf::class)->html(laporanContohUji(), app(DokumenResmiService::class)->konteks());

    expect($html)->toContain($kelas)
        ->toContain('Mengetahui,')
        ->toContain('Kepala Sekolah Uji');
})->with([
    'kanan' => ['kanan', 'ttd-kanan'],
    'kiri' => ['kiri', 'ttd-kiri'],
    'dua kolom' => ['dua_kolom', 'ttd-dua-kolom'],
]);

it('FR-KOP-04 memakai tanggal manual bila mode tanggal manual', function () {
    penandatangan();
    tataTtd(['mode_tanggal' => 'manual', 'tanggal_manual' => '2026-09-01']);

    $konteks = app(DokumenResmiService::class)->konteks();

    expect($konteks['tanda_tangan']['tanggal'])->toBe('1 September 2026');
});

// =====================================================================
// FR-KOP-05 — pratinjau sebelum disimpan
// =====================================================================

it('FR-KOP-05 menyajikan pratinjau kop & tanda tangan sebagai PDF inline', function () {
    penandatangan(['nama' => 'Penandatangan Pratinjau']);

    $res = $this->actingAs(sebagaiAdmin())->post('/api/v1/pengaturan/kop/pratinjau', [
        'kota_penetapan' => 'Malang',
        'posisi' => 'kiri',
        'tampilkan_mengetahui' => true,
        'mode_tanggal' => 'manual',
        'tanggal_manual' => '2026-09-01',
    ]);

    $res->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('inline');

    $pdf = (string) $res->getContent();

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(teksPdfBesar($pdf))->toContain('PRATINJAU KOP SURAT')
        ->toContain('MALANG')
        ->toContain('1 SEPTEMBER 2026')
        ->toContain('MENGETAHUI,');
});
