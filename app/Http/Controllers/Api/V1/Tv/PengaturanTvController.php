<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tv;

use App\Http\Controllers\Controller;
use App\Models\SesiTv;
use App\Services\AuditLogService;
use App\Services\PengaturanService;
use App\Services\TvRekapService;
use App\Services\TvSesiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-TV-16 — pengaturan Layar TV (admin).
 *
 * Termasuk: aktif/nonaktif, lihat & buat ulang kode TV (BR-35), izinkan NPSN,
 * interval refresh, tema, skala font, opsi alasan izin & ulang tahun, durasi
 * rotasi panel, masa berlaku token, daftar sesi TV aktif dengan tombol cabut,
 * dan pratinjau.
 */
class PengaturanTvController extends Controller
{
    public function __construct(
        private readonly TvSesiService $sesi,
        private readonly PengaturanService $pengaturan,
        private readonly TvRekapService $rekap,
        private readonly AuditLogService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->nilaiPengaturan()]);
    }

    public function simpan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tv_aktif' => ['required', 'boolean'],
            'tv_izinkan_npsn' => ['required', 'boolean'],
            'tv_interval_detik' => ['required', 'integer', 'between:10,120'],
            'tv_masa_berlaku_hari' => ['required', 'integer', 'between:1,365'],
            'tv_tema' => ['required', 'in:terang,gelap'],
            'tv_skala_font' => ['required', 'in:normal,besar,ekstra_besar'],
            'tv_tampilkan_alasan_izin' => ['required', 'boolean'],
            'tv_tampilkan_ulang_tahun' => ['required', 'boolean'],
            'tv_rotasi_panel_detik' => ['required', 'integer', 'between:3,60'],
            'tv_kecepatan_scroll' => ['required', 'in:lambat,normal,cepat'],
        ], [
            'tv_interval_detik.between' => 'Interval refresh 10–120 detik.',
            'tv_masa_berlaku_hari.between' => 'Masa berlaku token 1–365 hari.',
            'tv_rotasi_panel_detik.between' => 'Durasi rotasi panel 3–60 detik.',
        ]);

        foreach ($data as $kunci => $nilai) {
            $this->pengaturan->simpan($kunci, $nilai);
        }

        $this->audit->catat(AuditLogService::AKSI_UBAH_PENGATURAN, $request->user(), null, null, $data);

        return response()->json([
            'message' => 'Pengaturan Layar TV disimpan.',
            'data' => $this->nilaiPengaturan(),
        ]);
    }

    /** FR-TV-16 — lihat kode TV yang berlaku. */
    public function kode(): JsonResponse
    {
        return response()->json(['data' => ['kode' => $this->sesi->kode()]]);
    }

    /** FR-TV-16 / BR-35 — buat ulang kode TV; mencabut SEMUA sesi TV. */
    public function buatUlangKode(Request $request): JsonResponse
    {
        $sebelum = $this->sesi->sesiAktif()->count();
        $kode = $this->sesi->buatUlangKode();
        $sisa = $this->sesi->sesiAktif()->count();

        $this->audit->catat(AuditLogService::AKSI_UBAH_PENGATURAN, $request->user(), null, null, [
            'aksi' => 'buat_ulang_kode_tv',
            'sesi_dicabut' => $sebelum,
        ]);

        return response()->json([
            'message' => 'Kode TV diperbarui. Seluruh sesi TV dicabut.',
            'data' => [
                'kode' => $kode,
                'sesi_dicabut' => $sebelum,
                'sisa_sesi' => $sisa,
            ],
        ]);
    }

    /** FR-TV-16 — daftar sesi TV aktif. */
    public function sesi(): JsonResponse
    {
        $daftar = $this->sesi->sesiAktif()
            ->map(fn (SesiTv $s): array => [
                'id' => $s->id,
                'nama_perangkat' => $s->nama_perangkat,
                'ip' => $s->ip,
                'terakhir_aktif_at' => $s->terakhir_aktif_at?->toIso8601String(),
                'kedaluwarsa_at' => $s->kedaluwarsa_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => $daftar]);
    }

    /** FR-TV-16 — cabut satu sesi TV. */
    public function cabut(Request $request, SesiTv $sesiTv): JsonResponse
    {
        $this->sesi->cabut($sesiTv);

        $this->audit->catat(AuditLogService::AKSI_UBAH_PENGATURAN, $request->user(), null, null, [
            'aksi' => 'cabut_sesi_tv',
            'sesi_id' => $sesiTv->id,
        ]);

        return response()->json(['message' => 'Sesi TV dicabut.']);
    }

    /** FR-TV-16 — pratinjau rekap (admin), memakai layanan yang sama dengan TV. */
    public function pratinjau(Request $request): JsonResponse
    {
        $tanggal = $request->query('tanggal');
        $tanggal = is_string($tanggal) && $tanggal !== '' ? $tanggal : null;

        return response()->json(['data' => $this->rekap->rekap($tanggal)]);
    }

    /** @return array<string, mixed> */
    private function nilaiPengaturan(): array
    {
        return [
            'tv_aktif' => (bool) $this->pengaturan->ambil('tv_aktif'),
            'tv_izinkan_npsn' => (bool) $this->pengaturan->ambil('tv_izinkan_npsn'),
            'tv_interval_detik' => (int) $this->pengaturan->ambil('tv_interval_detik'),
            'tv_masa_berlaku_hari' => (int) $this->pengaturan->ambil('tv_masa_berlaku_hari'),
            'tv_tema' => (string) $this->pengaturan->ambil('tv_tema'),
            'tv_skala_font' => (string) $this->pengaturan->ambil('tv_skala_font'),
            'tv_tampilkan_alasan_izin' => (bool) $this->pengaturan->ambil('tv_tampilkan_alasan_izin'),
            'tv_tampilkan_ulang_tahun' => (bool) $this->pengaturan->ambil('tv_tampilkan_ulang_tahun'),
            'tv_rotasi_panel_detik' => (int) $this->pengaturan->ambil('tv_rotasi_panel_detik'),
            'tv_kecepatan_scroll' => (string) $this->pengaturan->ambil('tv_kecepatan_scroll'),
            'kode' => $this->sesi->kode(),
        ];
    }
}
