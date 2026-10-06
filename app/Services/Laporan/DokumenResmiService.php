<?php

declare(strict_types=1);

namespace App\Services\Laporan;

use App\Models\Penandatangan;
use App\Models\PengaturanTtd;
use App\Models\ProfilSekolah;
use App\Services\ExcelService;
use App\Support\DokumenPdf;
use App\Support\PeriodeLaporan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 5.15 / FR-KOP-02..06 — menyiapkan konteks dokumen resmi (kop + blok tanda tangan)
 * dan mengubah "hasil laporan" menjadi berkas PDF atau Excel.
 *
 * KP-5.2 yang paling penting: seluruh nilai dibaca dari pengaturan **saat dokumen
 * dibuat**. Tidak ada penyalinan ke baris laporan, sehingga mengubah kop atau
 * penandatangan lalu mencetak ulang selalu menghasilkan dokumen yang berbeda.
 */
final class DokumenResmiService
{
    public const MAKS_PENANDATANGAN = 2;

    public function __construct(
        private readonly DokumenPdf $dokumen,
        private readonly ExcelService $excel,
    ) {}

    /**
     * Konteks kop & tanda tangan dari pengaturan yang berlaku sekarang.
     *
     * @param  list<int>|null  $penandatanganIds  null = pakai penandatangan default/aktif
     * @return array<string, mixed>
     */
    public function konteks(?array $penandatanganIds = null, ?CarbonImmutable $tanggalCetak = null): array
    {
        $sekolah = ProfilSekolah::query()->first();
        $tata = PengaturanTtd::query()->first();
        $cetak = $tanggalCetak ?? CarbonImmutable::now();

        return [
            'sekolah' => $sekolah,
            'kop' => $this->kop($sekolah),
            'tanda_tangan' => $this->tandaTangan($tata, $sekolah, $cetak, $penandatanganIds),
            'tanggal_cetak' => $cetak,
        ];
    }

    /**
     * @param  array<string, mixed>  $laporan
     */
    public function pdf(array $laporan, ?array $penandatanganIds = null, string $namaBerkas = 'laporan.pdf', string $orientasi = 'portrait'): Response
    {
        return $this->dokumen->unduh(
            $laporan,
            $this->konteks($penandatanganIds),
            $namaBerkas,
            $orientasi,
        );
    }

    /**
     * Isi PDF mentah — dipakai uji untuk memeriksa berkas yang benar-benar dibuat.
     *
     * @param  array<string, mixed>  $laporan
     */
    public function pdfMentah(array $laporan, ?array $penandatanganIds = null): string
    {
        return $this->dokumen->keluaran($laporan, $this->konteks($penandatanganIds));
    }

    /**
     * FR-KOP-05 — pratinjau kop & blok tanda tangan sebelum disimpan.
     * Dikirim inline (bukan unduhan) supaya dapat ditampilkan langsung di peramban.
     *
     * @param  array<string, mixed>  $laporan
     * @param  array<string, mixed>  $konteks
     */
    public function pratinjau(array $laporan, array $konteks): Response
    {
        $keluaran = $this->dokumen->keluaran($laporan, $konteks);

        return response($keluaran, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="pratinjau-dokumen.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $laporan
     */
    public function xlsx(array $laporan, string $namaBerkas): Response
    {
        return $this->excel->unduhXlsx(
            $namaBerkas,
            array_map(fn ($v): string => (string) $v, (array) ($laporan['kolom'] ?? [])),
            array_map(fn ($b): array => array_values((array) $b), (array) ($laporan['baris'] ?? [])),
        );
    }

    /** Nama berkas aman dari judul laporan, mis. "rekap-presensi-pegawai.pdf". */
    public function namaBerkas(string $judul, string $ekstensi): string
    {
        $dasar = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $judul) ?? 'laporan', '-'));

        return ($dasar !== '' ? $dasar : 'laporan').'.'.$ekstensi;
    }

    /** @return array<string, mixed> */
    private function kop(?ProfilSekolah $sekolah): array
    {
        $baris = array_values(array_filter([
            $sekolah?->kop_baris1,
            $sekolah?->kop_baris2,
            $sekolah?->kop_baris3,
        ], fn ($v): bool => trim((string) $v) !== ''));

        if ($baris === [] && $sekolah?->nama_sekolah) {
            $baris = [$sekolah->nama_sekolah];
        }

        $kontak = array_values(array_filter([
            $sekolah?->telepon ? 'Telp. '.$sekolah->telepon : null,
            $sekolah?->email,
            $sekolah?->website,
        ]));

        return [
            'baris' => $baris,
            'alamat' => $sekolah?->alamatLengkap(),
            'kontak' => implode(' | ', $kontak),
            'logo_kiri' => (bool) ($sekolah?->kop_tampilkan_logo_kiri ?? false)
                ? $this->dataUri($sekolah?->logo_kiri_path)
                : null,
            'logo_kanan' => (bool) ($sekolah?->kop_tampilkan_logo_kanan ?? false)
                ? $this->dataUri($sekolah?->logo_kanan_path)
                : null,
        ];
    }

    /**
     * FR-KOP-03/04 — maksimal dua penandatangan, kota & tanggal penetapan, posisi,
     * dan opsi "Mengetahui".
     *
     * @param  list<int>|null  $ids
     * @return array<string, mixed>
     */
    private function tandaTangan(?PengaturanTtd $tata, ?ProfilSekolah $sekolah, CarbonImmutable $cetak, ?array $ids): array
    {
        $penandatangan = Penandatangan::query()
            ->where('is_active', true)
            ->when($ids !== null && $ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderByDesc('is_default')
            ->orderBy('urutan')
            ->orderBy('id')
            ->limit(self::MAKS_PENANDATANGAN)
            ->get();

        $mode = $tata->mode_tanggal ?? 'otomatis';
        $tanggal = ($mode === 'manual' && $tata?->tanggal_manual)
            ? CarbonImmutable::parse($tata->tanggal_manual)
            : $cetak;

        $kota = trim((string) ($tata?->kota_penetapan ?? '')) ?: (string) ($sekolah?->kabupaten_kota ?? '');

        return [
            'kota' => $kota,
            'tanggal' => PeriodeLaporan::tanggalPanjang($tanggal->startOfDay()),
            'posisi' => $tata->posisi ?? 'kanan',
            'mengetahui' => (bool) ($tata?->tampilkan_mengetahui ?? false),
            'penandatangan' => $penandatangan->map(fn (Penandatangan $p): array => [
                'id' => $p->id,
                'jabatan' => $p->jabatan,
                'nama' => $p->nama,
                'nip' => $p->nip,
                'ttd' => $this->dataUri($p->ttd_path),
                'stempel' => $this->dataUri($p->stempel_path),
            ])->all(),
        ];
    }

    /** Gambar disimpan di disk privat; untuk dompdf dikirim sebagai data URI. */
    private function dataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
    }
}
