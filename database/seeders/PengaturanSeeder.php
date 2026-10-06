<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pengaturan;
use App\Services\PengaturanService;
use Illuminate\Database\Seeder;

/**
 * 7.6 + FR-LOK-05 — nilai awal pengaturan sistem.
 * FR-LOK-06: radius dan parameter teknis tidak di-hardcode di kode aplikasi.
 */
class PengaturanSeeder extends Seeder
{
    /** Karakter kode TV: tanpa karakter membingungkan (0/O, 1/I/L). */
    private const KARAKTER_KODE_TV = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function run(): void
    {
        $nilai = PengaturanService::DEFAULT;
        $nilai['tv_kode'] = $this->kodeTvAcak();
        $nilai['tv_aktif'] = true;

        foreach ($nilai as $kunci => $isi) {
            if ($kunci === 'tv_kode') {
                // Kode TV unik; jangan ditimpa bila sudah ada.
                Pengaturan::firstOrCreate(
                    ['kunci' => 'tv_kode'],
                    ['nilai' => $isi, 'tipe' => 'string'],
                );

                continue;
            }

            Pengaturan::updateOrCreate(
                ['kunci' => $kunci],
                ['nilai' => $this->keTeks($isi), 'tipe' => PengaturanService::TIPE[$kunci] ?? 'string'],
            );
        }

        app(PengaturanService::class)->lupakanCache();
    }

    public function kodeTvAcak(): string
    {
        $panjang = strlen(self::KARAKTER_KODE_TV);
        $kode = '';

        for ($i = 0; $i < 8; $i++) {
            $kode .= self::KARAKTER_KODE_TV[random_int(0, $panjang - 1)];
        }

        return $kode;
    }

    private function keTeks(mixed $nilai): ?string
    {
        return match (true) {
            $nilai === null => null,
            is_bool($nilai) => $nilai ? '1' : '0',
            default => (string) $nilai,
        };
    }
}
