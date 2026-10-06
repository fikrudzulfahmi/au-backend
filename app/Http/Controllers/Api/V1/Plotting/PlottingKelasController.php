<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plotting;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MutasiKelasRequest;
use App\Http\Requests\PlottingBaruRequest;
use App\Http\Requests\WizardNaikKelasRequest;
use App\Http\Resources\MutasiKelasResource;
use App\Http\Resources\PlottingKelasResource;
use App\Http\Resources\SiswaResource;
use App\Models\Kelas;
use App\Models\PlottingKelas;
use App\Models\Siswa;
use App\Models\TahunPelajaran;
use App\Services\ExcelService;
use App\Services\ImportMasterService;
use App\Services\PlottingKelasService;
use App\Support\ResponsDaftar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** FR-PLK — plotting kelas / rombel siswa. */
final class PlottingKelasController extends Controller
{
    public function __construct(
        private readonly PlottingKelasService $service,
        private readonly ImportMasterService $import,
        private readonly ExcelService $excel,
    ) {}

    /** FR-PLK-08 — daftar siswa per kelas pada sebuah tahun pelajaran. */
    public function index(Request $request): JsonResponse
    {
        $tahunId = $this->tahunDariPermintaan($request);

        $query = PlottingKelas::with(['siswa', 'kelas.jurusan'])
            // Kolom dikualifikasi dengan nama tabel: setelah `join kelas`, `tahun_pelajaran_id`
            // ada di dua tabel sehingga WHERE tanpa kualifikasi menjadi ambigu (MySQL 1052).
            ->where('plotting_kelas.tahun_pelajaran_id', $tahunId)
            ->when($request->filled('kelas_id'), fn ($q) => $q->where('plotting_kelas.kelas_id', $request->integer('kelas_id')))
            ->when($request->filled('status_akhir'), fn ($q) => $q->where('plotting_kelas.status_akhir', $request->string('status_akhir')))
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->whereHas('siswa', fn ($s) => $s->where('nama', 'like', $cari)
                    ->orWhere('nis', 'like', $cari)
                    ->orWhere('nisn', 'like', $cari));
            })
            ->join('kelas', 'kelas.id', '=', 'plotting_kelas.kelas_id')
            ->orderBy('kelas.nama')
            ->orderBy('plotting_kelas.id')
            ->select('plotting_kelas.*');

        return ResponsDaftar::buat(
            $query->paginate($this->perHalaman($request)),
            PlottingKelasResource::class,
            ['tahun_pelajaran_id' => $tahunId],
        );
    }

    /** FR-PLK-08 — ringkasan jumlah siswa per kelas (termasuk L/P). */
    public function ringkasan(Request $request): JsonResponse
    {
        $tahunId = $this->tahunDariPermintaan($request);

        $kelas = Kelas::with('jurusan:id,kode')->where('tahun_pelajaran_id', $tahunId)->orderBy('nama')->get();

        $data = $kelas->map(function (Kelas $k) use ($tahunId): array {
            $baris = PlottingKelas::query()
                ->where('tahun_pelajaran_id', $tahunId)
                ->where('kelas_id', $k->id);

            $semua = (clone $baris)->count();
            $l = (clone $baris)->whereHas('siswa', fn ($q) => $q->where('jenis_kelamin', 'L'))->count();
            $p = $semua - $l;

            return [
                'kelas_id' => $k->id,
                'nama' => $k->nama,
                'tingkat' => $k->tingkat,
                'jurusan' => $k->jurusan?->kode,
                'wali_kelas' => $k->waliKelas?->nama,
                'jumlah' => $semua,
                'jumlah_l' => $l,
                'jumlah_p' => $p,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $data->sum('jumlah')],
        ]);
    }

    /** FR-PLK-08 — daftar siswa aktif yang belum terplot pada tahun pelajaran ini. */
    public function belumTerplot(Request $request): JsonResponse
    {
        $tahunId = $this->tahunDariPermintaan($request);

        $sudahTerplot = PlottingKelas::where('tahun_pelajaran_id', $tahunId)->pluck('siswa_id');

        $query = Siswa::query()
            ->where('status', 'aktif')
            ->whereNotIn('id', $sudahTerplot)
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->where(fn ($w) => $w->where('nama', 'like', $cari)->orWhere('nis', 'like', $cari));
            })
            ->orderBy('nama');

        return ResponsDaftar::buat($query->paginate($this->perHalaman($request)), SiswaResource::class);
    }

    /** FR-PLK-01 — menempatkan siswa (dapat massal) ke sebuah kelas. */
    public function store(PlottingBaruRequest $request): JsonResponse
    {
        $kelas = Kelas::findOrFail($request->integer('kelas_id'));

        $hasil = $this->service->plotBaru($kelas, $request->array('siswa_ids'), $request->user());

        return response()->json([
            'message' => $hasil['dibuat'] > 0
                ? "{$hasil['dibuat']} siswa ditempatkan ke kelas {$kelas->nama}."
                : 'Tidak ada siswa yang ditempatkan.',
            'data' => $hasil,
        ], $hasil['dibuat'] > 0 ? 201 : 200);
    }

    /** Menghapus satu baris plotting (mis. salah tempat). */
    public function destroy(PlottingKelas $plottingKelas): JsonResponse
    {
        $plottingKelas->delete();

        return response()->json(['message' => 'Plotting dihapus.']);
    }

    /** FR-PLK-08 — riwayat kelas seorang siswa dari tahun ke tahun. */
    public function riwayat(Siswa $siswa): JsonResponse
    {
        $riwayat = PlottingKelas::with(['kelas.tahunPelajaran', 'kelas.jurusan', 'mutasi.kelasAsal', 'mutasi.kelasTujuan'])
            ->where('siswa_id', $siswa->id)
            ->get()
            ->sortByDesc(fn (PlottingKelas $p) => $p->kelas?->tahunPelajaran?->nama)
            ->map(fn (PlottingKelas $p): array => [
                'plotting_kelas_id' => $p->id,
                'tahun_pelajaran' => $p->kelas?->tahunPelajaran?->nama,
                'kelas' => $p->kelas?->nama,
                'tingkat' => $p->kelas?->tingkat,
                'jurusan' => $p->kelas?->jurusan?->kode,
                'status_akhir' => $p->status_akhir,
                'mutasi' => $p->mutasi->map(fn ($m): array => [
                    'tanggal' => $m->tanggal?->format('Y-m-d'),
                    'dari' => $m->kelasAsal?->nama,
                    'ke' => $m->kelasTujuan?->nama,
                    'alasan' => $m->alasan,
                ])->values(),
            ])->values();

        return response()->json([
            'data' => [
                'siswa' => ['id' => $siswa->id, 'nis' => $siswa->nis, 'nama' => $siswa->nama, 'status' => $siswa->status],
                'riwayat' => $riwayat,
            ],
        ]);
    }

    /** FR-PLK-05 — mutasi siswa antar kelas dalam tahun berjalan. */
    public function mutasi(MutasiKelasRequest $request, PlottingKelas $plottingKelas): JsonResponse
    {
        $tujuan = Kelas::findOrFail($request->integer('kelas_tujuan_id'));

        $mutasi = $this->service->mutasi(
            $plottingKelas,
            $tujuan,
            (string) $request->string('tanggal'),
            (string) $request->string('alasan'),
            $request->user(),
        );

        return response()->json([
            'message' => "Siswa dipindahkan ke kelas {$tujuan->nama}.",
            'data' => new MutasiKelasResource($mutasi->load(['kelasAsal', 'kelasTujuan'])),
        ], 201);
    }

    /** FR-PLK-06 — membatalkan hasil naik kelas. */
    public function batalkan(PlottingKelas $plottingKelas, Request $request): JsonResponse
    {
        $hasil = $this->service->batalkan($plottingKelas, $request->user());

        return response()->json([
            'message' => 'Hasil naik kelas dibatalkan. Siswa dapat diproses ulang.',
            'data' => $hasil,
        ]);
    }

    /** FR-PLK-02 — pratinjau wizard (tanpa mengubah data). */
    public function wizardPratinjau(WizardNaikKelasRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->pratinjauWizard(
                $request->integer('tahun_pelajaran_asal_id'),
                $request->integer('tahun_pelajaran_tujuan_id'),
                $request->array('kelas_asal_ids'),
            ),
        ]);
    }

    /** FR-PLK-02/03 — eksekusi wizard (transaksional + idempotent). */
    public function wizardEksekusi(WizardNaikKelasRequest $request): JsonResponse
    {
        $hasil = $this->service->eksekusiWizard(
            $request->integer('tahun_pelajaran_asal_id'),
            $request->integer('tahun_pelajaran_tujuan_id'),
            $request->array('keputusan'),
            $request->user(),
        );

        return response()->json([
            'message' => $hasil['diproses'] > 0
                ? "Naik kelas diproses untuk {$hasil['diproses']} siswa."
                : 'Tidak ada siswa yang perlu diproses (semua sudah selesai).',
            'data' => $hasil,
        ]);
    }

    /** FR-PLK-09 — ekspor daftar siswa per kelas (Excel). */
    public function ekspor(Request $request): Response
    {
        $tahunId = $this->tahunDariPermintaan($request);
        $tahun = TahunPelajaran::findOrFail($tahunId);

        $kelasId = $request->filled('kelas_id') ? $request->integer('kelas_id') : null;

        $baris = PlottingKelas::with(['siswa', 'kelas'])
            ->where('tahun_pelajaran_id', $tahunId)
            ->when($kelasId !== null, fn ($q) => $q->where('kelas_id', $kelasId))
            ->get()
            ->sortBy([['kelas.nama', 'asc'], ['siswa.nama', 'asc']])
            ->map(fn (PlottingKelas $p): array => [
                $p->kelas?->nama,
                $p->siswa?->nis,
                $p->siswa?->nisn,
                $p->siswa?->nama,
                $p->siswa?->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan',
                $p->status_akhir,
            ])
            ->values()
            ->all();

        $nama = $kelasId !== null
            ? 'daftar-siswa-'.str_replace(' ', '-', (string) Kelas::find($kelasId)?->nama).'.xlsx'
            : 'daftar-siswa-per-kelas-'.str_replace('/', '-', $tahun->nama).'.xlsx';

        return $this->excel->unduhXlsx(
            $nama,
            ['Kelas', 'NIS', 'NISN', 'Nama', 'Jenis Kelamin', 'Status Akhir'],
            $baris,
            [],
            [1, 2],
        );
    }

    /** FR-PLK-01 — import penempatan siswa (NIS + nama kelas). */
    public function import(Request $request): JsonResponse
    {
        $request->validate(
            ['berkas' => ['required', 'file', 'max:4096']],
            ['berkas.required' => 'Berkas wajib dipilih.', 'berkas.max' => 'Ukuran berkas maksimal 4 MB.'],
        );

        $tahunId = $this->tahunDariPermintaan($request);

        try {
            $laporan = $this->import->importPlottingKelas($request->file('berkas'), $tahunId, $request->user());
        } catch (\RuntimeException $e) {
            throw AturanBisnisException::tolak(
                'Berkas tidak dapat dibaca sebagai Excel. Pastikan berkas xlsx, xls, atau csv yang sah.',
                'berkas',
                'BERKAS_TIDAK_TERBACA',
            );
        }

        return response()->json([
            'message' => "{$laporan['berhasil']} siswa ditempatkan, {$laporan['gagal']} baris gagal.",
            'data' => $laporan,
        ]);
    }

    private function tahunDariPermintaan(Request $request): int
    {
        if ($request->filled('tahun_pelajaran_id')) {
            return $request->integer('tahun_pelajaran_id');
        }

        $aktif = TahunPelajaran::where('status', TahunPelajaran::STATUS_AKTIF)->first();

        if ($aktif === null) {
            throw new NotFoundHttpException('Belum ada tahun pelajaran aktif.');
        }

        return $aktif->id;
    }
}
