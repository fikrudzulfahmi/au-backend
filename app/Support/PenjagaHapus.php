<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjaga penghapusan master data.
 *
 * Aturan spesifikasi yang dilindungi:
 *  - FR-SIS-06 — siswa yang sudah punya presensi tidak boleh dihapus;
 *  - FR-KLS-05 — kelas yang sudah memiliki jurnal tidak boleh dihapus;
 *  - FR-MPL-03 — mapel yang sudah dipakai plotting tidak boleh dihapus.
 *
 * Tabel rujukan baru ada pada fase berikutnya (`plotting_mapel` Fase 2,
 * `presensi_siswa` Fase 4, `jurnal` Fase 4). Pemeriksaan dilakukan terhadap
 * keberadaan tabel, sehingga penjaga ini otomatis aktif begitu tabelnya dibuat
 * tanpa perlu mengubah controller lagi. Selama tabelnya belum ada, penghapusan
 * tetap berupa soft delete sehingga riwayat tidak benar-benar hilang.
 */
final class PenjagaHapus
{
    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $ketergantungan
     *                                                                              setiap pasangan: [nama tabel, nama kolom, keterangan Bahasa Indonesia]
     * @return string|null pesan penolakan, atau null bila boleh dihapus
     */
    public static function periksa(int $id, array $ketergantungan): ?string
    {
        foreach ($ketergantungan as [$tabel, $kolom, $keterangan]) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }

            if (DB::table($tabel)->where($kolom, $id)->exists()) {
                return "Data tidak dapat dihapus karena sudah dipakai pada {$keterangan}.";
            }
        }

        return null;
    }
}
