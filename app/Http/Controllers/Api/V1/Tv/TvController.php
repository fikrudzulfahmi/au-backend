<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tv;

use App\Http\Controllers\Controller;
use App\Models\ProfilSekolah;
use App\Services\PengaturanService;
use App\Services\TvRekapService;
use App\Services\TvSesiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 5.19 — endpoint Layar TV (baca saja).
 *
 * FR-TV-02/03: `masuk` menerima kode TV (atau NPSN bila `tv_izinkan_npsn`) dan
 * menerbitkan token TV read-only. BR-32 mengunci percobaan kode salah
 * 5×/menit/IP. Token TV hanya diterima middleware `tv`.
 */
class TvController extends Controller
{
    /** BR-32 — batas percobaan kode salah per menit per IP. */
    public const BATAS_PERCOBAAN = 5;

    public function __construct(
        private readonly TvSesiService $sesi,
        private readonly PengaturanService $pengaturan,
        private readonly TvRekapService $rekap,
    ) {}

    /** FR-TV-02/03 — masuk memakai kode TV atau NPSN. Tanpa login pengguna. */
    public function masuk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kode' => ['nullable', 'string', 'max:32'],
            'npsn' => ['nullable', 'string', 'max:16'],
            'nama_perangkat' => ['nullable', 'string', 'max:191'],
        ]);

        if (! (bool) $this->pengaturan->ambil('tv_aktif')) {
            return response()->json([
                'message' => 'Layar TV sedang dinonaktifkan oleh administrator.',
                'code' => 'TV_NONAKTIF',
            ], 403);
        }

        $ip = (string) $request->ip();
        $kunci = 'tv-masuk:'.$ip;

        if (RateLimiter::tooManyAttempts($kunci, self::BATAS_PERCOBAAN)) {
            return response()->json([
                'message' => 'Terlalu banyak percobaan kode TV. Coba lagi dalam '
                    .RateLimiter::availableIn($kunci).' detik.',
                'code' => 'KODE_TV_TERKUNCI',
            ], 429);
        }

        $cocokKode = $this->sesi->kodeCocok($data['kode'] ?? null);
        $cocokNpsn = $this->npsnCocok($data['npsn'] ?? null);

        if (! $cocokKode && ! $cocokNpsn) {
            RateLimiter::hit($kunci, 60);

            return response()->json([
                'message' => 'Kode TV atau NPSN tidak cocok.',
                'code' => 'KODE_TV_SALAH',
            ], 401);
        }

        RateLimiter::clear($kunci);

        $terbit = $this->sesi->terbitkan($data['nama_perangkat'] ?? null, $ip);

        return response()->json([
            'message' => 'Layar TV berhasil terhubung.',
            'data' => [
                'token' => $terbit['token'],
                'kedaluwarsa_at' => $terbit['sesi']->kedaluwarsa_at?->toIso8601String(),
                'tampilan' => $this->rekap->tampilan(),
                'sekolah' => $this->sekolahRingkas(),
            ],
        ]);
    }

    /** KP-6.3 / BR-37 — rekap tiga kolom (presensi, jurnal, perizinan). */
    public function rekap(Request $request): JsonResponse
    {
        $tanggal = $request->query('tanggal');

        return response()->json([
            'data' => $this->rekap->rekap(is_string($tanggal) && $tanggal !== '' ? $tanggal : null),
        ]);
    }

    /** FR-TV-15 — pengaturan tampilan untuk perangkat TV. */
    public function tampilan(): JsonResponse
    {
        return response()->json([
            'data' => [
                'tampilan' => $this->rekap->tampilan(),
                'sekolah' => $this->sekolahRingkas(),
            ],
        ]);
    }

    /** BR-32 — hanya data sekolah ringkas; tanpa NIP/pegawai/siswa. */
    private function sekolahRingkas(): ?array
    {
        $sekolah = ProfilSekolah::query()->first();

        if ($sekolah === null) {
            return null;
        }

        return [
            'nama_sekolah' => $sekolah->nama_sekolah,
            'alamat_lengkap' => $sekolah->alamatLengkap(),
            'tagline' => $sekolah->tagline,
            'nama_kepala_sekolah' => $sekolah->nama_kepala_sekolah,
        ];
    }

    /** BR-32 — NPSN diterima hanya bila `tv_izinkan_npsn` aktif. */
    private function npsnCocok(?string $npsn): bool
    {
        if ($npsn === null || trim($npsn) === '') {
            return false;
        }

        if (! (bool) $this->pengaturan->ambil('tv_izinkan_npsn')) {
            return false;
        }

        $milikSekolah = ProfilSekolah::query()->value('npsn');

        return $milikSekolah !== null
            && trim((string) $milikSekolah) !== ''
            && trim($npsn) === trim((string) $milikSekolah);
    }
}
