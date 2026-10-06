<?php

declare(strict_types=1);

namespace App\Services;

use DateTime;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pembacaan & penulisan berkas Excel untuk import/export master data
 * (FR-SIS-03/04, FR-PEG-03, FR-MPL-02).
 *
 * Kumpulan pengaman yang dipakai di sini berasal dari pengalaman lapangan
 * (lihat skill phpspreadsheet-import-export):
 *  - nilai sel mentah dari `toArray()` — kolom tanggal bisa berupa serial Excel;
 *  - string disanitasi UTF-8 sebelum ditulis, agar sharedStrings.xml tetap
 *    well-formed dan berkas tidak rusak ("unreadable content") saat dibuka;
 *  - berkas ditulis penuh ke berkas sementara lebih dahulu, bukan langsung ke
 *    `php://output`, supaya keluaran tidak terpotong;
 *  - `setCellValue()` TIDAK diberi argumen ketiga (di PhpSpreadsheet 5.x argumen
 *    itu adalah IValueBinder, bukan tipe sel → TypeError).
 */
class ExcelService
{
    public const FORMAT_MASUKAN = ['xlsx', 'xls', 'csv'];

    /** Batas serial tanggal Excel yang wajar (1900-01-01 .. 9999-12-31). */
    private const SERIAL_MIN = 1;

    private const SERIAL_MAX = 2958465;

    /**
     * Membaca lembar pertama menjadi larangan baris: baris pertama dipakai
     * sebagai header (dibuat huruf kecil, tanpa spasi).
     *
     * @return array{header: array<int, string>, baris: array<int, array<string, mixed>>}
     */
    public function baca(UploadedFile $berkas): array
    {
        $spreadsheet = IOFactory::load($berkas->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        $mentah = $sheet->toArray(null, true, false, false);

        if ($mentah === [] || count($mentah) < 2) {
            throw new RuntimeException('Berkas tidak memuat data. Isi minimal satu baris di bawah baris judul.');
        }

        $barisHeader = array_shift($mentah);
        $header = array_map(
            fn ($h): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $h) ?? '', '_')),
            $barisHeader
        );

        $baris = [];
        $nomorBaris = 1; // baris 1 = judul
        foreach ($mentah as $nilai) {
            $nomorBaris++;
            if (count(array_filter($nilai, fn ($v): bool => $v !== null && trim((string) $v) !== '')) === 0) {
                continue; // lewati baris kosong
            }

            $isi = [];
            foreach ($header as $i => $namaKolom) {
                if ($namaKolom === '') {
                    continue;
                }
                $isi[$namaKolom] = $nilai[$i] ?? null;
            }
            $isi['_baris'] = $nomorBaris;
            $baris[] = $isi;
        }

        $spreadsheet->disconnectWorksheets();

        return ['header' => $header, 'baris' => $baris];
    }

    /**
     * Mengubah nilai sel menjadi tanggal `Y-m-d`, atau null bila tidak dikenali.
     * Urutan: serial numerik Excel → berbagai format teks → strtotime.
     */
    public function normalisasiTanggal(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (is_numeric($nilai)) {
            $angka = (float) $nilai;
            $teksAngka = (string) $nilai;

            // Tahun saja (mis. 2024) bukan tanggal. Nilai ini berada di dalam rentang
            // serial Excel (1..2958465) sehingga tanpa penjagaan ini "2024" akan
            // terbaca sebagai 1905-07-18 — bug nyata yang pernah terjadi.
            if (preg_match('/^\d{4}$/', $teksAngka) === 1) {
                return null;
            }

            // Delapan digit angka, mis. 20240517, jelas format Ymd.
            if (preg_match('/^\d{8}$/', $teksAngka) === 1) {
                $tanggal = DateTime::createFromFormat('!Ymd', $teksAngka);
                $galat = DateTime::getLastErrors();
                $bersih = ($galat === false)
                    || ($galat['warning_count'] === 0 && $galat['error_count'] === 0);

                return ($tanggal !== false && $bersih) ? $tanggal->format('Y-m-d') : null;
            }

            // Serial Excel hanya bila jelas di luar rentang tahun yang wajar
            // (1954-09-17 = serial 20000; tahun 1900..2100 hampir pasti bukan serial).
            if ($angka >= self::SERIAL_MIN && $angka <= self::SERIAL_MAX
                && ($angka < 1900 || $angka > 2100)) {
                return ExcelDate::excelToDateTimeObject($angka)->format('Y-m-d');
            }

            return null;
        }

        $teks = trim((string) $nilai);

        // Nama bulan Indonesia → Inggris, karena DateTime hanya mengenali nama Inggris.
        $bulan = [
            'januari' => 'January', 'februari' => 'February', 'maret' => 'March',
            'april' => 'April', 'mei' => 'May', 'juni' => 'June', 'juli' => 'July',
            'agustus' => 'August', 'september' => 'September', 'oktober' => 'October',
            'november' => 'November', 'desember' => 'December',
        ];
        $teks = str_ireplace(array_keys($bulan), array_values($bulan), $teks);

        foreach (['Y-m-d', 'Y/m/d', 'Y.m.d', 'Ymd', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'm/d/Y', 'm-d-Y', 'j-M-Y', 'd-M-Y', 'j F Y', 'd F Y', 'Y-m-d H:i:s', 'd/m/Y H:i:s'] as $format) {
            $tanggal = DateTime::createFromFormat('!'.$format, $teks);
            $galat = DateTime::getLastErrors();

            // PHP 8.2 — getLastErrors() mengembalikan false bila tidak ada galat sama sekali.
            $bersih = ($galat === false)
                || ($galat['warning_count'] === 0 && $galat['error_count'] === 0);

            if ($tanggal !== false && $bersih) {
                return $tanggal->format('Y-m-d');
            }
        }

        $stempel = strtotime($teks);

        return $stempel === false ? null : date('Y-m-d', $stempel);
    }

    /** Membuang byte invalid UTF-8 agar berkas XLSX tidak rusak saat dibuka. */
    public function bersihkanUtf8(mixed $nilai): mixed
    {
        if (! is_string($nilai) || $nilai === '') {
            return $nilai;
        }

        if (preg_match('//u', $nilai) === 1) {
            return $nilai;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($nilai, 'UTF-8', 'UTF-8');
        }

        $bersih = @iconv('UTF-8', 'UTF-8//IGNORE', $nilai);

        return $bersih === false ? '' : $bersih;
    }

    /**
     * Mengirim berkas XLSX sebagai unduhan.
     *
     * @param  array<int, string>  $judul  baris judul
     * @param  array<int, array<int, mixed>>  $baris  isi baris (urutan mengikuti $judul)
     * @param  array<int, int>  $kolomTanggal  indeks kolom (0-based) yang diformat tanggal
     * @param  array<int, int>  $kolomTeks  indeks kolom yang dipaksa bertipe teks (mis. NIS)
     */
    public function unduhXlsx(
        string $namaBerkas,
        array $judul,
        array $baris,
        array $kolomTanggal = [],
        array $kolomTeks = [],
    ): Response {
        // Tulis ke berkas sementara lebih dahulu: menjamin berkas lengkap di disk
        // sebelum dikirim, sehingga tidak ada bagian yang terpotong.
        $sementara = tempnam(sys_get_temp_dir(), 'sipandu_xlsx_').'.xlsx';
        $this->tulisKeBerkas($sementara, $judul, $baris, $kolomTanggal, $kolomTeks);

        // Berkas dikirim lewat BinaryFileResponse Laravel, yang sudah menangani
        // pengiriman berkas secara utuh. Pembersihan buffer manual tidak dilakukan
        // di sini: di dalam Laravel hal itu tidak perlu dan menutup buffer milik
        // kerangka uji sehingga tes ditandai "risky".
        return response()->download($sementara, $namaBerkas, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Menulis XLSX ke sebuah jalur berkas. Dipisah dari pengiriman unduhan agar
     * isi berkas dapat diuji langsung (magic bytes, sharedStrings, dan nilai sel).
     *
     * @param  array<int, string>  $judul
     * @param  array<int, array<int, mixed>>  $baris
     * @param  array<int, int>  $kolomTanggal
     * @param  array<int, int>  $kolomTeks
     */
    public function tulisKeBerkas(
        string $jalur,
        array $judul,
        array $baris,
        array $kolomTanggal = [],
        array $kolomTeks = [],
    ): void {
        if (function_exists('ini_set')) {
            // Peringatan PHP tidak boleh bocor ke berkas biner.
            ini_set('display_errors', '0');
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        $kolom = 'A';
        foreach ($judul as $teks) {
            $sheet->setCellValue($kolom.'1', (string) $this->bersihkanUtf8($teks));
            $sheet->getStyle($kolom.'1')->getFont()->setBold(true);
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
            $kolom++;
        }

        $nomorBaris = 1;
        foreach ($baris as $isiBaris) {
            $nomorBaris++;
            $kolom = 'A';
            foreach (array_values($isiBaris) as $indeks => $nilai) {
                $nilai = $this->bersihkanUtf8($nilai);
                $sel = $kolom.$nomorBaris;

                if ($nilai === null || $nilai === '') {
                    // biarkan kosong
                } elseif (in_array($indeks, $kolomTeks, true)) {
                    // NIS/NISN/NIP dikirim sebagai teks agar nol di depan tidak hilang.
                    $sheet->setCellValueExplicit($sel, (string) $nilai, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($sel, $nilai);
                }

                if (in_array($indeks, $kolomTanggal, true) && $nilai) {
                    $sheet->getStyle($sel)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                }

                $kolom++;
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($jalur);
        $spreadsheet->disconnectWorksheets();
    }

    /** FR-SIS-03 — templat import: baris contoh + tanggal berformat tanggal asli. */
    public function unduhTemplate(string $namaBerkas, array $judul): Response
    {
        // Baris contoh supaya format kolom (khususnya tanggal) terbaca jelas.
        $contoh = array_fill(0, count($judul), '');
        $kolomTanggal = [];
        $kolomTeks = [];

        foreach ($judul as $i => $teks) {
            $huruf = strtolower($teks);
            if (str_contains($huruf, 'tanggal')) {
                $contoh[$i] = '2010-05-17';
                $kolomTanggal[] = $i;
            } elseif (str_contains($huruf, 'nis') || str_contains($huruf, 'nip') || str_contains($huruf, 'nuptk')) {
                $contoh[$i] = '0012345678';
                $kolomTeks[] = $i;
            } else {
                $contoh[$i] = 'contoh';
            }
        }

        return $this->unduhXlsx($namaBerkas, $judul, [$contoh], $kolomTanggal, $kolomTeks);
    }
}
