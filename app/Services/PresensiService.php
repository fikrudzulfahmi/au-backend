<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\HariLibur;
use App\Models\JamKerja;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PengajuanLuarRadius;
use App\Models\PresensiPegawai;
use App\Models\Semester;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * FR-PRS-01..13 — presensi masuk dan pulang.
 *
 * Aturan yang ditegakkan di sini:
 * - BR-10 satu presensi masuk & satu pulang per hari (dijaga juga oleh UQ database).
 * - BR-11/BR-12 radius dicek Haversine terhadap seluruh lokasi pegawai, atau default.
 * - BR-13 waktu selalu dari WaktuService (waktu server), tidak pernah dari perangkat.
 * - BR-15/BR-16 terlambat & pulang cepat tanpa toleransi, menit dibulatkan ke atas.
 * - BR-17/BR-18 jalur luar radius dan penentuan hadir dari waktu kirim.
 * - BR-25 hari izin/sakit/cuti disetujui tidak mewajibkan presensi; dinas tetap.
 * - BR-29 foto wajib dari kamera, dikompres dan diberi watermark.
 */
final class PresensiService
{
    public function __construct(
        private readonly BerkasService $berkas,
        private readonly WaktuService $waktu,
        private readonly PengaturanService $pengaturan,
        private readonly AuditLogService $audit,
        private readonly JamKerjaService $jamKerja,
        private readonly LokasiPresensiService $lokasi,
    ) {}

    /**
     * Presensi masuk.
     *
     * @param  array{foto?: ?UploadedFile, lat?: mixed, lng?: mixed, akurasi_m?: mixed, alasan?: ?string}  $data
     */
    public function masuk(Pegawai $pegawai, array $data, ?User $oleh = null): PresensiPegawai
    {
        $sekarang = $this->waktu->sekarang();
        $tanggal = $sekarang->toDateString();

        $this->pastikanHariKerja($pegawai, $sekarang);
        $this->pastikanIzinTidakMembebaskan($pegawai, $tanggal);

        $presensi = PresensiPegawai::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->whereDate('tanggal', $tanggal)
            ->first();

        // BR-10 — presensi masuk satu kali per hari, kecuali yang sebelumnya ditolak
        // (FR-PRS-07: setelah ditolak pegawai boleh presensi ulang pada hari yang sama).
        if ($presensi !== null && $presensi->sudahMasuk() && $presensi->masuk_validasi !== PresensiPegawai::DITOLAK) {
            throw AturanBisnisException::tolak('Anda sudah melakukan presensi masuk hari ini (BR-10).', 'foto', 'BR-10');
        }

        $this->pastikanPresensiDibuka($pegawai, $sekarang);

        $masukan = $this->siapkanMasukan($data, true);

        // FR-PRS-06 — akurasi GPS buruk BUKAN "di luar radius": presensi tidak dikirim.
        $this->pastikanAkurasi($masukan['akurasi_m']);

        $penilaian = $this->nilaiRadius($pegawai, $masukan['lat'], $masukan['lng'], $tanggal, $masukan['alasan']);

        $hasil = $presensi ?? new PresensiPegawai(['pegawai_id' => $pegawai->getKey(), 'tanggal' => $tanggal]);
        $lama = $presensi?->only(['masuk_waktu', 'masuk_status', 'masuk_validasi']);

        return DB::transaction(function () use ($hasil, $lama, $masukan, $penilaian, $pegawai, $sekarang, $oleh, $data): PresensiPegawai {
            $foto = $this->simpanFoto($data['foto'] ?? null, $pegawai, $sekarang, $masukan['lat'], $masukan['lng']);

            // Berkas lama tidak diperlukan lagi bila presensi ditolak sebelumnya.
            if ($lama !== null && $hasil->masuk_foto_path !== null) {
                $this->berkas->hapus($hasil->masuk_foto_path);
            }

            $status = $this->statusMasuk($pegawai, $sekarang);

            $hasil->fill([
                'semester_id' => $this->semesterAktifId(),
                'masuk_waktu' => $sekarang,
                'masuk_lat' => $masukan['lat'],
                'masuk_lng' => $masukan['lng'],
                'masuk_akurasi_m' => $masukan['akurasi_m'],
                'masuk_lokasi_id' => $penilaian['lokasi_id'],
                'masuk_jarak_m' => $penilaian['jarak_m'] === null ? null : (int) round($penilaian['jarak_m']),
                'masuk_foto_path' => $foto,
                'masuk_status' => $status['status'],
                'masuk_menit_terlambat' => $status['menit'],
                'masuk_validasi' => $penilaian['validasi'],
                'masuk_alasan_luar_radius' => $penilaian['validasi'] === PresensiPegawai::VALID ? null : $masukan['alasan'],
                'masuk_pengajuan_luar_radius_id' => $penilaian['pengajuan_id'],
            ]);

            $hasil->save();

            $this->audit->catat(AuditLogService::AKSI_PRESENSI_MASUK, $oleh, $hasil, $lama, [
                'status' => $status['status'], 'validasi' => $penilaian['validasi'],
            ]);

            return $hasil->refresh();
        });
    }

    /**
     * Presensi pulang. BR-10: hanya setelah masuk, satu kali per hari.
     *
     * @param  array{foto?: ?UploadedFile, lat?: mixed, lng?: mixed, akurasi_m?: mixed, alasan?: ?string}  $data
     */
    public function pulang(Pegawai $pegawai, array $data, ?User $oleh = null): PresensiPegawai
    {
        $sekarang = $this->waktu->sekarang();
        $tanggal = $sekarang->toDateString();

        $presensi = PresensiPegawai::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->whereDate('tanggal', $tanggal)
            ->first();

        if ($presensi === null || ! $presensi->sudahMasuk()) {
            throw AturanBisnisException::tolak('Lakukan presensi masuk terlebih dahulu (BR-10).', 'foto', 'BR-10');
        }

        if ($presensi->sudahPulang() && $presensi->pulang_validasi !== PresensiPegawai::DITOLAK) {
            throw AturanBisnisException::tolak('Anda sudah melakukan presensi pulang hari ini (BR-10).', 'foto', 'BR-10');
        }

        $masukan = $this->siapkanMasukan($data, false);
        $this->pastikanAkurasi($masukan['akurasi_m']);

        $penilaian = $this->nilaiRadius($pegawai, $masukan['lat'], $masukan['lng'], $tanggal, $masukan['alasan']);
        $status = $this->statusPulang($pegawai, $sekarang);
        $lama = $presensi->only(['pulang_waktu', 'pulang_status', 'pulang_validasi']);

        return DB::transaction(function () use ($presensi, $lama, $masukan, $penilaian, $status, $pegawai, $sekarang, $oleh, $data): PresensiPegawai {
            $foto = $this->simpanFoto($data['foto'] ?? null, $pegawai, $sekarang, $masukan['lat'], $masukan['lng']);

            if ($lama !== null && $presensi->pulang_foto_path !== null) {
                $this->berkas->hapus($presensi->pulang_foto_path);
            }

            $presensi->fill([
                'pulang_waktu' => $sekarang,
                'pulang_lat' => $masukan['lat'],
                'pulang_lng' => $masukan['lng'],
                'pulang_akurasi_m' => $masukan['akurasi_m'],
                'pulang_lokasi_id' => $penilaian['lokasi_id'],
                'pulang_jarak_m' => $penilaian['jarak_m'] === null ? null : (int) round($penilaian['jarak_m']),
                'pulang_foto_path' => $foto,
                'pulang_status' => $status['status'],
                'pulang_menit_cepat' => $status['menit'],
                'pulang_validasi' => $penilaian['validasi'],
                'pulang_alasan_luar_radius' => $penilaian['validasi'] === PresensiPegawai::VALID ? null : $masukan['alasan'],
                'pulang_pengajuan_luar_radius_id' => $penilaian['pengajuan_id'],
            ]);

            $presensi->save();

            $this->audit->catat(AuditLogService::AKSI_PRESENSI_PULANG, $oleh, $presensi, $lama, [
                'status' => $status['status'], 'validasi' => $penilaian['validasi'],
            ]);

            return $presensi->refresh();
        });
    }

    /**
     * FR-PRS-11 — admin menyetujui atau menolak presensi luar radius.
     * BR-18: status hadir/terlambat TIDAK dihitung ulang dari waktu keputusan.
     */
    public function putuskan(
        PresensiPegawai $presensi,
        string $keputusan,
        ?string $catatan,
        User $oleh,
        string $sisi = 'masuk',
    ): PresensiPegawai {
        if (! in_array($keputusan, [PresensiPegawai::DISETUJUI, PresensiPegawai::DITOLAK], true)) {
            throw AturanBisnisException::tolak('Keputusan tidak dikenali.', 'keputusan');
        }

        if ($keputusan === PresensiPegawai::DITOLAK && ($catatan === null || trim($catatan) === '')) {
            throw AturanBisnisException::tolak('Catatan wajib diisi saat menolak presensi.', 'catatan_penyetuju');
        }

        $kolomValidasi = $sisi === 'pulang' ? 'pulang_validasi' : 'masuk_validasi';

        if ($presensi->{$kolomValidasi} !== PresensiPegawai::MENUNGGU) {
            throw AturanBisnisException::tolak('Presensi ini tidak sedang menunggu keputusan.', 'keputusan');
        }

        $lama = $presensi->only(['masuk_validasi', 'pulang_validasi', 'catatan_penyetuju']);

        $presensi->fill([
            $kolomValidasi => $keputusan,
            'diputuskan_oleh' => $oleh->getKey(),
            'diputuskan_pada' => $this->waktu->sekarang(),
            'catatan_penyetuju' => $catatan,
        ])->save();

        $this->audit->catat(AuditLogService::AKSI_PUTUSKAN_PRESENSI, $oleh, $presensi, $lama, [
            'sisi' => $sisi, 'keputusan' => $keputusan,
        ]);

        return $presensi->refresh();
    }

    /**
     * FR-PRS-13 — koreksi manual oleh admin (lupa pulang, gangguan sistem).
     * Wajib beralasan dan ditandai `dikoreksi_admin`.
     */
    public function koreksi(PresensiPegawai $presensi, array $data, User $oleh, string $alasan): PresensiPegawai
    {
        if (trim($alasan) === '') {
            throw AturanBisnisException::tolak('Alasan koreksi wajib diisi (FR-PRS-13).', 'alasan');
        }

        $lama = $presensi->only([
            'masuk_waktu', 'masuk_status', 'masuk_validasi',
            'pulang_waktu', 'pulang_status', 'pulang_validasi',
        ]);

        return DB::transaction(function () use ($presensi, $data, $alasan, $lama, $oleh): PresensiPegawai {
            $perubahan = ['dikoreksi_admin' => true];

            // getFillable() SUDAH berupa daftar nama kolom; membungkusnya dengan
            // array_keys() menghasilkan indeks 0,1,2… sehingga tidak ada kolom yang
            // pernah lolos dan koreksi menjadi tidak berefek.
            $kolomDiizinkan = $presensi->getFillable();

            foreach ($data as $kolom => $nilai) {
                if (in_array($kolom, $kolomDiizinkan, true) && $nilai !== null) {
                    $perubahan[$kolom] = $nilai;
                }
            }

            $presensi->fill($perubahan)->save();

            // Koreksi wajib terekam lengkap dengan alasannya (FR-PRS-13).
            $this->audit->catat(AuditLogService::AKSI_KOREKSI_PRESENSI, $oleh, $presensi, $lama, [
                'perubahan' => $perubahan,
                'alasan' => $alasan,
            ]);

            return $presensi->refresh();
        });
    }

    /**
     * Status hari ini untuk beranda pegawai: apakah boleh presensi, dan bila tidak, mengapa.
     * Dipakai juga untuk menyembunyikan tombol pada hari izin/sakit/cuti (BR-25).
     */
    public function statusHariIni(Pegawai $pegawai, ?CarbonImmutable $saat = null): array
    {
        $sekarang = $saat ?? $this->waktu->sekarang();
        $tanggal = $sekarang->toDateString();

        $aturan = $this->jamKerja->untuk($pegawai->jenis_pegawai, $sekarang->dayOfWeekIso);
        $libur = HariLibur::query()
            ->where('tanggal_mulai', '<=', $tanggal)
            ->where('tanggal_selesai', '>=', $tanggal)
            ->first();

        $presensi = PresensiPegawai::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->whereDate('tanggal', $tanggal)
            ->first();

        $izin = $this->izinMembebaskan($pegawai, $tanggal);

        $bolehMasuk = true;
        $alasanMasuk = null;

        if ($izin !== null) {
            $bolehMasuk = false;
            $alasanMasuk = 'Hari ini tercatat '.$izin->labelJenis().' yang disetujui — presensi tidak diperlukan (BR-25).';
        } elseif ($aturan === null || ! $aturan->is_hari_kerja) {
            $bolehMasuk = false;
            $alasanMasuk = 'Hari ini bukan hari kerja menurut jam kerja.';
        } elseif ($libur !== null) {
            $bolehMasuk = false;
            $alasanMasuk = 'Hari ini libur: '.($libur->keterangan ?? 'hari libur').'.';
        } elseif ($presensi !== null && $presensi->sudahMasuk() && $presensi->masuk_validasi !== PresensiPegawai::DITOLAK) {
            $bolehMasuk = false;
            $alasanMasuk = 'Anda sudah presensi masuk hari ini.';
        } elseif ($aturan->buka_presensi !== null && $sekarang->format('H:i:s') < $aturan->buka_presensi) {
            $bolehMasuk = false;
            $alasanMasuk = 'Presensi dibuka pukul '.substr($aturan->buka_presensi, 0, 5).'.';
        }

        $bolehPulang = $presensi !== null && $presensi->sudahMasuk() && ! $presensi->sudahPulang();

        return [
            'tanggal' => $tanggal,
            'hari' => $sekarang->dayOfWeekIso,
            'nama_hari' => JamKerja::DAFTAR_HARI[$sekarang->dayOfWeekIso] ?? null,
            'is_hari_kerja' => $aturan?->is_hari_kerja ?? false,
            'hari_libur' => $libur?->keterangan,
            'buka_presensi' => $aturan?->buka_presensi,
            'jam_masuk' => $aturan?->jam_masuk,
            'jam_pulang' => $aturan?->jam_pulang,
            'presensi' => $presensi,
            'pengajuan' => $izin,
            'boleh_masuk' => $bolehMasuk,
            'alasan_tidak_boleh_masuk' => $alasanMasuk,
            'boleh_pulang' => $bolehPulang,
            'lokasi' => $this->lokasi->lokasiEfektif($pegawai),
        ];
    }

    /** FR-PRS-12 — riwayat presensi milik sendiri. */
    public function riwayat(Pegawai $pegawai, array $filter = [], int $perHalaman = 25): LengthAwarePaginator
    {
        return PresensiPegawai::query()
            ->with(['masukLokasi', 'pulangLokasi'])
            ->where('pegawai_id', $pegawai->getKey())
            ->when($filter['dari'] ?? null, fn ($q, $dari) => $q->whereDate('tanggal', '>=', $dari))
            ->when($filter['sampai'] ?? null, fn ($q, $sampai) => $q->whereDate('tanggal', '<=', $sampai))
            ->orderByDesc('tanggal')
            ->paginate($perHalaman);
    }

    // ------------------------------------------------------------------
    // Bagian dalam
    // ------------------------------------------------------------------

    /** @return array{lat: float, lng: float, akurasi_m: ?int, alasan: ?string} */
    private function siapkanMasukan(array $data, bool $masuk): array
    {
        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;

        // KP-3.1 — presensi tanpa GPS ditolak.
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            throw AturanBisnisException::tolak(
                'Koordinat GPS tidak terbaca. Aktifkan izin lokasi lalu coba lagi (KP-3.1).',
                'lat',
                'GPS-WAJIB',
            );
        }

        // KP-3.1 — presensi tanpa foto dari kamera ditolak.
        if (($data['foto'] ?? null) === null) {
            throw AturanBisnisException::tolak(
                'Foto presensi wajib diambil dari kamera (KP-3.1, BR-29).',
                'foto',
                'FOTO-WAJIB',
            );
        }

        $alasan = $data['alasan'] ?? null;
        $alasan = is_string($alasan) ? trim($alasan) : null;

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'akurasi_m' => isset($data['akurasi_m']) && $data['akurasi_m'] !== '' ? (int) $data['akurasi_m'] : null,
            'alasan' => $alasan === '' ? null : $alasan,
        ];
    }

    /** FR-PRS-06 — akurasi GPS lebih buruk dari batas tidak dianggap luar radius. */
    private function pastikanAkurasi(?int $akurasi): void
    {
        if ($akurasi === null) {
            return;
        }

        $batas = (int) $this->pengaturan->ambil('gps_max_akurasi_m');

        if ($akurasi > $batas) {
            throw AturanBisnisException::tolak(
                "Akurasi GPS saat ini ±{$akurasi} m, melebihi batas {$batas} m. Cari tempat terbuka lalu coba lagi (FR-PRS-06).",
                'akurasi_m',
                'AKURASI-GPS',
            );
        }
    }

    private function pastikanPresensiDibuka(Pegawai $pegawai, CarbonImmutable $sekarang): void
    {
        $aturan = $this->jamKerja->untuk($pegawai->jenis_pegawai, $sekarang->dayOfWeekIso);

        if ($aturan?->buka_presensi === null) {
            return;
        }

        if ($sekarang->format('H:i:s') < $aturan->buka_presensi) {
            throw AturanBisnisException::tolak(
                'Presensi masuk dibuka pukul '.substr($aturan->buka_presensi, 0, 5).'.',
                'foto',
                'BELUM-DIBUKA',
            );
        }
    }

    private function pastikanHariKerja(Pegawai $pegawai, CarbonImmutable $sekarang): void
    {
        $aturan = $this->jamKerja->untuk($pegawai->jenis_pegawai, $sekarang->dayOfWeekIso);

        if ($aturan === null) {
            throw AturanBisnisException::tolak(
                'Jam kerja untuk jenis pegawai Anda belum diatur. Hubungi admin.',
                'foto',
                'JAM-KERJA-KOSONG',
            );
        }

        if (! $aturan->is_hari_kerja) {
            throw AturanBisnisException::tolak('Hari ini bukan hari kerja.', 'foto', 'BUKAN-HARI-KERJA');
        }
    }

    /** BR-25 — hari izin/sakit/cuti disetujui tidak mewajibkan presensi. */
    private function pastikanIzinTidakMembebaskan(Pegawai $pegawai, string $tanggal): void
    {
        $izin = $this->izinMembebaskan($pegawai, $tanggal);

        if ($izin !== null) {
            throw AturanBisnisException::tolak(
                'Hari ini Anda tercatat '.$izin->labelJenis().' yang disetujui, sehingga presensi tidak diperlukan (BR-25).',
                'foto',
                'BR-25',
            );
        }
    }

    private function izinMembebaskan(Pegawai $pegawai, string $tanggal): ?PengajuanIzin
    {
        return PengajuanIzin::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->whereIn('jenis', PengajuanIzin::JENIS_MEMBEBASKAN)
            ->where('tanggal_mulai', '<=', $tanggal)
            ->where('tanggal_selesai', '>=', $tanggal)
            ->first();
    }

    /**
     * BR-11/BR-17/BR-18 — menentukan validasi presensi berdasarkan posisi.
     *
     * @return array{validasi: string, lokasi_id: ?int, jarak_m: ?float, pengajuan_id: ?int}
     */
    private function nilaiRadius(Pegawai $pegawai, float $lat, float $lng, string $tanggal, ?string $alasan): array
    {
        $lokasiEfektif = $this->lokasi->lokasiEfektif($pegawai);
        $terdekat = $this->lokasi->terdekat($lokasiEfektif, $lat, $lng);

        // BR-11 — sah bila berada di dalam radius salah satu lokasi pegawai.
        if ($terdekat['lokasi'] !== null && $terdekat['jarak_m'] !== null
            && $terdekat['jarak_m'] <= (float) $terdekat['lokasi']->radius_m) {
            return [
                'validasi' => PresensiPegawai::VALID,
                'lokasi_id' => $terdekat['lokasi']->getKey(),
                'jarak_m' => $terdekat['jarak_m'],
                'pengajuan_id' => null,
            ];
        }

        // BR-17 Jalur A — sudah ada pengajuan luar radius yang disetujui untuk tanggal ini.
        $pengajuan = PengajuanLuarRadius::disetujuiUntuk($pegawai->getKey(), $tanggal);

        if ($pengajuan !== null) {
            return [
                'validasi' => PresensiPegawai::DISETUJUI,
                'lokasi_id' => null,
                'jarak_m' => $terdekat['jarak_m'],
                'pengajuan_id' => $pengajuan->getKey(),
            ];
        }

        // BR-17 Jalur B — diterima tetapi menunggu keputusan admin, alasan wajib.
        if ($alasan === null) {
            throw AturanBisnisException::tolak(
                'Anda berada di luar radius semua lokasi presensi. Isi alasan agar presensi dapat ditinjau admin (BR-17).',
                'alasan',
                'ALASAN-WAJIB',
            );
        }

        return [
            'validasi' => PresensiPegawai::MENUNGGU,
            'lokasi_id' => null,
            'jarak_m' => $terdekat['jarak_m'],
            'pengajuan_id' => null,
        ];
    }

    /**
     * BR-15 — terlambat bila waktu server > jam_masuk, TANPA toleransi.
     * Menit terlambat dibulatkan ke atas.
     *
     * @return array{status: string, menit: int}
     */
    private function statusMasuk(Pegawai $pegawai, CarbonImmutable $sekarang): array
    {
        $aturan = $this->jamKerja->untuk($pegawai->jenis_pegawai, $sekarang->dayOfWeekIso);
        $batas = $aturan?->jam_masuk;

        if ($batas === null) {
            return ['status' => PresensiPegawai::STATUS_HADIR, 'menit' => 0];
        }

        $batasWaktu = $sekarang->setTimeFromTimeString($batas);

        if ($sekarang->lessThanOrEqualTo($batasWaktu)) {
            return ['status' => PresensiPegawai::STATUS_HADIR, 'menit' => 0];
        }

        return ['status' => PresensiPegawai::STATUS_TERLAMBAT, 'menit' => $this->menitBulatKeAtas($batasWaktu, $sekarang)];
    }

    /**
     * BR-16 — pulang cepat bila waktu server < jam_pulang.
     *
     * @return array{status: string, menit: int}
     */
    private function statusPulang(Pegawai $pegawai, CarbonImmutable $sekarang): array
    {
        $aturan = $this->jamKerja->untuk($pegawai->jenis_pegawai, $sekarang->dayOfWeekIso);
        $batas = $aturan?->jam_pulang;

        if ($batas === null) {
            return ['status' => PresensiPegawai::PULANG_NORMAL, 'menit' => 0];
        }

        $batasWaktu = $sekarang->setTimeFromTimeString($batas);

        if ($sekarang->greaterThanOrEqualTo($batasWaktu)) {
            return ['status' => PresensiPegawai::PULANG_NORMAL, 'menit' => 0];
        }

        return ['status' => PresensiPegawai::PULANG_CEPAT, 'menit' => $this->menitBulatKeAtas($sekarang, $batasWaktu)];
    }

    private function menitBulatKeAtas(CarbonImmutable $dari, CarbonImmutable $ke): int
    {
        // abs() menjaga hasil tetap positif; Carbon 3 mengembalikan selisih bertanda.
        return (int) ceil(abs($dari->diffInSeconds($ke)) / 60);
    }

    /** BR-29 — foto disimpan terkompresi dengan watermark nama, waktu server, dan koordinat. */
    private function simpanFoto(
        ?UploadedFile $foto,
        Pegawai $pegawai,
        CarbonImmutable $sekarang,
        float $lat,
        float $lng,
    ): string {
        if ($foto === null) {
            throw AturanBisnisException::tolak('Foto presensi wajib diambil dari kamera (BR-29).', 'foto', 'FOTO-WAJIB');
        }

        $teks = $pegawai->nama."\n"
            .$sekarang->translatedFormat('d M Y H:i:s')."\n"
            .number_format($lat, 7, '.', '').', '.number_format($lng, 7, '.', '');

        return $this->berkas->simpanFotoPresensi($foto, 'presensi/'.$sekarang->format('Y/m'), $teks);
    }

    private function semesterAktifId(): ?int
    {
        return Semester::query()->where('is_active', true)->value('id');
    }
}
