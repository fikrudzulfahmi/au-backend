<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Http\Requests\PresensiRequest;
use App\Http\Resources\PresensiResource;
use App\Models\PresensiPegawai;
use App\Services\PresensiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FR-PRS-01..13 — presensi masuk/pulang, status hari ini, riwayat, dan penyajian foto.
 * Hanya untuk pegawai yang bersangkutan (Bagian 2: "presensi" = pegawai).
 */
final class PresensiController extends Controller
{
    use MemakaiPegawai;

    public function __construct(private readonly PresensiService $presensi) {}

    /** FR-PRS — status hari ini untuk beranda dan gerbang tombol presensi. */
    public function hariIni(Request $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);
        $status = $this->presensi->statusHariIni($pegawai);

        return response()->json([
            'data' => [
                'tanggal' => $status['tanggal'],
                'nama_hari' => $status['nama_hari'],
                'is_hari_kerja' => $status['is_hari_kerja'],
                'hari_libur' => $status['hari_libur'],
                'buka_presensi' => $status['buka_presensi'],
                'jam_masuk' => $status['jam_masuk'],
                'jam_pulang' => $status['jam_pulang'],
                'boleh_masuk' => $status['boleh_masuk'],
                'alasan_tidak_boleh_masuk' => $status['alasan_tidak_boleh_masuk'],
                'boleh_pulang' => $status['boleh_pulang'],
                'pengajuan' => $status['pengajuan'] === null ? null : [
                    'jenis' => $status['pengajuan']->jenis,
                    'label_jenis' => $status['pengajuan']->labelJenis(),
                ],
                'presensi' => $status['presensi'] === null ? null : new PresensiResource($status['presensi']),
                // Daftar lokasi efektif dipakai klien untuk menampilkan radius terdekat.
                'lokasi' => $status['lokasi']->map(fn ($l): array => [
                    'id' => $l->id, 'nama' => $l->nama, 'radius_m' => (int) $l->radius_m,
                    'latitude' => (float) $l->latitude, 'longitude' => (float) $l->longitude,
                    'is_default' => (bool) $l->is_default,
                ])->all(),
            ],
        ]);
    }

    public function masuk(PresensiRequest $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $presensi = $this->presensi->masuk($pegawai, $request->validated(), $request->user());

        return response()->json([
            'message' => $this->pesanMasuk($presensi),
            'data' => new PresensiResource($presensi->load(['masukLokasi'])),
        ], 201);
    }

    public function pulang(PresensiRequest $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $presensi = $this->presensi->pulang($pegawai, $request->validated(), $request->user());

        return response()->json([
            'message' => $presensi->pulang_status === PresensiPegawai::PULANG_CEPAT
                ? "Presensi pulang tersimpan. Anda pulang {$presensi->pulang_menit_cepat} menit lebih awal."
                : 'Presensi pulang tersimpan.',
            'data' => new PresensiResource($presensi->load(['masukLokasi', 'pulangLokasi'])),
        ]);
    }

    /** FR-PRS-12 — riwayat presensi milik sendiri. */
    public function riwayat(Request $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $daftar = $this->presensi->riwayat($pegawai, [
            'dari' => $request->date('dari')?->toDateString(),
            'sampai' => $request->date('sampai')?->toDateString(),
        ], $this->perHalaman($request));

        return response()->json([
            'data' => PresensiResource::collection($daftar->items()),
            'meta' => [
                'page' => $daftar->currentPage(),
                'per_page' => $daftar->perPage(),
                'total' => $daftar->total(),
                'last_page' => $daftar->lastPage(),
            ],
        ]);
    }

    /**
     * Menyajikan foto presensi dari disk privat.
     * Pemilik foto dan peran pemantau boleh melihat; selain itu ditolak.
     */
    public function foto(Request $request, PresensiPegawai $presensi, string $sisi): BinaryFileResponse|JsonResponse
    {
        $user = $this->pengguna($request);
        $pemilik = $user->pegawai_id !== null && (int) $user->pegawai_id === (int) $presensi->pegawai_id;

        if (! $pemilik && ! $this->penggunaMemantau($request)) {
            return response()->json(['message' => 'Anda tidak berhak melihat foto presensi ini.'], 403);
        }

        $path = $sisi === 'pulang' ? $presensi->pulang_foto_path : $presensi->masuk_foto_path;

        // BR-30 — foto boleh sudah dihapus karena retensi; itu bukan galat 500.
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Foto tidak tersedia (mungkin sudah dihapus sesuai masa retensi).'], 404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    private function pesanMasuk(PresensiPegawai $presensi): string
    {
        if ($presensi->masuk_validasi === PresensiPegawai::MENUNGGU) {
            return 'Presensi tersimpan dan menunggu persetujuan admin karena Anda berada di luar radius (BR-17).';
        }

        if ($presensi->masuk_validasi === PresensiPegawai::DISETUJUI) {
            return 'Presensi tersimpan. Anda di luar radius, tetapi pengajuan luar radius Anda sudah disetujui.';
        }

        return $presensi->masuk_status === PresensiPegawai::STATUS_TERLAMBAT
            ? "Presensi masuk tersimpan. Anda terlambat {$presensi->masuk_menit_terlambat} menit."
            : 'Presensi masuk tersimpan. Selamat bekerja.';
    }
}
