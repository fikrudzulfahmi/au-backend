<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fase 5 — header CORS untuk ekspor (KP-5.1)
|--------------------------------------------------------------------------
| Bug nyata yang dijaga uji ini: tanpa `Access-Control-Expose-Headers:
| Content-Disposition`, peramban tidak memberikan header itu kepada JavaScript.
| Frontend lalu memakai nama berkas cadangan yang berakhiran `.pdf`, sehingga
| ekspor Excel terunduh bernama .pdf dan pengguna mengira berkasnya rusak.
| Ekspor PDF tampak benar hanya karena kebetulan cadangannya memang .pdf.
*/

beforeEach(function (): void {
    siapkanPeran();
    // Laporan menuntut tahun pelajaran/semester aktif (FR-LAP-11).
    semesterAktif();
});

it('menyatakan Content-Disposition dapat dibaca JavaScript lintas asal', function () {
    $admin = sebagaiAdmin();

    $respon = $this->actingAs($admin)
        ->getJson('/api/v1/laporan/presensi/rekap-pegawai?periode=bulan_ini', [
            'Origin' => 'http://127.0.0.1:5173',
        ]);

    $respon->assertOk();

    $terbuka = (string) $respon->headers->get('Access-Control-Expose-Headers');

    expect($terbuka)->toContain('Content-Disposition');
});

it('memberi nama berkas berakhiran tepat pada ekspor Excel dan PDF', function (string $format, string $ekstensi) {
    $admin = sebagaiAdmin();

    $respon = $this->actingAs($admin)
        ->get('/api/v1/laporan/presensi/rekap-pegawai?periode=bulan_ini&format='.$format, [
            'Origin' => 'http://127.0.0.1:5173',
        ]);

    $respon->assertOk();

    $disposisi = (string) $respon->headers->get('Content-Disposition');

    expect($disposisi)->toContain('.'.$ekstensi);
})->with([
    'excel' => ['excel', 'xlsx'],
    'pdf' => ['pdf', 'pdf'],
]);
