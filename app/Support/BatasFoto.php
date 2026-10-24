<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\PengaturanService;

/**
 * Batas unggah foto setelah kompresi klien (FR-PRS / BR-29).
 *
 * Klien mengompres setiap foto ke `foto_target_maks_kb` sebelum dikirim, jadi
 * batas server hanya berfungsi sebagai jaring pengaman: ia mengikuti setelan
 * Pengaturan (dua kali target, minimal 1 MB) alih-alih angka tetap 1 MB yang
 * menolak foto sah ketika admin menaikkan target.
 */
final class BatasFoto
{
    public static function kb(): int
    {
        $target = (int) app(PengaturanService::class)->ambil('foto_target_maks_kb');

        return max(1024, $target * 2);
    }

    public static function pesan(): string
    {
        return 'Ukuran tiap foto maksimal '.number_format(self::kb() / 1024, 1, ',', '.').' MB setelah dikompres otomatis.';
    }
}
