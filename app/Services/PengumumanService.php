<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pengumuman;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 5.20 / BR-36 — pengumuman yang berhak tayang.
 *
 * Satu tempat menentukan apa yang tampil, dipakai bersama oleh TV (FR-TV-09,
 * FR-TV-10), beranda aplikasi (FR-PMN-02), dan landing page (FR-LND-05), agar
 * aturan rentang tanggal & target tidak disalin ulang dan menyimpang.
 */
class PengumumanService
{
    /** Daftar pengumuman yang tayang untuk satu target tampil. */
    public function untukTarget(string $kolomTarget, ?CarbonImmutable $saat = null): Collection
    {
        return Pengumuman::query()
            ->tayang($saat)
            ->target($kolomTarget)
            ->orderByRaw("FIELD(prioritas, 'penting', 'normal')")
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Panel TV: kartu bergantian (pengumuman/pengingat) dan teks berjalan.
     * A-23: `teks_berjalan` hanya muncul di footer TV, bukan sebagai kartu.
     *
     * @return array{kartu: array<int, array<string, mixed>>, teks_berjalan: array<int, string>}
     */
    public function untukTv(?CarbonImmutable $saat = null): array
    {
        $semua = $this->untukTarget(Pengumuman::TARGET_TV, $saat);

        $kartu = $semua
            ->reject(fn (Pengumuman $p): bool => $p->teksBerjalan())
            ->map(fn (Pengumuman $p): array => [
                'judul' => $p->judul,
                'isi' => $p->isi,
                'tipe' => $p->tipe,
                'prioritas' => $p->prioritas,
                'penting' => $p->penting(),
            ])
            ->values()
            ->all();

        $berjalan = $semua
            ->filter(fn (Pengumuman $p): bool => $p->teksBerjalan())
            ->map(fn (Pengumuman $p): string => (string) ($p->isi ?? $p->judul))
            ->values()
            ->all();

        return ['kartu' => $kartu, 'teks_berjalan' => $berjalan];
    }
}
