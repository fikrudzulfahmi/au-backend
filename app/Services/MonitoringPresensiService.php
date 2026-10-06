<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JamKerja;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PresensiPegawai;
use Carbon\CarbonImmutable;

/**
 * FR-PRS-10 — monitoring harian presensi.
 *
 * Status setiap pegawai dihitung dari data nyata (presensi, izin, jam kerja),
 * bukan disimpan, agar tidak pernah basi. Kategori mengikuti daftar pada FR-PRS-10.
 */
final class MonitoringPresensiService
{
    public const BELUM_PRESENSI = 'belum_presensi';

    public const HADIR = 'hadir';

    public const TERLAMBAT = 'terlambat';

    public const BERHALANGAN = 'berhalangan';

    public const MENUNGGU = 'menunggu';

    public const LUAR_RADIUS = 'luar_radius';

    public const TIDAK_PRESENSI_PULANG = 'tidak_presensi_pulang';

    /** @var array<string, string> */
    public const DAFTAR_STATUS = [
        self::BELUM_PRESENSI => 'Belum Presensi',
        self::HADIR => 'Hadir',
        self::TERLAMBAT => 'Terlambat',
        self::BERHALANGAN => 'Izin/Sakit/Dinas/Cuti',
        self::MENUNGGU => 'Menunggu Persetujuan',
        self::LUAR_RADIUS => 'Luar Radius',
        self::TIDAK_PRESENSI_PULANG => 'Tidak Presensi Pulang',
    ];

    public function __construct(private readonly JamKerjaService $jamKerja) {}

    /**
     * Daftar seluruh pegawai aktif beserta status presensinya pada tanggal tertentu.
     *
     * @return array{tanggal: string, nama_hari: string, hari_libur: ?string, ringkasan: array<string,int>, baris: array<int,array<string,mixed>>}
     */
    public function harian(string $tanggal, ?string $jenisPegawai = null, ?string $status = null): array
    {
        $saat = CarbonImmutable::parse($tanggal)->startOfDay();

        $pegawai = Pegawai::query()
            ->where('is_active', true)
            ->when($jenisPegawai, fn ($q, $jenis) => $q->where('jenis_pegawai', $jenis))
            ->orderBy('nama')
            ->get();

        $presensi = PresensiPegawai::query()
            ->with(['masukLokasi', 'pulangLokasi'])
            ->whereDate('tanggal', $saat->toDateString())
            ->get()
            ->keyBy('pegawai_id');

        $izin = PengajuanIzin::query()
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->where('tanggal_mulai', '<=', $saat->toDateString())
            ->where('tanggal_selesai', '>=', $saat->toDateString())
            ->get()
            ->keyBy('pegawai_id');

        $libur = $this->jamKerja->hariLibur($saat);
        $baris = [];
        $ringkasan = array_fill_keys(array_keys(self::DAFTAR_STATUS), 0);

        foreach ($pegawai as $orang) {
            $p = $presensi->get($orang->getKey());
            $i = $izin->get($orang->getKey());
            $aturan = $this->jamKerja->untuk($orang->jenis_pegawai, $saat->dayOfWeekIso);

            $kategori = $this->kategori($p, $i, $aturan?->is_hari_kerja ?? false, $libur);
            $ringkasan[$kategori]++;

            $baris[] = [
                'pegawai_id' => $orang->getKey(),
                'nip' => $orang->nip,
                'nama' => $orang->nama,
                'jenis_pegawai' => $orang->jenis_pegawai,
                'jabatan' => $orang->jabatan,
                'status' => $kategori,
                'label_status' => self::DAFTAR_STATUS[$kategori],
                'hari_kerja' => (bool) ($aturan?->is_hari_kerja ?? false) && ! $libur,
                'jam_masuk' => $aturan?->jam_masuk,
                'jam_pulang' => $aturan?->jam_pulang,
                'presensi_id' => $p?->getKey(),
                'masuk_jam' => $p?->masuk_waktu?->format('H:i'),
                'pulang_jam' => $p?->pulang_waktu?->format('H:i'),
                'masuk_status' => $p?->masuk_status,
                'pulang_status' => $p?->pulang_status,
                'masuk_validasi' => $p?->masuk_validasi,
                'pulang_validasi' => $p?->pulang_validasi,
                'menit_terlambat' => (int) ($p?->masuk_menit_terlambat ?? 0),
                'jarak_m' => $p?->masuk_jarak_m,
                'lokasi' => $p?->masukLokasi?->nama,
                'pengajuan_jenis' => $i?->jenis,
                'pengajuan_label' => $i?->labelJenis(),
                'alasan_luar_radius' => $p?->masuk_alasan_luar_radius,
                'ada_foto' => $p?->masuk_foto_path !== null,
            ];
        }

        if ($status !== null && $status !== '') {
            $baris = array_values(array_filter($baris, fn (array $b): bool => $b['status'] === $status));
        }

        return [
            'tanggal' => $saat->toDateString(),
            'nama_hari' => JamKerja::DAFTAR_HARI[$saat->dayOfWeekIso] ?? null,
            'hari_libur' => $libur ? 'Hari libur' : null,
            'ringkasan' => $ringkasan,
            'baris' => $baris,
        ];
    }

    /** FR-PRS-11 — antrean presensi luar radius yang menunggu keputusan. */
    public function antreanPersetujuan(?string $tanggal = null): array
    {
        return PresensiPegawai::query()
            ->with(['pegawai', 'masukLokasi', 'pulangLokasi'])
            ->when($tanggal, fn ($q, $t) => $q->whereDate('tanggal', $t))
            ->where(fn ($q) => $q->where('masuk_validasi', PresensiPegawai::MENUNGGU)
                ->orWhere('pulang_validasi', PresensiPegawai::MENUNGGU))
            ->orderBy('tanggal')
            ->get()
            ->toArray();
    }

    /** Kategori status satu pegawai (FR-PRS-10). */
    private function kategori(
        ?PresensiPegawai $presensi,
        ?PengajuanIzin $izin,
        bool $hariKerja,
        bool $libur,
    ): string {
        // Hari libur atau bukan hari kerja: tidak ada kewajiban presensi.
        if ($libur || ! $hariKerja) {
            return $presensi?->sudahMasuk() ? self::HADIR : self::BELUM_PRESENSI;
        }

        // BR-25 — izin/sakit/cuti disetujui membebaskan presensi dan bukan alpa.
        if ($izin !== null && in_array($izin->jenis, PengajuanIzin::JENIS_MEMBEBASKAN, true)) {
            return self::BERHALANGAN;
        }

        if ($presensi === null || ! $presensi->sudahMasuk()) {
            return self::BELUM_PRESENSI;
        }

        if ($presensi->menungguPersetujuan()) {
            return self::MENUNGGU;
        }

        if ($presensi->masuk_validasi === PresensiPegawai::DITOLAK) {
            return self::BELUM_PRESENSI;
        }

        // Luar radius disimpulkan dari VALIDASI disetujui, bukan sekadar lokasi_id
        // kosong — presensi `valid` selalu punya lokasi, sehingga lokasi kosong dengan
        // validasi disetujui-lah yang benar-benar berada di luar radius (BR-17).
        if ($presensi->masuk_validasi === PresensiPegawai::DISETUJUI && $presensi->masuk_lokasi_id === null) {
            return self::LUAR_RADIUS;
        }

        if ($presensi->masuk_status === PresensiPegawai::STATUS_TERLAMBAT) {
            return self::TERLAMBAT;
        }

        if ($presensi->belumPulang()) {
            return self::TIDAK_PRESENSI_PULANG;
        }

        return self::HADIR;
    }
}
