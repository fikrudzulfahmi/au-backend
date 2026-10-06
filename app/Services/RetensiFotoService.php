<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JurnalFoto;
use App\Models\PresensiPegawai;
use App\Models\Semester;
use App\Models\TahunPelajaran;
use Illuminate\Support\Facades\Storage;

/**
 * BR-30 + A-12 — retensi foto.
 *
 * Foto presensi (dan lampiran foto jurnal, karena A-12 menyebut foto jurnal
 * "ikut aturan retensi") disimpan selama satu tahun pelajaran. Setelah tahun
 * pelajaran berstatus `selesai`, berkas fisiknya dibuang, kolom path diisi
 * NULL, dan `foto_dihapus_pada` ditandai. Data teks presensi (waktu, koordinat,
 * status, validasi) tetap utuh — barisnya tidak pernah dihapus.
 *
 * Aman diulang: berkas yang sudah tiada hanya dihitung sebagai "hilang",
 * bukan dianggap gagal. `Storage::delete()` pada berkas tak ada mengembalikan
 * `false` (bukan galat), sehingga keberadaannya diperiksa lebih dulu.
 */
class RetensiFotoService
{
    public function __construct(private readonly WaktuService $waktu) {}

    /**
     * Membersihkan berkas foto seluruh tahun pelajaran yang diberikan.
     *
     * Pemanggil bertanggung jawab menyaring tahun pelajaran berstatus `selesai`;
     * layanan ini tidak pernah memutuskan statusnya sendiri supaya aturan
     * "tahun aktif tidak pernah tersentuh" hanya hidup di satu tempat.
     *
     * @param  iterable<TahunPelajaran>  $daftarTahun
     * @return array{tahun: list<string>, berkas_terhapus: int, berkas_hilang: int, baris_ditandai: int}
     */
    public function bersihkan(iterable $daftarTahun, bool $kering = false): array
    {
        $ringkasan = [
            'tahun' => [],
            'berkas_terhapus' => 0,
            'berkas_hilang' => 0,
            'baris_ditandai' => 0,
        ];

        foreach ($daftarTahun as $tahun) {
            $ringkasan['tahun'][] = (string) $tahun->nama;

            $idSemester = Semester::query()
                ->where('tahun_pelajaran_id', $tahun->getKey())
                ->pluck('id')
                ->all();

            if ($idSemester === []) {
                continue;
            }

            $this->bersihkanFotoPresensi($idSemester, $kering, $ringkasan);
            $this->bersihkanLampiranJurnal($idSemester, $kering, $ringkasan);
        }

        return $ringkasan;
    }

    /**
     * Foto presensi pegawai: `masuk_foto_path` dan `pulang_foto_path`.
     *
     * @param  list<int>  $idSemester
     * @param  array<string, mixed>  $ringkasan
     */
    private function bersihkanFotoPresensi(array $idSemester, bool $kering, array &$ringkasan): void
    {
        PresensiPegawai::query()
            ->whereIn('semester_id', $idSemester)
            ->where(function ($q): void {
                $q->whereNotNull('masuk_foto_path')->orWhereNotNull('pulang_foto_path');
            })
            ->orderBy('id')
            ->chunkById(200, function ($baris) use ($kering, &$ringkasan): void {
                foreach ($baris as $presensi) {
                    $path = array_values(array_filter(
                        [$presensi->masuk_foto_path, $presensi->pulang_foto_path],
                        fn ($p): bool => $p !== null && $p !== '',
                    ));

                    [$terhapus, $hilang] = $this->hapusBerkas($path, $kering);

                    $ringkasan['berkas_terhapus'] += $terhapus;
                    $ringkasan['berkas_hilang'] += $hilang;
                    $ringkasan['baris_ditandai']++;

                    if ($kering) {
                        continue;
                    }

                    // Hanya kolom path dan penanda retensi yang disentuh.
                    $presensi->forceFill([
                        'masuk_foto_path' => null,
                        'pulang_foto_path' => null,
                        'foto_dihapus_pada' => $this->waktu->sekarang(),
                    ])->save();
                }
            });
    }

    /**
     * Lampiran foto jurnal (A-12). Baris jurnal & presensi siswa tidak disentuh.
     *
     * @param  list<int>  $idSemester
     * @param  array<string, mixed>  $ringkasan
     */
    private function bersihkanLampiranJurnal(array $idSemester, bool $kering, array &$ringkasan): void
    {
        JurnalFoto::query()
            ->whereHas('jurnal', fn ($q) => $q->whereIn('semester_id', $idSemester))
            ->whereNotNull('foto_path')
            ->where('foto_path', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($baris) use ($kering, &$ringkasan): void {
                foreach ($baris as $foto) {
                    [$terhapus, $hilang] = $this->hapusBerkas([$foto->foto_path], $kering);

                    $ringkasan['berkas_terhapus'] += $terhapus;
                    $ringkasan['berkas_hilang'] += $hilang;
                    $ringkasan['baris_ditandai']++;

                    if ($kering) {
                        continue;
                    }

                    $foto->forceFill([
                        'foto_path' => null,
                        'foto_dihapus_pada' => $this->waktu->sekarang(),
                    ])->save();
                }
            });
    }

    /**
     * Menghapus sekumpulan berkas dan melaporkan hasilnya dengan benar.
     *
     * @param  list<string>  $path
     * @return array{0: int, 1: int} [berkas terhapus, berkas sudah tiada]
     */
    private function hapusBerkas(array $path, bool $kering): array
    {
        $terhapus = 0;
        $hilang = 0;

        foreach ($path as $p) {
            // Trap: Storage::delete() pada berkas yang tidak ada mengembalikan false,
            // bukan galat. Periksa keberadaannya lebih dulu agar laporan jujur.
            if (! Storage::disk('local')->exists($p)) {
                $hilang++;

                continue;
            }

            if (! $kering) {
                Storage::disk('local')->delete($p);
            }

            $terhapus++;
        }

        return [$terhapus, $hilang];
    }
}
