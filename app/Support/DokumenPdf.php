<?php

declare(strict_types=1);

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-KOP-02..06 / KP-5.1 — pembangun dokumen PDF resmi.
 *
 * SATU kelas ini dipakai SEMUA laporan supaya kop surat, judul, baris periode,
 * tanggal cetak, tabel, dan blok tanda tangan selalu seragam (FR-KOP-06).
 *
 * KP-5.2: dokumen dibangun dari "konteks" yang dibaca saat dokumen DIBUAT — bukan
 * dari nilai yang disalin ke baris laporan. Karena itu mengubah kop/penandatangan
 * pada pengaturan langsung tercermin pada dokumen berikutnya.
 *
 * Catatan teknis (K-70 — perbaikan ukuran berkas):
 * Sebelumnya kelas ini memakai `compress => 0` agar isi PDF dapat dibaca langsung
 * oleh uji, tetapi itu membuat satu laporan kecil mencapai 1,6–2,3 MB — tidak
 * dapat diterima untuk sekolah berinternet lambat. Keluaran sekarang memakai
 * **kompresi normal** (`compress => true`) sekaligus **subsetting font**
 * (`isFontSubsettingEnabled => true`), sehingga hanya glif yang benar-benar
 * dipakai yang ditanam. Ukuran laporan kecil turun ke puluhan KB.
 *
 * Perilaku produksi dan uji kini SAMA (Opsi A): isi dokumen tetap dapat diperiksa
 * dengan cara mengembang (inflate) aliran PDF di dalam uji — lihat helper
 * `teksPdf()` di `tests/Pest.php` — bukan dengan mematikan kompresi.
 */
final class DokumenPdf
{
    /**
     * Opsi keluaran dompdf; dipakai bersama agar hasil uji sama dengan produksi.
     *
     * Catatan: `isFontSubsettingEnabled` TIDAK berlaku bila hanya dikirim ke
     * `output()`; opsi itu dibaca saat penguraian dokumen sehingga harus dipasang
     * lewat `setOption()` pada `pdf()` di bawah.
     */
    public const OPSI_DOMPDF = ['compress' => true];

    /**
     * @param  array<string, mixed>  $laporan  judul, periode, kode, kolom, baris, ringkasan
     * @param  array<string, mixed>  $konteks  sekolah, kop, tanda_tangan
     */
    public function unduh(array $laporan, array $konteks, string $namaBerkas, string $orientasi = 'portrait'): Response
    {
        $pdf = $this->pdf($laporan, $konteks, $orientasi);

        $keluaran = $pdf->output(self::OPSI_DOMPDF);

        return response($keluaran, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$namaBerkas.'"',
            'Content-Length' => (string) strlen($keluaran),
        ]);
    }

    /**
     * Isi PDF mentah — dipakai uji untuk memastikan berkas benar-benar terbentuk
     * dan memuat judul, periode, tanggal cetak, serta nama penandatangan.
     *
     * @param  array<string, mixed>  $laporan
     * @param  array<string, mixed>  $konteks
     */
    public function keluaran(array $laporan, array $konteks, string $orientasi = 'portrait'): string
    {
        return $this->pdf($laporan, $konteks, $orientasi)->output(self::OPSI_DOMPDF);
    }

    /** @param array<string, mixed> $laporan @param array<string, mixed> $konteks */
    private function pdf(array $laporan, array $konteks, string $orientasi): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadHTML($this->html($laporan, $konteks))
            ->setPaper('a4', $orientasi)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans')
            // K-70 — hanya glif yang terpakai yang ditanam; ini yang paling
            // menyusutkan ukuran berkas (font penuh DejaVu Sans ±750 KB).
            ->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * Menyusun HTML dokumen: kop → judul → baris periode & tanggal cetak →
     * ringkasan (bila ada) → tabel → blok tanda tangan.
     *
     * @param  array<string, mixed>  $laporan
     * @param  array<string, mixed>  $konteks
     */
    public function html(array $laporan, array $konteks): string
    {
        $kop = (array) ($konteks['kop'] ?? []);
        $ttd = (array) ($konteks['tanda_tangan'] ?? []);
        $kolom = (array) ($laporan['kolom'] ?? []);
        $baris = (array) ($laporan['baris'] ?? []);

        $kepala = '';
        foreach ((array) ($kop['baris'] ?? []) as $teks) {
            if (is_string($teks) && trim($teks) !== '') {
                $kepala .= '<div class="kop-baris">'.e($teks).'</div>';
            }
        }

        $logoKiri = isset($kop['logo_kiri']) && $kop['logo_kiri']
            ? '<img class="logo" src="'.$kop['logo_kiri'].'" alt="Logo">'
            : '<div class="logo-kosong"></div>';

        $logoKanan = isset($kop['logo_kanan']) && $kop['logo_kanan']
            ? '<img class="logo" src="'.$kop['logo_kanan'].'" alt="Logo">'
            : '<div class="logo-kosong"></div>';

        $alamat = trim((string) ($kop['alamat'] ?? ''));
        $kontak = trim((string) ($kop['kontak'] ?? ''));
        $bawahKop = '';

        if ($alamat !== '') {
            $bawahKop .= '<div class="kop-kecil">'.$alamat.'</div>';
        }
        if ($kontak !== '') {
            $bawahKop .= '<div class="kop-kecil">'.$kontak.'</div>';
        }

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
            .'<style>'.$this->gaya().'</style></head><body>';

        $html .= '<div class="kop">'
            .'<table class="kop-tabel"><tr>'
            .'<td class="kop-logo">'.$logoKiri.'</td>'
            .'<td class="kop-teks">'.$kepala.'</td>'
            .'<td class="kop-logo">'.$logoKanan.'</td>'
            .'</tr></table>'
            .'<div class="kop-alamat">'.$bawahKop.'</div>'
            .'</div>'
            .'<div class="garis-ganda"></div>';

        $html .= '<div class="judul">'.e((string) ($laporan['judul'] ?? '')).'</div>';

        $periode = trim((string) ($laporan['periode'] ?? ''));
        if ($periode !== '') {
            $html .= '<div class="periode">Periode: '.e($periode).'</div>';
        }

        $html .= '<div class="dicetak">Dicetak: '.e($this->waktuCetak($konteks)).'</div>';

        $ringkasan = (array) ($laporan['ringkasan'] ?? []);
        if ($ringkasan !== []) {
            $html .= '<table class="ringkasan"><tr>';
            foreach ($ringkasan as $butir) {
                $html .= '<td><span class="ringkasan-label">'.e((string) ($butir['label'] ?? '')).'</span>'
                    .'<span class="ringkasan-nilai">'.e((string) ($butir['nilai'] ?? '')).'</span></td>';
            }
            $html .= '</tr></table>';
        }

        if ($baris === [] && $kolom !== []) {
            $html .= '<div class="kosong">Tidak ada data pada periode ini.</div>';
        }

        if ($kolom !== []) {
            $html .= $this->tabel($kolom, $baris);
        }

        $html .= $this->blokTandaTangan($ttd);

        $kode = trim((string) ($laporan['kode'] ?? ''));
        if ($kode !== '') {
            $html .= '<div class="kaki">'.$kode.' — SIPANDU</div>';
        }

        return $html.'</body></html>';
    }

    /** @param array<int, string> $kolom @param array<int, array<int, mixed>> $baris */
    private function tabel(array $kolom, array $baris): string
    {
        $html = '<table class="data"><thead><tr>';
        foreach ($kolom as $judul) {
            $html .= '<th>'.e((string) $judul).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($baris as $isi) {
            $html .= '<tr>';
            foreach (array_values((array) $isi) as $nilai) {
                $html .= '<td>'.e($nilai === null ? '' : (string) $nilai).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /** FR-KOP-04 — blok tanda tangan: kota & tanggal, posisi, "Mengetahui". */
    private function blokTandaTangan(array $ttd): string
    {
        $penandatangan = array_values(array_filter(
            (array) ($ttd['penandatangan'] ?? []),
            fn ($p): bool => is_array($p) && trim((string) ($p['nama'] ?? '')) !== '',
        ));

        if ($penandatangan === []) {
            return '';
        }

        // FR-KOP-03 — maksimal 2 penandatangan per dokumen, ditegakkan juga di layanan.
        $penandatangan = array_slice($penandatangan, 0, 2);

        $posisi = (string) ($ttd['posisi'] ?? 'kanan');
        $mengetahui = (bool) ($ttd['mengetahui'] ?? false);
        $kota = trim((string) ($ttd['kota'] ?? ''));
        $tanggal = trim((string) ($ttd['tanggal'] ?? ''));

        $barisKota = trim($kota.($kota !== '' && $tanggal !== '' ? ', ' : '').$tanggal);

        $kelasBlok = match ($posisi) {
            'kiri' => 'ttd-kiri',
            'dua_kolom' => 'ttd-dua-kolom',
            default => 'ttd-kanan',
        };

        $html = '<div class="ttd-wadah"><div class="ttd-'.$this->namaPosisi($posisi).'">';

        if ($mengetahui) {
            $html .= '<div class="mengetahui">Mengetahui,</div>';
        }
        if ($barisKota !== '') {
            $html .= '<div class="kota">'.e($barisKota).'</div>';
        }

        $html .= '<table class="ttd '.$kelasBlok.'"><tr>';

        foreach ($penandatangan as $p) {
            $gambar = '';
            if (! empty($p['ttd'])) {
                $gambar .= '<img class="gambar-ttd" src="'.$p['ttd'].'" alt="Tanda tangan">';
            }
            if (! empty($p['stempel'])) {
                $gambar .= '<img class="gambar-stempel" src="'.$p['stempel'].'" alt="Stempel">';
            }

            $html .= '<td class="ttd-sel">'
                .'<div class="jabatan">'.e((string) ($p['jabatan'] ?? '')).'</div>'
                .'<div class="ruang">'.$gambar.'</div>'
                .'<div class="nama">'.e((string) ($p['nama'] ?? '')).'</div>'
                .(trim((string) ($p['nip'] ?? '')) !== ''
                    ? '<div class="nip">NIP. '.e((string) $p['nip']).'</div>'
                    : '')
                .'</td>';
        }

        $html .= '</tr></table></div></div>';

        return $html;
    }

    private function namaPosisi(string $posisi): string
    {
        return match ($posisi) {
            'kiri' => 'kiri',
            'dua_kolom' => 'dua-kolom',
            default => 'kanan',
        };
    }

    /** @param array<string, mixed> $konteks */
    private function waktuCetak(array $konteks): string
    {
        $cetak = $konteks['tanggal_cetak'] ?? null;

        $waktu = $cetak instanceof CarbonImmutable
            ? $cetak
            : CarbonImmutable::now();

        return $waktu->format('d/m/Y H:i');
    }

    private function gaya(): string
    {
        return <<<'CSS'
        @page { margin: 18mm 15mm 20mm 15mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #111; }
        .kop-tabel { width: 100%; border-collapse: collapse; }
        .kop-logo { width: 90px; text-align: center; vertical-align: middle; }
        .logo { height: 68px; }
        .logo-kosong { height: 68px; }
        .kop-teks { text-align: center; vertical-align: middle; }
        .kop-baris { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop-alamat { text-align: center; font-size: 9px; margin-top: 2px; }
        .kop-kecil { font-size: 9px; }
        .garis-ganda { border-top: 3px solid #111; border-bottom: 1px solid #111; height: 2px; margin-top: 4px; }
        .judul { text-align: center; font-size: 14px; font-weight: bold; margin-top: 12px; text-transform: uppercase; }
        .periode { text-align: center; font-size: 10px; margin-top: 2px; }
        .dicetak { text-align: right; font-size: 8px; color: #444; margin-top: 4px; }
        .ringkasan { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .ringkasan td { border: 1px solid #999; padding: 3px 5px; text-align: center; }
        .ringkasan-label { display: block; font-size: 8px; color: #444; }
        .ringkasan-nilai { display: block; font-size: 11px; font-weight: bold; }
        .kosong { margin-top: 10px; font-style: italic; color: #555; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data th, table.data td { border: 1px solid #666; padding: 3px 4px; font-size: 9px; }
        table.data th { background: #e6e6e6; text-align: center; }
        .ttd-wadah { margin-top: 18px; width: 100%; overflow: hidden; }
        .ttd-kanan { margin-left: auto; width: 45%; }
        .ttd-kiri { margin-right: auto; width: 45%; }
        .ttd-dua-kolom { width: 100%; }
        .mengetahui { font-size: 10px; }
        .kota { font-size: 10px; text-align: left; margin-bottom: 2px; }
        table.ttd { border-collapse: collapse; width: 100%; }
        table.ttd td.ttd-sel { vertical-align: top; text-align: center; padding: 0 6px; }
        .jabatan { font-size: 10px; }
        .ruang { height: 52px; position: relative; }
        .gambar-ttd { max-height: 48px; max-width: 150px; }
        .gambar-stempel { max-height: 52px; max-width: 60px; position: absolute; left: 55%; top: 0; }
        .nama { font-size: 10px; font-weight: bold; text-decoration: underline; }
        .nip { font-size: 9px; }
        .kaki { margin-top: 14px; font-size: 8px; color: #666; text-align: right; }
        CSS;
    }
}
