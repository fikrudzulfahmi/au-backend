<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plotting;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlottingMapelRequest;
use App\Http\Requests\SalinRequest;
use App\Http\Resources\PlottingMapelResource;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pegawai;
use App\Models\PlottingMapel;
use App\Models\Role;
use App\Models\Semester;
use App\Services\ExcelService;
use App\Services\PlottingMapelService;
use App\Support\ResponsDaftar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** FR-PLM — plotting mapel (guru pengampu). */
final class PlottingMapelController extends Controller
{
    public function __construct(
        private readonly PlottingMapelService $service,
        private readonly ExcelService $excel,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $semester = $this->semesterDariPermintaan($request);

        // Matriks Bagian 2 — guru hanya melihat plotting miliknya (L/S). Bila akun guru
        // tidak terhubung ke data pegawai, pegawai_id 0 membuat hasilnya kosong (aman),
        // bukan malah menampilkan seluruh plotting sekolah.
        $hanyaSendiri = $this->hanyaGuru($request);

        $query = PlottingMapel::with(['pegawai:id,nama', 'mapel', 'kelas:id,nama,tingkat'])
            ->withCount('jadwal')
            ->where('semester_id', $semester->id)
            ->when($hanyaSendiri, fn ($q) => $q->where('pegawai_id', $request->user()?->pegawai_id ?? 0))
            ->when($request->filled('kelas_id'), fn ($q) => $q->where('kelas_id', $request->integer('kelas_id')))
            ->when($request->filled('pegawai_id'), fn ($q) => $q->where('pegawai_id', $request->integer('pegawai_id')))
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->where(fn ($w) => $w
                    ->whereHas('mapel', fn ($m) => $m->where('nama', 'like', $cari)->orWhere('kode', 'like', $cari))
                    ->orWhereHas('pegawai', fn ($p) => $p->where('nama', 'like', $cari)));
            })
            ->join('kelas', 'kelas.id', '=', 'plotting_mapel.kelas_id')
            ->join('mapel', 'mapel.id', '=', 'plotting_mapel.mapel_id')
            ->orderBy('kelas.nama')
            ->orderBy('mapel.nama')
            ->select('plotting_mapel.*');

        return ResponsDaftar::buat(
            $query->paginate($this->perHalaman($request)),
            PlottingMapelResource::class,
            ['semester_id' => $semester->id],
        );
    }

    /** FR-PLM-04a — matriks kelas × mapel untuk satu semester. */
    public function matriks(Request $request): JsonResponse
    {
        $semester = $this->semesterDariPermintaan($request);

        $kelas = Kelas::where('tahun_pelajaran_id', $semester->tahun_pelajaran_id)->orderBy('nama')->get();
        $plotting = PlottingMapel::with('pegawai:id,nama')
            ->where('semester_id', $semester->id)
            ->get();

        $matriks = $kelas->map(fn (Kelas $k): array => [
            'kelas_id' => $k->id,
            'nama' => $k->nama,
            'tingkat' => $k->tingkat,
            'mapel' => $plotting->where('kelas_id', $k->id)->map(fn (PlottingMapel $p): array => [
                'plotting_mapel_id' => $p->id,
                'mapel_id' => $p->mapel_id,
                'guru' => $p->pegawai?->nama,
                'jp_per_minggu' => $p->jp_per_minggu,
            ])->values(),
        ]);

        return response()->json([
            'data' => [
                'semester_id' => $semester->id,
                'kelas' => $matriks,
                'mapel' => Mapel::where('is_active', true)->orderBy('nama')->get(['id', 'kode', 'nama']),
            ],
        ]);
    }

    /** FR-PLM-04b — daftar per guru dengan total JP per minggu. */
    public function perGuru(Request $request): JsonResponse
    {
        $semester = $this->semesterDariPermintaan($request);

        $plotting = PlottingMapel::with(['mapel:id,kode,nama', 'kelas:id,nama'])
            ->withCount('jadwal')
            ->where('semester_id', $semester->id)
            ->get();

        $perGuru = [];
        foreach ($plotting->groupBy('pegawai_id') as $pegawaiId => $daftar) {
            $guru = Pegawai::find($pegawaiId);

            $perGuru[] = [
                'pegawai_id' => (int) $pegawaiId,
                'nama' => $guru?->nama,
                'jabatan' => $guru?->label_jabatan,
                'total_jp' => $daftar->sum('jp_per_minggu'),
                'jumlah_kelas' => $daftar->pluck('kelas_id')->unique()->count(),
                'rincian' => $daftar->map(fn (PlottingMapel $p): array => [
                    'plotting_mapel_id' => $p->id,
                    'mapel' => $p->mapel?->nama,
                    'kode_mapel' => $p->mapel?->kode,
                    'kelas' => $p->kelas?->nama,
                    'jp_per_minggu' => $p->jp_per_minggu,
                    'jp_terjadwal' => $p->jadwal_count,
                ])->sortBy('kelas')->values(),
            ];
        }

        usort($perGuru, fn (array $a, array $b): int => $b['total_jp'] <=> $a['total_jp']);

        return response()->json(['data' => $perGuru]);
    }

    public function store(PlottingMapelRequest $request): JsonResponse
    {
        $plotting = $this->service->simpan(
            Semester::findOrFail($request->integer('semester_id')),
            Pegawai::findOrFail($request->integer('pegawai_id')),
            Mapel::findOrFail($request->integer('mapel_id')),
            Kelas::findOrFail($request->integer('kelas_id')),
            $request->integer('jp_per_minggu'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Plotting mapel disimpan.',
            'data' => new PlottingMapelResource($plotting->load(['pegawai:id,nama', 'mapel', 'kelas:id,nama,tingkat'])),
        ], 201);
    }

    public function update(PlottingMapelRequest $request, PlottingMapel $plottingMapel): JsonResponse
    {
        $plotting = $this->service->perbarui(
            $plottingMapel,
            $request->integer('pegawai_id'),
            $request->integer('jp_per_minggu'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Plotting mapel diperbarui.',
            'data' => new PlottingMapelResource($plotting->load(['pegawai:id,nama', 'mapel', 'kelas:id,nama,tingkat'])),
        ]);
    }

    public function destroy(Request $request, PlottingMapel $plottingMapel): JsonResponse
    {
        $this->service->hapus($plottingMapel, $request->user());

        return response()->json(['message' => 'Plotting mapel dihapus.']);
    }

    /** FR-PLM-05 — salin plotting dari semester lain. */
    public function salin(SalinRequest $request): JsonResponse
    {
        $hasil = $this->service->salin(
            Semester::findOrFail($request->integer('semester_asal_id')),
            Semester::findOrFail($request->integer('semester_tujuan_id')),
            $request->user(),
        );

        return response()->json([
            'message' => "{$hasil['dibuat']} plotting disalin, {$hasil['dilewati']} dilewati.",
            'data' => $hasil,
        ]);
    }

    /** Ekspor plotting mapel (Excel). */
    public function ekspor(Request $request): Response
    {
        $semester = $this->semesterDariPermintaan($request);

        $baris = PlottingMapel::with(['pegawai:id,nama', 'mapel', 'kelas:id,nama'])
            ->withCount('jadwal')
            ->where('semester_id', $semester->id)
            ->get()
            ->sortBy([['kelas.nama', 'asc'], ['mapel.nama', 'asc']])
            ->map(fn (PlottingMapel $p): array => [
                $p->kelas?->nama,
                $p->mapel?->kode,
                $p->mapel?->nama,
                $p->mapel?->kelompok,
                $p->pegawai?->nama,
                $p->jp_per_minggu,
                $p->jadwal_count,
            ])
            ->values()
            ->all();

        return $this->excel->unduhXlsx(
            'plotting-mapel.xlsx',
            ['Kelas', 'Kode Mapel', 'Mata Pelajaran', 'Kelompok', 'Guru Pengampu', 'JP/Minggu', 'JP Terjadwal'],
            $baris,
        );
    }

    /** True bila pengguna hanya berperan guru, sehingga dibatasi ke data miliknya. */
    private function hanyaGuru(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return ! $user->punyaPeran(Role::ADMIN, Role::KEPALA_SEKOLAH, Role::WAKASEK_KURIKULUM);
    }

    private function semesterDariPermintaan(Request $request): Semester
    {
        if ($request->filled('semester_id')) {
            $semester = Semester::find($request->integer('semester_id'));
            if ($semester !== null) {
                return $semester;
            }
        }

        $aktif = Semester::where('is_active', true)->first();

        if ($aktif === null) {
            throw new NotFoundHttpException('Belum ada semester aktif.');
        }

        return $aktif;
    }
}
