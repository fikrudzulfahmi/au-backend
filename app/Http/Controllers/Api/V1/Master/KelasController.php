<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\KelasRequest;
use App\Http\Resources\KelasResource;
use App\Models\Kelas;
use App\Models\TahunPelajaran;
use App\Services\AuditLogService;
use App\Support\PenjagaHapus;
use App\Support\ResponsDaftar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-KLS-02..05 — kelas per tahun pelajaran.
 * BR-02 (satu guru satu kelas wali per tahun) dijaga di KelasRequest + indeks unik.
 */
class KelasController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Kelas::query()
            ->with(['jurusan', 'waliKelas', 'tahunPelajaran'])
            ->when($request->filled('tahun_pelajaran_id'), fn ($q) => $q->where('tahun_pelajaran_id', $request->integer('tahun_pelajaran_id')))
            ->when($request->filled('tingkat'), fn ($q) => $q->where('tingkat', $request->string('tingkat')))
            ->when($request->filled('jurusan_id'), fn ($q) => $q->where('jurusan_id', $request->integer('jurusan_id')))
            ->when($request->filled('cari'), fn ($q) => $q->where('nama', 'like', '%'.$request->string('cari').'%'))
            ->orderBy('tingkat')->orderBy('nama');

        return ResponsDaftar::buat($query->paginate($this->perHalaman($request)), KelasResource::class);
    }

    public function store(KelasRequest $request): JsonResponse
    {
        $kelas = Kelas::create($request->validated());
        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $kelas, null, $kelas->toArray());

        return response()->json([
            'data' => new KelasResource($kelas->load(['jurusan', 'waliKelas'])),
        ], 201);
    }

    public function update(KelasRequest $request, Kelas $kelas): JsonResponse
    {
        $lama = $kelas->toArray();
        $kelas->update($request->validated());
        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $kelas, $lama, $request->validated());

        return response()->json([
            'data' => new KelasResource($kelas->refresh()->load(['jurusan', 'waliKelas'])),
        ]);
    }

    /** FR-KLS-05 — kelas yang sudah memiliki jurnal tidak boleh dihapus. */
    public function destroy(Request $request, Kelas $kelas): JsonResponse
    {
        if ($pesan = PenjagaHapus::periksa($kelas->getKey(), [
            ['jurnal', 'kelas_id', 'jurnal pembelajaran'],
            ['jadwal', 'kelas_id', 'jadwal pelajaran'],
        ])) {
            return response()->json([
                'message' => $pesan.' Nonaktifkan kelas bila sudah tidak dipakai.',
                'code' => 'KONFLIK_DATA',
            ], 409);
        }

        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $kelas, $kelas->toArray(), null);
        $kelas->delete();

        return response()->json(['message' => 'Kelas berhasil dihapus.']);
    }

    /**
     * FR-KLS-04 — salin kelas dari tahun pelajaran lain dengan penyesuaian tingkat
     * (X→XI, XI→XII; kelas XII tidak disalin). Wali kelas tidak ikut disalin karena
     * penugasannya berbeda tiap tahun (A-04).
     */
    public function salin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tahun_pelajaran_asal_id' => ['required', 'integer', 'exists:tahun_pelajaran,id'],
            'tahun_pelajaran_id' => ['required', 'integer', 'exists:tahun_pelajaran,id', 'different:tahun_pelajaran_asal_id'],
        ], [
            'tahun_pelajaran_id.different' => 'Tahun pelajaran tujuan harus berbeda dari tahun pelajaran asal.',
        ]);

        $asal = TahunPelajaran::findOrFail($data['tahun_pelajaran_asal_id']);

        $hasil = DB::transaction(function () use ($asal, $data): array {
            $dibuat = 0;
            $dilewati = [];

            $kelasAsal = Kelas::query()->where('tahun_pelajaran_id', $asal->getKey())->orderBy('nama')->get();

            foreach ($kelasAsal as $kelas) {
                $tingkatBaru = $kelas->tingkatBerikutnya();

                if ($tingkatBaru === null) {
                    $dilewati[] = ['nama' => $kelas->nama, 'alasan' => 'Kelas XII tidak disalin (diluluskan lewat Plotting Kelas).'];

                    continue;
                }

                $namaBaru = preg_replace('/^X(I{0,2})\s+/', $tingkatBaru.' ', $kelas->nama) ?? $kelas->nama;

                $sudahAda = Kelas::query()
                    ->where('tahun_pelajaran_id', $data['tahun_pelajaran_id'])
                    ->where('nama', $namaBaru)
                    ->exists();

                if ($sudahAda) {
                    $dilewati[] = ['nama' => $namaBaru, 'alasan' => 'Kelas dengan nama tersebut sudah ada di tahun pelajaran tujuan.'];

                    continue;
                }

                Kelas::create([
                    'tahun_pelajaran_id' => $data['tahun_pelajaran_id'],
                    'nama' => $namaBaru,
                    'tingkat' => $tingkatBaru,
                    'jurusan_id' => $kelas->jurusan_id,
                    'wali_kelas_id' => null,
                    'is_active' => true,
                ]);

                $dibuat++;
            }

            return ['dibuat' => $dibuat, 'dilewati' => $dilewati];
        });

        $this->audit->catat('salin_kelas', $request->user(), $asal, null, $hasil);

        return response()->json([
            'message' => "{$hasil['dibuat']} kelas disalin. Wali kelas perlu ditetapkan ulang.",
            'data' => $hasil,
        ]);
    }
}
