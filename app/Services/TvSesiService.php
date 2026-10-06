<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SesiTv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * FR-TV-02/03/16 — kode TV dan siklus hidup sesi TV.
 *
 * Kode TV (8 karakter) tersimpan pada tabel `pengaturan` (kunci `tv_kode`).
 * BR-35: membuat ulang kode MENCABUT semua sesi TV yang masih hidup.
 * BR-32: token TV disimpan sebagai hash; hanya endpoint `tv` yang menerimanya.
 */
class TvSesiService
{
    /** Panjang kode TV menurut A-16. */
    public const PANJANG_KODE = 8;

    /**
     * Abjad kode tanpa karakter yang mudah tertukar (0/O, 1/I/L) supaya
     * kode masih enak dibaca dari layar TV jarak jauh.
     */
    private const ABJAD = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly PengaturanService $pengaturan) {}

    /** Kode TV yang berlaku sekarang; dibuat otomatis bila belum pernah ada. */
    public function kode(): string
    {
        $kode = (string) ($this->pengaturan->ambil('tv_kode', '') ?? '');

        if ($kode === '') {
            $kode = $this->kodeBaru();
            $this->pengaturan->simpan('tv_kode', $kode);
        }

        return $kode;
    }

    /** Membuat kode acak baru tanpa menyimpannya (dipakai internal/uji). */
    public function kodeBaru(): string
    {
        $kode = '';

        for ($i = 0; $i < self::PANJANG_KODE; $i++) {
            $kode .= self::ABJAD[random_int(0, strlen(self::ABJAD) - 1)];
        }

        return $kode;
    }

    /**
     * FR-TV-16 / BR-35 — buat ulang kode TV lalu cabut SEMUA sesi TV.
     * Urutan ini penting: sesi lama tidak boleh tetap hidup dengan kode baru.
     */
    public function buatUlangKode(): string
    {
        $kode = $this->kodeBaru();
        $this->pengaturan->simpan('tv_kode', $kode);
        $this->cabutSemua();

        return $kode;
    }

    /** Memeriksa apakah sebuah kode (atau NPSN yang diizinkan) cocok. */
    public function kodeCocok(?string $kode): bool
    {
        if ($kode === null || $kode === '') {
            return false;
        }

        return strtoupper(trim($kode)) === strtoupper($this->kode());
    }

    /**
     * FR-TV-03 — menerbitkan token TV read-only untuk satu perangkat.
     *
     * @return array{sesi: SesiTv, token: string}
     */
    public function terbitkan(?string $namaPerangkat, ?string $ip): array
    {
        $token = bin2hex(random_bytes(32));
        $hari = max(1, (int) $this->pengaturan->ambil('tv_masa_berlaku_hari', 30));
        $sekarang = CarbonImmutable::now();

        $sesi = SesiTv::query()->create([
            'token_hash' => self::hash($token),
            'nama_perangkat' => $namaPerangkat !== null && $namaPerangkat !== ''
                ? mb_substr($namaPerangkat, 0, 191)
                : null,
            'ip' => $ip,
            'terakhir_aktif_at' => $sekarang,
            'kedaluwarsa_at' => $sekarang->addDays($hari),
        ]);

        return ['sesi' => $sesi, 'token' => $token];
    }

    /** Mencari sesi yang sah dari token mentah; memperbarui waktu aktif. */
    public function cari(?string $token): ?SesiTv
    {
        if ($token === null || $token === '') {
            return null;
        }

        $sesi = SesiTv::query()->where('token_hash', self::hash($token))->first();

        if ($sesi === null || ! $sesi->aktif()) {
            return null;
        }

        $sesi->forceFill(['terakhir_aktif_at' => CarbonImmutable::now()])->save();

        return $sesi;
    }

    /** BR-35 — mencabut seluruh sesi TV yang masih hidup. */
    public function cabutSemua(): int
    {
        return SesiTv::query()->whereNull('dicabut_pada')
            ->update(['dicabut_pada' => CarbonImmutable::now()]);
    }

    /** FR-TV-16 — mencabut satu sesi dari daftar sesi aktif. */
    public function cabut(SesiTv $sesi): void
    {
        $sesi->forceFill(['dicabut_pada' => CarbonImmutable::now()])->save();
    }

    /** @return Collection<int, SesiTv> */
    public function sesiAktif(): Collection
    {
        return SesiTv::query()->aktif()
            ->orderByDesc('terakhir_aktif_at')
            ->orderByDesc('id')
            ->get();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
