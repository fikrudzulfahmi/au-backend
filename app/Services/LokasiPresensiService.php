<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\LokasiPresensi;
use App\Models\Pegawai;
use App\Models\PegawaiLokasi;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FR-LOK-01..03, FR-LOK-06 — master lokasi presensi dan penetapannya ke pegawai.
 * Jarak memakai Haversine (BR-11); radius disimpan di database, tidak di-hardcode.
 */
final class LokasiPresensiService
{
    /** Radius Bumi rata-rata dalam meter. */
    private const RADIUS_BUMI_M = 6371000.0;

    public function __construct(private readonly AuditLogService $audit) {}

    public function simpan(array $data, ?LokasiPresensi $lokasi = null, ?User $oleh = null): LokasiPresensi
    {
        $radius = (int) $data['radius_m'];

        if ($radius < 10 || $radius > 10000) {
            throw AturanBisnisException::tolak('Radius harus antara 10 sampai 10.000 meter.', 'radius_m');
        }

        return DB::transaction(function () use ($data, $lokasi, $oleh): LokasiPresensi {
            $baru = $lokasi === null;

            $target = $lokasi ?? new LokasiPresensi;

            $target->fill([
                'nama' => $data['nama'],
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'radius_m' => (int) $data['radius_m'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            // BR-12 — jangan biarkan dua lokasi default. Lokasi default lama dilepas
            // lebih dahulu karena MySQL memeriksa indeks unik secara langsung.
            if ((bool) ($data['is_default'] ?? false)) {
                $this->lepasDefaultLain($target);
                $target->is_default = true;
            } elseif (! $baru && $target->exists) {
                // Mengubah lokasi default menjadi bukan default hanya bila masih ada
                // lokasi aktif lain, agar tidak ada kondisi tanpa default.
                $target->is_default = (bool) $target->getOriginal('is_default');
            } else {
                $target->is_default = false;
            }

            $target->save();

            $this->audit->catat($baru ? AuditLogService::AKSI_BUAT : AuditLogService::AKSI_UBAH, $oleh, $target);

            return $target->refresh();
        });
    }

    /** FR-LOK-02 / BR-12 — menjadikan sebuah lokasi sebagai satu-satunya default. */
    public function jadikanDefault(LokasiPresensi $lokasi, ?User $oleh = null): LokasiPresensi
    {
        return DB::transaction(function () use ($lokasi, $oleh): LokasiPresensi {
            $this->lepasDefaultLain($lokasi);

            $lokasi->is_default = true;
            $lokasi->is_active = true;

            try {
                $lokasi->save();
            } catch (QueryException $e) {
                // Jaring terakhir: indeks unik lokasi_presensi_default_uq.
                throw AturanBisnisException::konflik(
                    'Sudah ada lokasi default lain. Lepas default lokasi tersebut terlebih dahulu (BR-12).',
                    'BR-12',
                );
            }

            $this->audit->catat(AuditLogService::AKSI_UBAH, $oleh, $lokasi, null, ['is_default' => true]);

            return $lokasi;
        });
    }

    public function hapus(LokasiPresensi $lokasi, ?User $oleh = null): void
    {
        DB::transaction(function () use ($lokasi, $oleh): void {
            $lokasi->delete();
            $this->audit->catat(AuditLogService::AKSI_HAPUS, $oleh, $lokasi);
        });
    }

    /**
     * FR-LOK-03 — penetapan lokasi per pegawai, mendukung penetapan massal.
     * Pilihan yang dikirim MENGGANTIKAN penetapan sebelumnya untuk pegawai tersebut,
     * sehingga menghapus semua centang mengembalikan pegawai ke lokasi default (BR-12).
     *
     * @param  array<int, int>  $pegawaiIds
     * @param  array<int, int>  $lokasiIds
     */
    public function tetapkanPegawai(array $pegawaiIds, array $lokasiIds, ?User $oleh = null): array
    {
        if ($pegawaiIds === []) {
            throw AturanBisnisException::tolak('Pilih setidaknya satu pegawai.', 'pegawai_ids');
        }

        return DB::transaction(function () use ($pegawaiIds, $lokasiIds, $oleh): array {
            $jumlah = 0;

            foreach ($pegawaiIds as $pegawaiId) {
                PegawaiLokasi::where('pegawai_id', $pegawaiId)->delete();

                foreach (array_unique($lokasiIds) as $lokasiId) {
                    PegawaiLokasi::create(['pegawai_id' => $pegawaiId, 'lokasi_id' => $lokasiId]);
                    $jumlah++;
                }
            }

            $this->audit->catat(AuditLogService::AKSI_TETAPKAN_LOKASI, $oleh, null, null, [
                'pegawai' => count($pegawaiIds),
                'lokasi' => count($lokasiIds),
            ]);

            return ['pegawai' => count($pegawaiIds), 'penetapan' => $jumlah];
        });
    }

    /**
     * BR-11/B-12 — lokasi yang sah untuk seorang pegawai.
     * Pegawai tanpa penetapan khusus memakai lokasi default.
     *
     * @return Collection<int, LokasiPresensi>
     */
    public function lokasiEfektif(Pegawai $pegawai): Collection
    {
        $khusus = $pegawai->lokasi()->aktif()->get();

        if ($khusus->isNotEmpty()) {
            return $khusus;
        }

        $default = LokasiPresensi::defaultAktif();

        return $default === null ? collect() : collect([$default]);
    }

    /**
     * BR-11 — lokasi terdekat beserta jaraknya (meter, Haversine).
     *
     * @param  Collection<int, LokasiPresensi>  $lokasi
     * @return array{lokasi: ?LokasiPresensi, jarak_m: ?float}
     */
    public function terdekat(Collection $lokasi, float $lat, float $lng): array
    {
        $terpilih = null;
        $jarakTerpilih = null;

        foreach ($lokasi as $kandidat) {
            $jarak = self::haversine($lat, $lng, (float) $kandidat->latitude, (float) $kandidat->longitude);

            if ($jarakTerpilih === null || $jarak < $jarakTerpilih) {
                $terpilih = $kandidat;
                $jarakTerpilih = $jarak;
            }
        }

        return ['lokasi' => $terpilih, 'jarak_m' => $jarakTerpilih];
    }

    /** Jarak lingkaran besar antara dua koordinat, dalam meter. */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::RADIUS_BUMI_M * 2 * asin(min(1.0, sqrt($a)));
    }

    /** Melepas penanda default dari seluruh lokasi selain yang diberikan. */
    private function lepasDefaultLain(?LokasiPresensi $kecuali): void
    {
        $kueri = LokasiPresensi::query()->where('is_default', true);

        if ($kecuali !== null && $kecuali->exists) {
            $kueri->whereKeyNot($kecuali->getKey());
        }

        $kueri->update(['is_default' => false, 'penanda_default' => null]);
    }
}
