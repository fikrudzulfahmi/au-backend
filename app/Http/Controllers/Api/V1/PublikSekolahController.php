<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SekolahResource;
use App\Models\ProfilSekolah;
use App\Support\UrlBerkasSekolah;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kelompok endpoint `publik` (3.4) — tanpa login.
 * BR-34: hanya info sekolah; tidak ada data pegawai, siswa, atau kehadiran.
 */
class PublikSekolahController extends Controller
{
    public function show(): JsonResponse
    {
        $sekolah = ProfilSekolah::query()->first();

        if (! $sekolah) {
            return response()->json([
                'message' => 'Info sekolah belum diisi oleh administrator.',
                'code' => 'INFO_SEKOLAH_KOSONG',
            ], 404);
        }

        return response()->json(['data' => new SekolahResource($sekolah)]);
    }

    /**
     * FR-SCH-02 — menyajikan logo/favicon/foto sampul sekolah tanpa login.
     *
     * Landing page dan favicon peramban tidak membawa token, sedangkan berkasnya
     * tersimpan di disk privat. Parameter `v` pada URL membuat cache peramban
     * gugur saat admin mengganti gambar.
     */
    public function berkas(string $jenis): Response
    {
        $kolom = UrlBerkasSekolah::JENIS[$jenis] ?? null;
        abort_if($kolom === null, 404);

        $sekolah = ProfilSekolah::query()->first();
        $path = $sekolah?->{$kolom};
        $disk = Storage::disk('local');

        abort_if(! is_string($path) || $path === '' || ! $disk->exists($path), 404);

        return response((string) $disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'image/jpeg',
            'Content-Length' => (string) $disk->size($path),
            'Cache-Control' => 'public, max-age=604800',
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }
}
