<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * FR-SCH-02 — URL publik untuk berkas sekolah (logo, favicon, foto sampul).
 *
 * Berkas disimpan di disk `local` (privat) oleh `BerkasService`, jadi tidak bisa
 * ditautkan lewat `/storage/...`; rute publik `publik/sekolah/berkas/{jenis}`
 * yang menyajikannya. Parameter `v` berubah setiap berkas diganti agar peramban
 * tidak terus memakai favicon/logo lama dari cache.
 */
final class UrlBerkasSekolah
{
    /** @var array<string, string> jenis URL => kolom path di `profil_sekolah` */
    public const JENIS = [
        'logo-kiri' => 'logo_kiri_path',
        'logo-kanan' => 'logo_kanan_path',
        'favicon' => 'favicon_path',
        'hero' => 'hero_foto_path',
    ];

    public static function url(string $jenis, ?string $path): ?string
    {
        if ($path === null || $path === '' || ! isset(self::JENIS[$jenis])) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $versi = substr(sha1($path.'|'.$disk->lastModified($path)), 0, 10);

        return url('/api/v1/publik/sekolah/berkas/'.$jenis).'?v='.$versi;
    }
}
