<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pengaturan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pengaturan\PengaturanTtdRequest;
use App\Http\Resources\PenandatanganResource;
use App\Models\Penandatangan;
use App\Models\PengaturanTtd;
use App\Models\ProfilSekolah;
use App\Services\AuditLogService;
use App\Services\Laporan\DokumenResmiService;
use App\Support\PeriodeLaporan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-KOP-02/04/05 — pengaturan kop surat & tata letak tanda tangan, beserta
 * pratinjau sebelum disimpan. Hanya admin (matriks Bagian 2).
 *
 * Baris teks kop dan posisi logo disunting lewat Info Sekolah (FR-KOP-01) karena
 * keduanya memang milik `profil_sekolah`; endpoint ini melengkapinya dengan tata
 * letak tanda tangan dan pratinjau dokumen.
 */
final class PengaturanTtdController extends Controller
{
    public function __construct(
        private readonly DokumenResmiService $dokumen,
        private readonly AuditLogService $audit,
    ) {}

    /** Ringkasan pengaturan dokumen: kop, tata letak tanda tangan, daftar penandatangan. */
    public function show(): JsonResponse
    {
        $sekolah = ProfilSekolah::query()->first();

        return response()->json([
            'data' => [
                'kop' => [
                    'baris1' => $sekolah?->kop_baris1,
                    'baris2' => $sekolah?->kop_baris2,
                    'baris3' => $sekolah?->kop_baris3,
                    'alamat' => $sekolah?->alamatLengkap(),
                    'kontak' => collect([$sekolah?->telepon, $sekolah?->email, $sekolah?->website])->filter()->implode(' | '),
                    'tampilkan_logo_kiri' => (bool) ($sekolah?->kop_tampilkan_logo_kiri ?? false),
                    'tampilkan_logo_kanan' => (bool) ($sekolah?->kop_tampilkan_logo_kanan ?? false),
                    'ada_logo_kiri' => $sekolah?->logo_kiri_path !== null,
                    'ada_logo_kanan' => $sekolah?->logo_kanan_path !== null,
                ],
                'tanda_tangan' => $this->tata(),
                'penandatangan' => PenandatanganResource::collection(
                    Penandatangan::query()->orderByDesc('is_default')->orderBy('urutan')->orderBy('id')->get()
                ),
            ],
        ]);
    }

    /** FR-KOP-04 — menyimpan kota/tanggal penetapan, posisi, dan opsi "Mengetahui". */
    public function simpan(PengaturanTtdRequest $request): JsonResponse
    {
        $data = $request->validated();
        $lama = PengaturanTtd::query()->first()?->toArray();

        $tata = PengaturanTtd::query()->first() ?? new PengaturanTtd;
        $tata->fill($data)->save();

        $this->audit->catat(AuditLogService::AKSI_UBAH_PENGATURAN, $request->user(), $tata, $lama, $data);

        return response()->json([
            'message' => 'Tata letak tanda tangan berhasil disimpan.',
            'data' => $this->tata(),
        ]);
    }

    /**
     * FR-KOP-05 — pratinjau kop & blok tanda tangan sebelum disimpan.
     *
     * Nilai yang dikirim pada badan permintaan dipakai lebih dahulu, sehingga
     * pengguna dapat melihat hasilnya tanpa menyimpan lebih dulu.
     */
    public function pratinjau(Request $request): Response
    {
        $masukan = $request->validate([
            'kota_penetapan' => ['nullable', 'string', 'max:191'],
            'mode_tanggal' => ['nullable', 'in:otomatis,manual'],
            'tanggal_manual' => ['nullable', 'date_format:Y-m-d'],
            'posisi' => ['nullable', 'in:kanan,kiri,dua_kolom'],
            'tampilkan_mengetahui' => ['nullable', 'boolean'],
            'penandatangan_ids' => ['nullable', 'array', 'max:'.DokumenResmiService::MAKS_PENANDATANGAN],
            'penandatangan_ids.*' => ['integer'],
        ]);

        $ids = $masukan['penandatangan_ids'] ?? null;
        $konteks = $this->dokumen->konteks(is_array($ids) ? $ids : null);

        $ttd = $konteks['tanda_tangan'];
        $ttd['kota'] = $masukan['kota_penetapan'] ?? $ttd['kota'];
        $ttd['posisi'] = $masukan['posisi'] ?? $ttd['posisi'];
        $ttd['mengetahui'] = (bool) ($masukan['tampilkan_mengetahui'] ?? $ttd['mengetahui']);

        $mode = $masukan['mode_tanggal'] ?? null;
        if ($mode === 'manual' && ! empty($masukan['tanggal_manual'])) {
            $ttd['tanggal'] = PeriodeLaporan::tanggalPanjang(
                CarbonImmutable::parse((string) $masukan['tanggal_manual'])->startOfDay()
            );
        }

        $konteks['tanda_tangan'] = $ttd;

        return $this->dokumen->pratinjau($this->laporanContoh(), $konteks);
    }

    /** @return array<string, mixed> */
    private function tata(): array
    {
        $tata = PengaturanTtd::query()->first();

        return [
            'kota_penetapan' => $tata?->kota_penetapan,
            'mode_tanggal' => $tata->mode_tanggal ?? 'otomatis',
            'tanggal_manual' => $tata?->tanggal_manual?->toDateString(),
            'posisi' => $tata->posisi ?? 'kanan',
            'tampilkan_mengetahui' => (bool) ($tata?->tampilkan_mengetahui ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function laporanContoh(): array
    {
        return [
            'kode' => 'FR-KOP-05',
            'judul' => 'Pratinjau Kop Surat & Tanda Tangan',
            'periode' => 'Contoh periode',
            'kolom' => ['No', 'Keterangan'],
            'baris' => [
                [1, 'Contoh baris tabel laporan.'],
                [2, 'Kop dan blok tanda tangan di atas inilah yang akan dipakai.'],
            ],
            'ringkasan' => [['label' => 'Contoh', 'nilai' => 2]],
        ];
    }
}
