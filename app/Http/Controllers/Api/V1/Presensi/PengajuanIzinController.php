<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Http\Requests\PengajuanIzinRequest;
use App\Http\Requests\PutuskanPengajuanRequest;
use App\Http\Resources\PengajuanIzinResource;
use App\Models\Pegawai;
use App\Models\PengajuanIzin;
use App\Models\Role;
use App\Services\Laporan\DokumenResmiService;
use App\Services\PengajuanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-IZN-01..09 — pengajuan izin/sakit/dinas/cuti.
 * Pegawai mengajukan miliknya sendiri; admin/kepala sekolah memutuskan (FR-IZN-04).
 */
final class PengajuanIzinController extends Controller
{
    use MemakaiPegawai;

    public function __construct(
        private readonly PengajuanService $pengajuan,
        private readonly DokumenResmiService $dokumen,
    ) {}

    /** FR-IZN-09 — pegawai melihat riwayatnya; pemantau dapat melihat semua. */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $this->pengguna($request);
        $bolehSemua = $user->punyaPeran(Role::ADMIN)
            || $user->punyaPeran(Role::KEPALA_SEKOLAH)
            || $user->punyaPeran(Role::WAKASEK_KURIKULUM);

        $kueri = PengajuanIzin::query()
            ->with(['pegawai', 'diputuskanOleh'])
            ->when(! $bolehSemua, fn ($q) => $q->where('pegawai_id', $user->pegawai_id))
            ->when($bolehSemua && $request->filled('pegawai_id'), fn ($q) => $q->where('pegawai_id', $request->integer('pegawai_id')))
            ->when($request->filled('nama'), function ($q) use ($request): void {
                $cari = trim((string) $request->string('nama'));
                $q->whereHas('pegawai', fn ($p) => $p->where('nama', 'like', '%'.$cari.'%')->orWhere('nip', 'like', '%'.$cari.'%'));
            })
            ->when($request->filled('jenis'), fn ($q) => $q->where('jenis', $request->string('jenis')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('dari'), fn ($q) => $q->whereDate('tanggal_selesai', '>=', $request->date('dari')))
            ->when($request->filled('sampai'), fn ($q) => $q->whereDate('tanggal_mulai', '<=', $request->date('sampai')))
            ->orderByDesc('tanggal_mulai');

        $format = strtolower((string) $request->query('format', 'json'));
        if (in_array($format, ['pdf', 'excel', 'xlsx'], true)) {
            $format = $format === 'xlsx' ? 'excel' : $format;
            $itemsSemua = $kueri->get();
            $laporan = $this->susunLaporanEkspor($itemsSemua, $request);

            return match ($format) {
                'pdf' => $this->dokumen->pdf(
                    $laporan,
                    null,
                    $this->dokumen->namaBerkas((string) $laporan['judul'], 'pdf'),
                    'landscape',
                ),
                'excel' => $this->dokumen->xlsx(
                    $laporan,
                    $this->dokumen->namaBerkas((string) $laporan['judul'], 'xlsx'),
                ),
                default => response()->json(['data' => $laporan]),
            };
        }

        $daftar = $kueri->paginate($this->perHalaman($request));

        // Tandai keberadaan berkas + MIME agar frontend bisa preview inline.
        // Disk `local` berakar di storage/app/private — jangan pakai storage_path('app/...').
        $items = $daftar->items();
        foreach ($items as $item) {
            $item->berkas_ada = false;
            $item->lampiran_mime = null;

            if ($item->lampiran_path === null || $item->lampiran_dihapus_pada !== null) {
                continue;
            }

            if (! Storage::disk('local')->exists($item->lampiran_path)) {
                continue;
            }

            $item->berkas_ada = true;
            $item->lampiran_mime = Storage::disk('local')->mimeType($item->lampiran_path) ?: null;
        }

        return response()->json([
            'data' => PengajuanIzinResource::collection($items),
            'meta' => [
                'page' => $daftar->currentPage(),
                'per_page' => $daftar->perPage(),
                'total' => $daftar->total(),
                'last_page' => $daftar->lastPage(),
                'menunggu' => PengajuanIzin::query()->when(! $bolehSemua, fn ($q) => $q->where('pegawai_id', $user->pegawai_id))->menunggu()->count(),
            ],
        ]);
    }

    /** FR-IZN-01 — pegawai mengajukan untuk dirinya sendiri. */
    public function store(PengajuanIzinRequest $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $pengajuan = $this->pengajuan->ajukan($pegawai, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Pengajuan terkirim dan menunggu persetujuan.',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ], 201);
    }

    /** FR-IZN-08 — admin membuat pengajuan atas nama pegawai (langsung disetujui). */
    public function storeAtasNama(PengajuanIzinRequest $request, Pegawai $pegawai): JsonResponse
    {
        $pengajuan = $this->pengajuan->buatAtasNama($pegawai, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Pengajuan dibuat atas nama '.$pegawai->nama.' dan langsung disetujui.',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ], 201);
    }

    /** FR-IZN-04 — keputusan admin/kepala sekolah. */
    public function putuskan(PutuskanPengajuanRequest $request, PengajuanIzin $pengajuanIzin): JsonResponse
    {
        $pengajuan = $this->pengajuan->putuskan(
            $pengajuanIzin,
            $request->validated('status'),
            $request->validated('catatan_penyetuju'),
            $request->user(),
        );

        return response()->json([
            'message' => $pengajuan->status === PengajuanIzin::STATUS_DISETUJUI
                ? 'Pengajuan disetujui.'
                : 'Pengajuan ditolak.',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ]);
    }

    /**
     * Hapus pengajuan (admin/kepala sekolah) — membersihkan pengajuan salah input
     * yang kalau dibiarkan memblokir pengajuan baru pada tanggal yang sama.
     */
    public function destroy(Request $request, PengajuanIzin $pengajuanIzin): JsonResponse
    {
        $this->pengajuan->hapusIzin($pengajuanIzin, $request->user());

        return response()->json(['message' => 'Pengajuan dihapus.']);
    }

    /** FR-IZN-03 — pegawai membatalkan pengajuannya selama masih menunggu. */
    public function batalkan(Request $request, PengajuanIzin $pengajuanIzin): JsonResponse
    {
        $user = $this->pengguna($request);

        $milikSendiri = $user->pegawai_id !== null && (int) $user->pegawai_id === (int) $pengajuanIzin->pegawai_id;

        if (! $milikSendiri && ! $user->punyaPeran(Role::ADMIN)) {
            return response()->json(['message' => 'Anda hanya dapat membatalkan pengajuan sendiri.'], 403);
        }

        $pengajuan = $this->pengajuan->batalkan($pengajuanIzin, $user);

        return response()->json([
            'message' => 'Pengajuan dibatalkan.',
            'data' => new PengajuanIzinResource($pengajuan->load('pegawai')),
        ]);
    }

    /** Unduh lampiran pengajuan (surat dokter/tugas). */
    public function lampiran(Request $request, PengajuanIzin $pengajuanIzin): mixed
    {
        $user = $this->pengguna($request);

        // Hanya milik sendiri atau admin/kepala sekolah yang boleh unduh.
        $milikSendiri = $user->pegawai_id !== null && (int) $user->pegawai_id === (int) $pengajuanIzin->pegawai_id;
        $bolehLihat = $milikSendiri
            || $user->punyaPeran(Role::ADMIN)
            || $user->punyaPeran(Role::KEPALA_SEKOLAH)
            || $user->punyaPeran(Role::WAKASEK_KURIKULUM);

        if (! $bolehLihat) {
            return response()->json(['message' => 'Anda tidak berhak mengakses lampiran ini.'], 403);
        }

        if ($pengajuanIzin->lampiran_path === null || $pengajuanIzin->lampiran_dihapus_pada !== null) {
            return response()->json(['message' => 'Lampiran tidak tersedia.'], 404);
        }

        $path = Storage::disk('local')->path($pengajuanIzin->lampiran_path);

        if (! file_exists($path)) {
            return response()->json(['message' => 'Berkas lampiran tidak ditemukan.'], 404);
        }

        return response()->file($path);
    }

    /**
     * @param  Collection<int, PengajuanIzin>  $pengajuan
     * @return array<string, mixed>
     */
    private function susunLaporanEkspor($pengajuan, Request $request): array
    {
        $dari = $request->string('dari')->toString();
        $sampai = $request->string('sampai')->toString();
        $labelPeriode = 'Semua Waktu';
        if ($dari !== '' && $sampai !== '') {
            $labelPeriode = "{$dari} s.d. {$sampai}";
        } elseif ($dari !== '') {
            $labelPeriode = "Mulai {$dari}";
        } elseif ($sampai !== '') {
            $labelPeriode = "Sampai {$sampai}";
        }

        $baris = [];
        $no = 1;
        $totalHari = 0;
        $hitungJenis = [
            PengajuanIzin::JENIS_IZIN => 0,
            PengajuanIzin::JENIS_SAKIT => 0,
            PengajuanIzin::JENIS_DINAS => 0,
            PengajuanIzin::JENIS_CUTI => 0,
        ];
        $hitungStatus = [
            PengajuanIzin::STATUS_MENUNGGU => 0,
            PengajuanIzin::STATUS_DISETUJUI => 0,
            PengajuanIzin::STATUS_DITOLAK => 0,
            PengajuanIzin::STATUS_DIBATALKAN => 0,
        ];

        foreach ($pengajuan as $p) {
            $hari = ($p->tanggal_mulai && $p->tanggal_selesai)
                ? (int) $p->tanggal_mulai->diffInDays($p->tanggal_selesai) + 1
                : 1;

            $totalHari += $hari;
            if (isset($hitungJenis[$p->jenis])) {
                $hitungJenis[$p->jenis]++;
            }
            if (isset($hitungStatus[$p->status])) {
                $hitungStatus[$p->status]++;
            }

            $baris[] = [
                (string) $no++,
                (string) ($p->pegawai?->nip ?? '-'),
                (string) ($p->pegawai?->nama ?? '-'),
                (string) $p->labelJenis(),
                (string) ($p->tanggal_mulai?->toDateString() ?? '-'),
                (string) ($p->tanggal_selesai?->toDateString() ?? '-'),
                (string) $hari,
                (string) (PengajuanIzin::DAFTAR_STATUS[$p->status] ?? $p->status),
                (string) ($p->alasan ?? '-'),
                (string) ($p->diputuskanOleh?->name ?? '-'),
            ];
        }

        return [
            'kode' => 'FR-IZN-RP',
            'judul' => 'Riwayat Pengajuan Izin Pegawai',
            'periode' => $labelPeriode,
            'kolom' => [
                'No', 'NIP', 'Nama Pegawai', 'Jenis', 'Tanggal Mulai',
                'Tanggal Selesai', 'Hari', 'Status', 'Alasan', 'Diputuskan Oleh',
            ],
            'baris' => $baris,
            'ringkasan' => [
                ['label' => 'Total Pengajuan', 'nilai' => count($baris)],
                ['label' => 'Total Hari', 'nilai' => $totalHari],
                ['label' => 'Disetujui', 'nilai' => $hitungStatus[PengajuanIzin::STATUS_DISETUJUI] ?? 0],
                ['label' => 'Menunggu', 'nilai' => $hitungStatus[PengajuanIzin::STATUS_MENUNGGU] ?? 0],
                ['label' => 'Ditolak', 'nilai' => $hitungStatus[PengajuanIzin::STATUS_DITOLAK] ?? 0],
            ],
        ];
    }
}
