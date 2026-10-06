<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Laporan;

use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Http\Resources\PenandatanganResource;
use App\Models\Penandatangan;
use App\Models\PengaturanTtd;
use App\Models\ProfilSekolah;
use Illuminate\Http\JsonResponse;

/**
 * FR-KOP-02..04 — pengaturan dokumen yang boleh dibaca pembuka laporan.
 *
 * Endpoint ini ADA karena pengaturan kop & tanda tangan (`/pengaturan/ttd`)
 * hanya untuk admin, sedangkan wali kelas/guru/kepsek perlu memilih penandatangan
 * saat mencetak laporan. Sama seperti Fase 4: jangan melonggarkan endpoint
 * pengaturan demi satu halaman — sediakan endpoint baca tersendiri.
 */
final class LaporanDokumenController extends Controller
{
    use MemakaiPegawai;

    public function pengaturan(): JsonResponse
    {
        $sekolah = ProfilSekolah::query()->first();
        $tata = PengaturanTtd::query()->first();

        return response()->json([
            'data' => [
                'kop' => [
                    'baris1' => $sekolah?->kop_baris1,
                    'baris2' => $sekolah?->kop_baris2,
                    'baris3' => $sekolah?->kop_baris3,
                    'alamat' => $sekolah?->alamatLengkap(),
                    'tampilkan_logo_kiri' => (bool) ($sekolah?->kop_tampilkan_logo_kiri ?? false),
                    'tampilkan_logo_kanan' => (bool) ($sekolah?->kop_tampilkan_logo_kanan ?? false),
                ],
                'tanda_tangan' => [
                    'kota_penetapan' => $tata?->kota_penetapan,
                    'mode_tanggal' => $tata->mode_tanggal ?? 'otomatis',
                    'tanggal_manual' => $tata?->tanggal_manual?->toDateString(),
                    'posisi' => $tata->posisi ?? 'kanan',
                    'tampilkan_mengetahui' => (bool) ($tata?->tampilkan_mengetahui ?? false),
                ],
                'maks_penandatangan' => 2,
                'penandatangan' => PenandatanganResource::collection(
                    Penandatangan::query()
                        ->where('is_active', true)
                        ->orderByDesc('is_default')
                        ->orderBy('urutan')
                        ->orderBy('id')
                        ->get()
                ),
            ],
        ]);
    }
}
