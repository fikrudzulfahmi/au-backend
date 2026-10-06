<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pengaturan;
use Illuminate\Support\Facades\Cache;

/**
 * FR-LOK-06 / 7.6 — pengaturan sistem tersimpan di tabel `pengaturan`.
 * Seluruh nilai dapat diubah admin tanpa deploy ulang.
 */
class PengaturanService
{
    private const PREFIX_CACHE = 'pengaturan:';

    /** Nilai default sesuai tabel kunci tambahan pada 7.6 dan FR-LOK-05. */
    public const DEFAULT = [
        'gps_max_akurasi_m' => 50,
        'foto_max_sisi_px' => 800,
        'foto_kualitas_jpeg' => 65,
        'foto_target_maks_kb' => 150,
        'tv_aktif' => true,
        'tv_izinkan_npsn' => true,
        'tv_interval_detik' => 30,
        'tv_masa_berlaku_hari' => 30,
        'tv_tema' => 'gelap',
        'tv_skala_font' => 'besar',
        'tv_tampilkan_alasan_izin' => false,
        'tv_tampilkan_ulang_tahun' => true,
        'tv_rotasi_panel_detik' => 10,
        'tv_kecepatan_scroll' => 'normal',
        'landing_aktif' => true,
        'landing_judul_hero' => null,
        'landing_tampilkan_peta' => true,
        'landing_tampilkan_pengumuman' => true,
    ];

    public const TIPE = [
        'gps_max_akurasi_m' => 'int',
        'foto_max_sisi_px' => 'int',
        'foto_kualitas_jpeg' => 'int',
        'foto_target_maks_kb' => 'int',
        'tv_aktif' => 'bool',
        'tv_izinkan_npsn' => 'bool',
        'tv_interval_detik' => 'int',
        'tv_masa_berlaku_hari' => 'int',
        'tv_tema' => 'string',
        'tv_skala_font' => 'string',
        'tv_tampilkan_alasan_izin' => 'bool',
        'tv_tampilkan_ulang_tahun' => 'bool',
        'tv_rotasi_panel_detik' => 'int',
        'tv_kecepatan_scroll' => 'string',
        'landing_aktif' => 'bool',
        'landing_judul_hero' => 'string',
        'landing_tampilkan_peta' => 'bool',
        'landing_tampilkan_pengumuman' => 'bool',
    ];

    /** Seluruh pengaturan sebagai array asosiatif (nilai terkonversi). */
    public function semua(): array
    {
        $tersimpan = Cache::remember(
            'pengaturan:semua',
            now()->addMinutes(30),
            fn (): array => Pengaturan::query()
                ->get()
                ->mapWithKeys(fn (Pengaturan $p): array => [$p->kunci => $p->nilaiTerkonversi()])
                ->all()
        );

        return array_merge(self::DEFAULT, $tersimpan);
    }

    public function ambil(string $kunci, mixed $default = null): mixed
    {
        return $this->semua()[$kunci] ?? $default ?? (self::DEFAULT[$kunci] ?? null);
    }

    public function simpan(string $kunci, mixed $nilai): Pengaturan
    {
        $tipe = self::TIPE[$kunci] ?? 'string';

        $tersimpan = match ($tipe) {
            'int' => (string) (int) $nilai,
            'bool' => $nilai ? '1' : '0',
            'json' => json_encode($nilai, JSON_UNESCAPED_UNICODE),
            default => $nilai === null ? null : (string) $nilai,
        };

        $pengaturan = Pengaturan::updateOrCreate(['kunci' => $kunci], ['nilai' => $tersimpan, 'tipe' => $tipe]);
        $this->lupakanCache();

        return $pengaturan;
    }

    public function lupakanCache(): void
    {
        Cache::forget('pengaturan:semua');

        foreach (array_keys(self::DEFAULT) as $kunci) {
            Cache::forget(self::PREFIX_CACHE.$kunci);
        }
    }
}
