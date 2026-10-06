<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\HariLibur;
use App\Models\JamKerja;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * FR-LOK-04 — jam kerja per jenis pegawai per hari.
 * Menjadi acuan BR-15 (terlambat), BR-16 (pulang cepat), dan BR-24 (hari kerja).
 */
final class JamKerjaService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function untuk(string $jenisPegawai, int $hari): ?JamKerja
    {
        return JamKerja::untuk($jenisPegawai, $hari);
    }

    /** Menyimpan aturan satu hari; jam diisi hanya bila hari itu hari kerja. */
    public function simpan(string $jenisPegawai, int $hari, array $data, ?User $oleh = null): JamKerja
    {
        if (! array_key_exists($jenisPegawai, JamKerja::DAFTAR_JENIS)) {
            throw AturanBisnisException::tolak('Jenis pegawai tidak dikenali.', 'jenis_pegawai');
        }

        $isHariKerja = (bool) ($data['is_hari_kerja'] ?? false);

        if ($isHariKerja) {
            foreach (['jam_masuk', 'jam_pulang'] as $wajib) {
                if (empty($data[$wajib])) {
                    throw AturanBisnisException::tolak(
                        $wajib === 'jam_masuk' ? 'Jam masuk wajib diisi pada hari kerja.' : 'Jam pulang wajib diisi pada hari kerja.',
                        $wajib,
                    );
                }
            }

            // Jam pulang harus setelah jam masuk, jika tidak BR-16 tidak bermakna.
            if ($data['jam_masuk'] >= $data['jam_pulang']) {
                throw AturanBisnisException::tolak('Jam pulang harus setelah jam masuk.', 'jam_pulang');
            }
        }

        $aturan = JamKerja::updateOrCreate(
            ['jenis_pegawai' => $jenisPegawai, 'hari' => $hari],
            [
                'is_hari_kerja' => $isHariKerja,
                'buka_presensi' => $isHariKerja ? ($data['buka_presensi'] ?? null) : null,
                'jam_masuk' => $isHariKerja ? $data['jam_masuk'] : null,
                'jam_pulang' => $isHariKerja ? $data['jam_pulang'] : null,
            ],
        );

        $this->audit->catat(AuditLogService::AKSI_BUAT, $oleh, $aturan, null, [
            'jenis_pegawai' => $jenisPegawai, 'hari' => $hari, 'is_hari_kerja' => $isHariKerja,
        ]);

        return $aturan;
    }

    /**
     * Menyimpan satu pekan sekaligus untuk sebuah jenis pegawai.
     * Lebih praktis daripada tujuh permintaan terpisah karena layar pengaturan
     * menampilkan seluruh hari dalam satu tabel.
     *
     * @param  array<int, array<string, mixed>>  $perHari  kunci = nomor hari (1–7)
     */
    public function simpanMassal(string $jenisPegawai, array $perHari, ?User $oleh = null): array
    {
        return DB::transaction(function () use ($jenisPegawai, $perHari, $oleh): array {
            $tersimpan = [];

            foreach (JamKerja::DAFTAR_HARI as $hari => $nama) {
                $data = $perHari[$hari] ?? $perHari[(string) $hari] ?? null;

                if ($data === null) {
                    continue;
                }

                $tersimpan[$hari] = $this->simpan($jenisPegawai, $hari, $data, $oleh);
            }

            return $tersimpan;
        });
    }

    /**
     * BR-24 — hari kerja: aturan jam kerja menandai hari itu sebagai hari kerja
     * DAN tanggal tersebut bukan hari libur.
     */
    public function hariKerja(string $jenisPegawai, CarbonInterface $tanggal): bool
    {
        $aturan = $this->untuk($jenisPegawai, $tanggal->dayOfWeekIso);

        if ($aturan === null || ! $aturan->is_hari_kerja) {
            return false;
        }

        return ! $this->hariLibur($tanggal);
    }

    public function hariLibur(CarbonInterface $tanggal): bool
    {
        return HariLibur::query()
            ->where('tanggal_mulai', '<=', $tanggal->toDateString())
            ->where('tanggal_selesai', '>=', $tanggal->toDateString())
            ->exists();
    }

    /**
     * Ringkasan sepekan untuk sebuah jenis pegawai (untuk layar pengaturan).
     *
     * Memakai `get()` dan bukan `[$hari]`: akses offset pada Collection MELEMPAR
     * galat bila kuncinya tidak ada (Collection::offsetGet), sehingga hari yang
     * belum diatur akan menghasilkan 500 alih-alih baris kosong.
     */
    public function sepekan(string $jenisPegawai): array
    {
        $aturan = JamKerja::query()->where('jenis_pegawai', $jenisPegawai)->get()->keyBy('hari');

        return collect(JamKerja::DAFTAR_HARI)->map(function (string $nama, int $hari) use ($aturan): array {
            $baris = $aturan->get($hari);

            return [
                'hari' => $hari,
                'nama_hari' => $nama,
                'is_hari_kerja' => (bool) ($baris?->is_hari_kerja ?? false),
                'buka_presensi' => $baris?->buka_presensi,
                'jam_masuk' => $baris?->jam_masuk,
                'jam_pulang' => $baris?->jam_pulang,
            ];
        })->values()->all();
    }
}
