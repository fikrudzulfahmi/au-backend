<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * FR-SCH-04 — logo dan foto dikompres otomatis (masukan maksimal 1 MB,
 * hasil akhir <= 300 KB). Dipakai juga oleh foto presensi pada Fase 3 (BR-29).
 */
class BerkasService
{
    public const MAKS_MASUKAN_BYTE = 1024 * 1024;      // 1 MB

    public const MAKS_HASIL_BYTE = 300 * 1024;         // 300 KB

    public function __construct(private readonly PengaturanService $pengaturan) {}

    /**
     * Menyimpan gambar terkompresi ke disk `local` (private) dan mengembalikan path relatif.
     *
     * @param  string  $folder  mis. `sekolah/logo`
     * @param  int  $sisiMaks  sisi terpanjang setelah resize
     */
    public function simpanGambarTerkompres(
        UploadedFile $berkas,
        string $folder,
        int $sisiMaks = 800,
        ?string $namaBerkas = null,
    ): string {
        if (! $berkas->isValid()) {
            throw new RuntimeException('Berkas gagal diunggah. Silakan coba lagi.');
        }

        if ($berkas->getSize() > self::MAKS_MASUKAN_BYTE) {
            throw new RuntimeException('Ukuran gambar melebihi 1 MB. Perkecil gambar terlebih dahulu.');
        }

        $kualitas = (int) $this->pengaturan->ambil('foto_kualitas_jpeg');

        // Intervention Image v3 — pabrik statis, bukan `new ImageManager('gd')` (API v2).
        $gambar = ImageManager::gd()->read($berkas->getRealPath());
        $gambar->scaleDown(width: $sisiMaks, height: $sisiMaks);

        $nama = $namaBerkas ?? uniqid('img_', true);
        $relatif = trim($folder, '/').'/'.$nama.'.jpg';

        $isi = (string) $gambar->toJpeg($kualitas);

        // Pastikan hasil akhir tidak melebihi batas; turunkan kualitas bila perlu.
        $kualitasTurun = $kualitas;
        while (strlen($isi) > self::MAKS_HASIL_BYTE && $kualitasTurun > 25) {
            $kualitasTurun -= 10;
            $isi = (string) $gambar->toJpeg($kualitasTurun);
        }

        Storage::disk('local')->put($relatif, $isi);

        return $relatif;
    }

    /** Menghapus berkas tanpa melempar galat bila tidak ada. */
    public function hapus(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
