<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
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

    /**
     * BR-29 / FR-PRS-08 — foto presensi: diperkecil ke `foto_max_sisi_px`,
     * diberi watermark (nama, tanggal-jam server, koordinat), lalu dikompres
     * sampai <= `foto_target_maks_kb`.
     *
     * Batas 150 KB ini berbeda dari logo sekolah (300 KB), karena itu jalur ini
     * tidak memakai MAKS_HASIL_BYTE melainkan setelan dari tabel `pengaturan`.
     *
     * Teks digambar memakai font bawaan GD (tanpa berkas TTF) supaya tetap bekerja
     * di Linux produksi tanpa menambahkan biner font ke repositori.
     */
    public function simpanFotoPresensi(UploadedFile $berkas, string $folder, string $teksWatermark): string
    {
        if (! $berkas->isValid()) {
            throw new RuntimeException('Foto gagal diunggah. Silakan ambil ulang.');
        }

        if ($berkas->getSize() > self::MAKS_MASUKAN_BYTE) {
            throw new RuntimeException('Ukuran foto melebihi 1 MB. Perkecil foto terlebih dahulu.');
        }

        $sisiMaks = max(240, (int) $this->pengaturan->ambil('foto_max_sisi_px'));
        $kualitas = (int) $this->pengaturan->ambil('foto_kualitas_jpeg');
        $targetByte = max(20, (int) $this->pengaturan->ambil('foto_target_maks_kb')) * 1024;

        $gambar = ImageManager::gd()->read($berkas->getRealPath());
        $gambar->scaleDown(width: $sisiMaks, height: $sisiMaks);

        $this->gambarWatermark($gambar, $teksWatermark);

        // Turunkan kualitas dulu; bila belum cukup, perkecil dimensi bertahap.
        $isi = $this->kompresKeTarget($gambar, $kualitas, $targetByte);
        $skala = 0.85;

        while (strlen($isi) > $targetByte && $skala > 0.35) {
            $salinan = ImageManager::gd()->read($berkas->getRealPath());
            $salinan->scaleDown(width: (int) round($sisiMaks * $skala), height: (int) round($sisiMaks * $skala));
            $this->gambarWatermark($salinan, $teksWatermark);

            $calon = $this->kompresKeTarget($salinan, $kualitas, $targetByte);

            if (strlen($calon) < strlen($isi)) {
                $isi = $calon;
            }

            $skala -= 0.15;
        }

        $relatif = trim($folder, '/').'/'.uniqid('psn_', true).'.jpg';
        Storage::disk('local')->put($relatif, $isi);

        return $relatif;
    }

    /** Menulis watermark pada gambar: pita gelap + teks nama/waktu/koordinat. */
    private function gambarWatermark(ImageInterface $gambar, string $teks): void
    {
        $lebar = $gambar->width();
        $tinggi = $gambar->height();

        $baris = explode("\n", $teks);
        $tinggiPita = 16 + (count($baris) * 14);
        $yPita = max(0, $tinggi - $tinggiPita);

        // Pita gelap semi-transparan agar teks terbaca di atas foto apa pun.
        // Tanda tangan v3: drawRectangle($x, $y, $init) — lebar/tinggi diatur di dalam
        // closure (closure menerima RectangleFactory), bukan sebagai argumen terpisah.
        $gambar->drawRectangle(0, $yPita, function ($kotak) use ($lebar, $tinggiPita): void {
            $kotak->size($lebar, $tinggiPita);
            $kotak->background('rgba(0,0,0,0.55)');
        });

        foreach ($baris as $i => $teksBaris) {
            $gambar->text($teksBaris, 8, $yPita + 6 + ($i * 14), function ($font): void {
                $font->size(13);
                $font->color('#ffffff');
            });
        }
    }

    /** Menurunkan kualitas JPEG bertahap sampai di bawah target. */
    private function kompresKeTarget(ImageInterface $gambar, int $kualitas, int $targetByte): string
    {
        $isi = (string) $gambar->toJpeg($kualitas);
        $q = $kualitas;

        while (strlen($isi) > $targetByte && $q > 20) {
            $q -= 10;
            $isi = (string) $gambar->toJpeg($q);
        }

        return $isi;
    }

    /**
     * FR-KOP-03 — gambar tanda tangan & stempel disimpan sebagai PNG agar latar
     * transparan tetap terjaga (tanda tangan digital ditumpuk di atas dokumen).
     *
     * Berbeda dari `simpanGambarTerkompres()` yang selalu menjadi JPEG (sehingga
     * kehilangan alpha), jalur ini mempertahankan PNG.
     */
    public function simpanGambarPng(UploadedFile $berkas, string $folder, int $sisiMaks = 600): string
    {
        if (! $berkas->isValid()) {
            throw new RuntimeException('Berkas gagal diunggah. Silakan coba lagi.');
        }

        if ($berkas->getSize() > self::MAKS_MASUKAN_BYTE) {
            throw new RuntimeException('Ukuran gambar melebihi 1 MB. Perkecil gambar terlebih dahulu.');
        }

        $gambar = ImageManager::gd()->read($berkas->getRealPath());
        $gambar->scaleDown(width: $sisiMaks, height: $sisiMaks);

        $relatif = trim($folder, '/').'/'.uniqid('ttd_', true).'.png';
        Storage::disk('local')->put($relatif, (string) $gambar->toPng());

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
