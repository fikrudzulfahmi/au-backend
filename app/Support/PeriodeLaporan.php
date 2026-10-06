<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * 5.14 / FR-LAP — penyaring periode laporan.
 *
 * Seluruh laporan menerima periode yang sama: hari ini, minggu ini, bulan ini,
 * atau rentang tanggal bebas. Kelas ini menyelesaikan pilihan itu menjadi dua
 * tanggal kongkret (`dari` dan `sampai`) beserta label yang dipakai pada judul
 * dokumen PDF — supaya tiap laporan tidak menyusun sendiri aturan periodenya.
 *
 * Zona waktu mengikuti `Asia/Jakarta` (waktu server otoritatif, 9), bukan zona
 * waktu mesin pengembang.
 */
final class PeriodeLaporan
{
    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private function __construct(
        public readonly CarbonImmutable $dari,
        public readonly CarbonImmutable $sampai,
        public readonly string $label,
    ) {}

    /**
     * @param  array<string, mixed>  $data  berisi `periode` dan/atau `dari` & `sampai`
     */
    public static function buat(array $data): self
    {
        $sekarang = CarbonImmutable::now()->startOfDay();
        $mode = trim((string) ($data['periode'] ?? ''));

        // Rentang eksplisit tanpa `periode` tetap diperlakukan sebagai rentang.
        if ($mode === '' && (! empty($data['dari']) || ! empty($data['sampai']))) {
            $mode = 'rentang';
        }

        return match ($mode) {
            'hari_ini' => new self($sekarang, $sekarang, self::tanggalPanjang($sekarang)),
            'minggu_ini' => self::mingguIni($sekarang),
            'rentang' => self::rentang($data, $sekarang),
            default => self::bulanIni($sekarang),
        };
    }

    private static function mingguIni(CarbonImmutable $sekarang): self
    {
        $dari = $sekarang->startOfWeek(CarbonImmutable::MONDAY);
        $sampai = $sekarang->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();

        return new self(
            $dari,
            $sampai,
            self::tanggalPanjang($dari).' s.d. '.self::tanggalPanjang($sampai),
        );
    }

    private static function bulanIni(CarbonImmutable $sekarang): self
    {
        $dari = $sekarang->startOfMonth();
        $sampai = $sekarang->endOfMonth()->startOfDay();

        return new self(
            $dari,
            $sampai,
            self::NAMA_BULAN[$sekarang->month].' '.$sekarang->year,
        );
    }

    /** @param array<string, mixed> $data */
    private static function rentang(array $data, CarbonImmutable $sekarang): self
    {
        $dari = ! empty($data['dari'])
            ? CarbonImmutable::parse((string) $data['dari'])->startOfDay()
            : $sekarang->startOfMonth();

        $sampai = ! empty($data['sampai'])
            ? CarbonImmutable::parse((string) $data['sampai'])->startOfDay()
            : $sekarang;

        // Rentang yang terbalik dibalik, bukan ditolak: pengguna yang menukar
        // tanggal lebih baik tetap mendapat laporan daripada galat.
        if ($sampai->lessThan($dari)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        $label = $dari->equalTo($sampai)
            ? self::tanggalPanjang($dari)
            : self::tanggalPanjang($dari).' s.d. '.self::tanggalPanjang($sampai);

        return new self($dari, $sampai, $label);
    }

    /** Seluruh tanggal dalam periode, berurutan naik. */
    public function tanggal(): array
    {
        $daftar = [];
        $t = $this->dari;

        while ($t->lessThanOrEqualTo($this->sampai)) {
            $daftar[] = $t;
            $t = $t->addDay();
        }

        return $daftar;
    }

    public function memuat(CarbonImmutable|string $tanggal): bool
    {
        $t = $tanggal instanceof CarbonImmutable
            ? $tanggal
            : CarbonImmutable::parse($tanggal);

        return $t->betweenIncluded($this->dari, $this->sampai);
    }

    /** Tanggal Indonesia tanpa jam, mis. "5 Oktober 2026". */
    public static function tanggalPanjang(CarbonImmutable $tanggal): string
    {
        return $tanggal->day.' '.self::NAMA_BULAN[$tanggal->month].' '.$tanggal->year;
    }
}
