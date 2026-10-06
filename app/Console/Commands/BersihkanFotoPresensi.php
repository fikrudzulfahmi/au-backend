<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TahunPelajaran;
use App\Services\AuditLogService;
use App\Services\RetensiFotoService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * BR-30 — retensi foto: berkas foto presensi (dan lampiran jurnal, A-12) milik
 * tahun pelajaran berstatus `selesai` dibuang setelah masa simpannya berakhir.
 * Kolom path diisi NULL dan `foto_dihapus_pada` ditandai; data teks presensi
 * (waktu, koordinat, status, validasi) tetap tersimpan.
 *
 * Pengaman terpenting: tahun pelajaran yang BELUM berstatus `selesai` tidak
 * pernah disentuh, termasuk bila diminta langsung lewat `--tahun-pelajaran`.
 * Perintah aman dijalankan berulang (idempoten).
 */
class BersihkanFotoPresensi extends Command
{
    protected $signature = 'presensi:bersihkan-foto
        {--tahun-pelajaran= : Nama atau id satu tahun pelajaran tertentu (opsional)}
        {--kering : Mode aman — laporkan saja, tidak menghapus apa pun}
        {--dry-run : Sinonim dari --kering}';

    protected $description = 'Menghapus berkas foto presensi & lampiran tahun pelajaran berstatus selesai (BR-30)';

    public function handle(RetensiFotoService $retensi, AuditLogService $audit): int
    {
        $kering = (bool) $this->option('kering') || (bool) $this->option('dry-run');

        $diminta = (string) ($this->option('tahun-pelajaran') ?? '');
        $daftar = null;

        if ($diminta !== '') {
            $tahun = $this->cariTahun($diminta);

            if ($tahun === null) {
                $this->error("Tahun pelajaran \"{$diminta}\" tidak ditemukan. Pembersihan dibatalkan.");

                return self::FAILURE;
            }

            if (! $tahun->isSelesai()) {
                $this->warn("Tahun pelajaran {$tahun->nama} berstatus \"{$tahun->status}\", bukan \"selesai\" — tidak ada berkas yang dibersihkan. Berkas tahun yang masih aktif tidak pernah dihapus (BR-30).");

                return self::SUCCESS;
            }

            $daftar = new Collection([$tahun]);
        } else {
            $daftar = TahunPelajaran::query()
                ->where('status', TahunPelajaran::STATUS_SELESAI)
                ->orderBy('id')
                ->get();
        }

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada tahun pelajaran berstatus "selesai". Tidak ada berkas yang dibersihkan.');

            return self::SUCCESS;
        }

        $ringkasan = $retensi->bersihkan($daftar, $kering);

        $this->laporkan($ringkasan, $kering);

        if (! $kering) {
            $audit->catat(AuditLogService::AKSI_BERSIHKAN_FOTO, null, null, null, $ringkasan);
        }

        return self::SUCCESS;
    }

    /** Mencari tahun pelajaran lewat id (bila angka) atau nama persis. */
    private function cariTahun(string $diminta): ?TahunPelajaran
    {
        if (ctype_digit($diminta)) {
            $lewatId = TahunPelajaran::query()->find((int) $diminta);

            if ($lewatId !== null) {
                return $lewatId;
            }
        }

        return TahunPelajaran::query()->where('nama', $diminta)->first();
    }

    /**
     * @param  array{tahun: list<string>, berkas_terhapus: int, berkas_hilang: int, baris_ditandai: int}  $ringkasan
     */
    private function laporkan(array $ringkasan, bool $kering): void
    {
        $kata = $kering ? 'akan dihapus' : 'dihapus';

        if ($kering) {
            $this->warn('[MODE KERING] Tidak ada berkas yang benar-benar dihapus maupun kolom yang diubah.');
        }

        $this->line('Tahun pelajaran diproses: '.implode(', ', $ringkasan['tahun']));

        $this->table(['Keterangan', 'Jumlah'], [
            ["Berkas {$kata}", (string) $ringkasan['berkas_terhapus']],
            ['Berkas sudah tiada (dilewati)', (string) $ringkasan['berkas_hilang']],
            ['Baris ditandai (path NULL + foto_dihapus_pada)', (string) $ringkasan['baris_ditandai']],
        ]);
    }
}
