<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\PengajuanLuarRadius;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * FR-IZN-01..09 — pengajuan izin, sakit, dinas, cuti, dan presensi luar radius.
 *
 * Dua aturan yang mengikat seluruh layanan ini:
 * - BR-25 jenis izin/sakit/cuti yang disetujui membebaskan presensi; dinas tidak.
 * - BR-17 dinas yang disetujui dengan opsi luar radius menurunkan pengajuan luar
 *   radius berstatus disetujui untuk SETIAP HARI KERJA pada rentang tanggalnya.
 */
final class PengajuanService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly JamKerjaService $jamKerja,
        private readonly WaktuService $waktu,
        private readonly BerkasService $berkas,
    ) {}

    /** FR-IZN-01 — pegawai mengajukan izin/sakit/dinas/cuti. */
    public function ajukan(Pegawai $pegawai, array $data, ?User $oleh = null): PengajuanIzin
    {
        $this->pastikanRentang($data);
        $this->pastikanJenis($data['jenis']);
        $this->pastikanTidakBertumpuk($pegawai, $data['tanggal_mulai'], $data['tanggal_selesai']);

        $luarRadius = $data['jenis'] === PengajuanIzin::JENIS_DINAS && (bool) ($data['presensi_luar_radius'] ?? false);

        return DB::transaction(function () use ($pegawai, $data, $luarRadius, $oleh): PengajuanIzin {
            $pengajuan = PengajuanIzin::create([
                'pegawai_id' => $pegawai->getKey(),
                'jenis' => $data['jenis'],
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'],
                'alasan' => $data['alasan'],
                'lampiran_path' => $this->simpanLampiran($data['lampiran'] ?? null, $pegawai),
                'presensi_luar_radius' => $luarRadius,
                'status' => PengajuanIzin::STATUS_MENUNGGU,
                'dibuat_oleh_admin' => false,
            ]);

            $this->audit->catat(AuditLogService::AKSI_AJUKAN_IZIN, $oleh, $pengajuan, null, [
                'jenis' => $pengajuan->jenis, 'luar_radius' => $luarRadius,
            ]);

            return $pengajuan->refresh();
        });
    }

    /**
     * FR-IZN-08 — admin membuat pengajuan atas nama pegawai; langsung disetujui
     * karena admin sendiri yang memutuskan.
     */
    public function buatAtasNama(Pegawai $pegawai, array $data, User $oleh): PengajuanIzin
    {
        $this->pastikanRentang($data);
        $this->pastikanJenis($data['jenis']);
        $this->pastikanTidakBertumpuk($pegawai, $data['tanggal_mulai'], $data['tanggal_selesai']);

        $luarRadius = $data['jenis'] === PengajuanIzin::JENIS_DINAS && (bool) ($data['presensi_luar_radius'] ?? false);

        return DB::transaction(function () use ($pegawai, $data, $luarRadius, $oleh): PengajuanIzin {
            $pengajuan = PengajuanIzin::create([
                'pegawai_id' => $pegawai->getKey(),
                'jenis' => $data['jenis'],
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'],
                'alasan' => $data['alasan'],
                'lampiran_path' => $this->simpanLampiran($data['lampiran'] ?? null, $pegawai),
                'presensi_luar_radius' => $luarRadius,
                'status' => PengajuanIzin::STATUS_DISETUJUI,
                'diputuskan_oleh' => $oleh->getKey(),
                'diputuskan_pada' => $this->waktu->sekarang(),
                'catatan_penyetuju' => $data['catatan_penyetuju'] ?? 'Dibuat oleh admin (FR-IZN-08).',
                'dibuat_oleh_admin' => true,
            ]);

            // Dinas dengan luar radius tetap menurunkan pengajuan luar radius.
            if ($luarRadius) {
                $this->turunkanLuarRadius($pengajuan, $pegawai, $oleh);
            }

            $this->audit->catat(AuditLogService::AKSI_PUTUSKAN_IZIN, $oleh, $pengajuan, null, [
                'jenis' => $pengajuan->jenis, 'status' => 'disetujui', 'atas_nama' => true,
            ]);

            return $pengajuan->refresh();
        });
    }

    /**
     * FR-IZN-04 — penyetuju admin/kepala sekolah memutuskan pengajuan.
     * Catatan wajib saat menolak.
     */
    public function putuskan(PengajuanIzin $pengajuan, string $status, ?string $catatan, User $oleh): PengajuanIzin
    {
        if (! in_array($status, [PengajuanIzin::STATUS_DISETUJUI, PengajuanIzin::STATUS_DITOLAK], true)) {
            throw AturanBisnisException::tolak('Keputusan tidak dikenali.', 'status');
        }

        if ($pengajuan->status !== PengajuanIzin::STATUS_MENUNGGU) {
            throw AturanBisnisException::tolak(
                'Pengajuan ini sudah diputuskan sebelumnya (status: '.$pengajuan->status.').',
                'status',
            );
        }

        if ($status === PengajuanIzin::STATUS_DITOLAK && ($catatan === null || trim($catatan) === '')) {
            throw AturanBisnisException::tolak('Catatan wajib diisi saat menolak pengajuan (FR-IZN-04).', 'catatan_penyetuju');
        }

        $lama = $pengajuan->only(['status', 'catatan_penyetuju']);

        return DB::transaction(function () use ($pengajuan, $status, $catatan, $lama, $oleh): PengajuanIzin {
            $pengajuan->fill([
                'status' => $status,
                'diputuskan_oleh' => $oleh->getKey(),
                'diputuskan_pada' => $this->waktu->sekarang(),
                'catatan_penyetuju' => $catatan,
            ])->save();

            // FR-IZN-02 / BR-17 — dinas disetujui dengan opsi luar radius.
            if ($status === PengajuanIzin::STATUS_DISETUJUI
                && $pengajuan->jenis === PengajuanIzin::JENIS_DINAS
                && $pengajuan->presensi_luar_radius) {
                $this->turunkanLuarRadius($pengajuan, $pengajuan->pegawai, $oleh);
            }

            // Ditolak → pengajuan luar radius turunan tidak boleh tetap berlaku.
            if ($status === PengajuanIzin::STATUS_DITOLAK) {
                PengajuanLuarRadius::query()
                    ->where('pengajuan_izin_id', $pengajuan->getKey())
                    ->where('status', PengajuanLuarRadius::STATUS_DISETUJUI)
                    ->update(['status' => PengajuanLuarRadius::STATUS_DIBATALKAN]);
            }

            $this->audit->catat(AuditLogService::AKSI_PUTUSKAN_IZIN, $oleh, $pengajuan, $lama, [
                'status' => $status, 'catatan' => $catatan,
            ]);

            return $pengajuan->refresh();
        });
    }

    /** FR-IZN-03 — pegawai membatalkan pengajuannya, hanya selama masih menunggu. */
    public function batalkan(PengajuanIzin $pengajuan, User $oleh): PengajuanIzin
    {
        if ($pengajuan->status !== PengajuanIzin::STATUS_MENUNGGU) {
            throw AturanBisnisException::tolak(
                'Pengajuan hanya dapat dibatalkan selama masih menunggu (FR-IZN-03).',
                'status',
            );
        }

        $lama = $pengajuan->only(['status']);

        $pengajuan->fill(['status' => PengajuanIzin::STATUS_DIBATALKAN])->save();

        $this->audit->catat(AuditLogService::AKSI_BATAL_IZIN, $oleh, $pengajuan, $lama, ['status' => 'dibatalkan']);

        return $pengajuan->refresh();
    }

    /** FR-IZN-06 — pengajuan presensi luar radius mandiri (satu tanggal). */
    public function ajukanLuarRadius(Pegawai $pegawai, array $data, ?User $oleh = null): PengajuanLuarRadius
    {
        $adaSebelumnya = PengajuanLuarRadius::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->where('tanggal', $data['tanggal'])
            ->first();

        // UQ (pegawai_id, tanggal) menjamin satu baris per tanggal; yang ditolak hanya
        // bila pengajuannya masih mengikat (BR-17 deterministik).
        if ($adaSebelumnya !== null && in_array($adaSebelumnya->status, [
            PengajuanLuarRadius::STATUS_MENUNGGU, PengajuanLuarRadius::STATUS_DISETUJUI,
        ], true)) {
            throw AturanBisnisException::tolak(
                'Sudah ada pengajuan luar radius untuk tanggal tersebut.',
                'tanggal',
            );
        }

        return DB::transaction(function () use ($pegawai, $data, $adaSebelumnya, $oleh): PengajuanLuarRadius {
            if ($adaSebelumnya !== null) {
                $adaSebelumnya->delete();
            }

            $pengajuan = PengajuanLuarRadius::create([
                'pegawai_id' => $pegawai->getKey(),
                'tanggal' => $data['tanggal'],
                'alasan' => $data['alasan'],
                'lampiran_path' => $this->simpanLampiran($data['lampiran'] ?? null, $pegawai),
                'status' => PengajuanLuarRadius::STATUS_MENUNGGU,
            ]);

            $this->audit->catat(AuditLogService::AKSI_AJUKAN_LUAR_RADIUS, $oleh, $pengajuan, null, [
                'tanggal' => $pengajuan->tanggal->toDateString(),
            ]);

            return $pengajuan->refresh();
        });
    }

    /** FR-PRS-11 — memutuskan pengajuan luar radius. */
    public function putuskanLuarRadius(
        PengajuanLuarRadius $pengajuan,
        string $status,
        ?string $catatan,
        User $oleh,
    ): PengajuanLuarRadius {
        if (! in_array($status, [PengajuanLuarRadius::STATUS_DISETUJUI, PengajuanLuarRadius::STATUS_DITOLAK], true)) {
            throw AturanBisnisException::tolak('Keputusan tidak dikenali.', 'status');
        }

        if ($status === PengajuanLuarRadius::STATUS_DITOLAK && ($catatan === null || trim($catatan) === '')) {
            throw AturanBisnisException::tolak('Catatan wajib diisi saat menolak.', 'catatan_penyetuju');
        }

        $lama = $pengajuan->only(['status']);

        $pengajuan->fill([
            'status' => $status,
            'diputuskan_oleh' => $oleh->getKey(),
            'diputuskan_pada' => $this->waktu->sekarang(),
            'catatan_penyetuju' => $catatan,
        ])->save();

        $this->audit->catat(AuditLogService::AKSI_PUTUSKAN_LUAR_RADIUS, $oleh, $pengajuan, $lama, ['status' => $status]);

        return $pengajuan->refresh();
    }

    /** FR-IZN-09 — riwayat pengajuan milik pegawai (izin + luar radius). */
    public function riwayat(Pegawai $pegawai, array $filter = [], int $perHalaman = 25): LengthAwarePaginator
    {
        return PengajuanIzin::query()
            ->with(['pegawai', 'diputuskanOleh'])
            ->where('pegawai_id', $pegawai->getKey())
            ->when($filter['jenis'] ?? null, fn ($q, $jenis) => $q->where('jenis', $jenis))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('tanggal_mulai')
            ->paginate($perHalaman);
    }

    /**
     * FR-IZN-07 / BR-25 — pengajuan yang membuat pegawai berhalangan pada tanggal tertentu.
     * Dipakai Fase 4 untuk menandai sesi jadwal sebagai "Berhalangan".
     */
    public function berhalanganPada(Pegawai $pegawai, string $tanggal): ?PengajuanIzin
    {
        return PengajuanIzin::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->where('status', PengajuanIzin::STATUS_DISETUJUI)
            ->where('tanggal_mulai', '<=', $tanggal)
            ->where('tanggal_selesai', '>=', $tanggal)
            ->first();
    }

    // ------------------------------------------------------------------
    // Bagian dalam
    // ------------------------------------------------------------------

    private function pastikanRentang(array $data): void
    {
        if (empty($data['tanggal_mulai']) || empty($data['tanggal_selesai'])) {
            throw AturanBisnisException::tolak('Tanggal mulai dan selesai wajib diisi.', 'tanggal_mulai');
        }

        if ($data['tanggal_selesai'] < $data['tanggal_mulai']) {
            throw AturanBisnisException::tolak('Tanggal selesai tidak boleh lebih awal daripada tanggal mulai.', 'tanggal_selesai');
        }

        if (trim((string) ($data['alasan'] ?? '')) === '') {
            throw AturanBisnisException::tolak('Alasan wajib diisi.', 'alasan');
        }
    }

    private function pastikanJenis(string $jenis): void
    {
        if (! array_key_exists($jenis, PengajuanIzin::DAFTAR_JENIS)) {
            throw AturanBisnisException::tolak('Jenis pengajuan tidak dikenali.', 'jenis');
        }
    }

    /** FR-IZN-05 — tidak boleh bertumpuk tanggal dengan pengajuan yang masih mengikat. */
    private function pastikanTidakBertumpuk(Pegawai $pegawai, string $mulai, string $selesai): void
    {
        $bentrok = PengajuanIzin::query()
            ->where('pegawai_id', $pegawai->getKey())
            ->mengikat()
            ->where('tanggal_mulai', '<=', $selesai)
            ->where('tanggal_selesai', '>=', $mulai)
            ->first();

        if ($bentrok !== null) {
            throw AturanBisnisException::tolak(
                'Sudah ada pengajuan '.$bentrok->labelJenis().' ('.$bentrok->status.') pada rentang tanggal tersebut (FR-IZN-05).',
                'tanggal_mulai',
                'IZN-05',
            );
        }
    }

    /**
     * FR-IZN-02 / BR-17 — menurunkan pengajuan luar radius berstatus disetujui
     * untuk setiap HARI KERJA pada rentang tanggal dinas.
     */
    private function turunkanLuarRadius(PengajuanIzin $pengajuan, Pegawai $pegawai, User $oleh): int
    {
        $mulai = CarbonImmutable::parse($pengajuan->tanggal_mulai)->startOfDay();
        $selesai = CarbonImmutable::parse($pengajuan->tanggal_selesai)->startOfDay();
        $jumlah = 0;

        for ($tanggal = $mulai; $tanggal->lessThanOrEqualTo($selesai); $tanggal = $tanggal->addDay()) {
            // Hari kerja saja: Sabtu/Minggu dan hari libur dilewati (BR-24).
            if (! $this->jamKerja->hariKerja($pegawai->jenis_pegawai, $tanggal)) {
                continue;
            }

            PengajuanLuarRadius::updateOrCreate(
                ['pegawai_id' => $pegawai->getKey(), 'tanggal' => $tanggal->toDateString()],
                [
                    'alasan' => 'Otomatis dari dinas: '.$pengajuan->alasan,
                    'pengajuan_izin_id' => $pengajuan->getKey(),
                    'status' => PengajuanLuarRadius::STATUS_DISETUJUI,
                    'diputuskan_oleh' => $oleh->getKey(),
                    'diputuskan_pada' => $this->waktu->sekarang(),
                    'catatan_penyetuju' => 'Menyusul dinas yang disetujui (FR-IZN-02).',
                ],
            );

            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * Lampiran opsional: gambar dikompres seperti berkas lain (FR-SCH-04),
     * berkas non-gambar (PDF) disimpan apa adanya.
     */
    private function simpanLampiran(?UploadedFile $berkas, Pegawai $pegawai): ?string
    {
        if ($berkas === null) {
            return null;
        }

        $folder = 'pengajuan/'.$pegawai->getKey();

        if (str_starts_with((string) $berkas->getMimeType(), 'image/')) {
            return $this->berkas->simpanGambarTerkompres($berkas, $folder);
        }

        $nama = uniqid('lmp_', true).'.'.$berkas->getClientOriginalExtension();
        $relatif = $folder.'/'.$nama;

        Storage::disk('local')->put($relatif, $berkas->get());

        return $relatif;
    }
}
