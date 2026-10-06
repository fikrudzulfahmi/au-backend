<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Akademik;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\JadwalRequest;
use App\Http\Resources\JadwalResource;
use App\Models\Jadwal;
use App\Models\Pegawai;
use App\Models\Role;
use App\Models\Semester;
use App\Services\ExcelService;
use App\Services\JadwalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** FR-JDW — jadwal pelajaran. */
final class JadwalController extends Controller
{
    public function __construct(
        private readonly JadwalService $service,
        private readonly ExcelService $excel,
    ) {}

    /** FR-JDW-04 — grid jadwal per kelas dan/atau per guru. */
    public function index(Request $request): JsonResponse
    {
        $semester = $this->semesterDariPermintaan($request);

        $kelasId = $request->filled('kelas_id') ? $request->integer('kelas_id') : null;

        // Guru hanya melihat jadwalnya sendiri; permintaan pegawai_id dari guru diabaikan
        // agar tidak dapat dipakai mengintip jadwal guru lain (matriks Bagian 2: L/S).
        $pegawaiId = $this->hanyaGuru($request)
            ? ($request->user()?->pegawai_id ?? 0)
            : ($request->filled('pegawai_id') ? $request->integer('pegawai_id') : null);

        return response()->json([
            'data' => $this->service->grid($semester, $kelasId, $pegawaiId),
        ]);
    }

    public function store(JadwalRequest $request): JsonResponse
    {
        $jadwal = $this->service->simpan(
            Semester::findOrFail($request->integer('semester_id')),
            $request->integer('hari'),
            $request->integer('slot_jam_id'),
            $request->integer('plotting_mapel_id'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Jadwal disimpan.',
            'data' => new JadwalResource($jadwal),
            'peringatan' => $this->service->peringatan($jadwal->semester),
        ], 201);
    }

    public function destroy(Request $request, Jadwal $jadwal): JsonResponse
    {
        $semester = $jadwal->semester;
        $this->service->hapus($jadwal, $request->user());

        return response()->json([
            'message' => 'Jadwal dihapus.',
            'peringatan' => $semester !== null ? $this->service->peringatan($semester) : [],
        ]);
    }

    /** FR-JDW-07 — peringatan JP terjadwal kurang dari jp_per_minggu (bukan blokir). */
    public function peringatan(Request $request): JsonResponse
    {
        $semester = $this->semesterDariPermintaan($request);

        return response()->json(['data' => $this->service->peringatan($semester)]);
    }

    /** FR-JDW-06 — jadwal hari ini milik pengguna yang sedang masuk. */
    public function hariIni(Request $request): JsonResponse
    {
        $pegawai = $this->pegawaiPengguna($request);
        $semester = $this->semesterDariPermintaan($request);

        return response()->json([
            'data' => [
                'hari' => (int) now()->isoWeekday(),
                'nama_hari' => $this->service->namaHari((int) now()->isoWeekday()),
                'jadwal' => $this->service->jadwalHariIni($pegawai, $semester),
            ],
        ]);
    }

    /** FR-JDW-06 — jadwal mingguan milik pengguna yang sedang masuk. */
    public function mingguan(Request $request): JsonResponse
    {
        $pegawai = $this->pegawaiPengguna($request);
        $semester = $this->semesterDariPermintaan($request);

        return response()->json([
            'data' => ['hari' => $this->service->jadwalMingguan($pegawai, $semester)],
        ]);
    }

    /** FR-JDW-05 — ekspor jadwal (Excel). */
    public function ekspor(Request $request): Response
    {
        $semester = $this->semesterDariPermintaan($request);

        $kelasId = $request->filled('kelas_id') ? $request->integer('kelas_id') : null;
        $pegawaiId = $request->filled('pegawai_id') ? $request->integer('pegawai_id') : null;

        $grid = $this->service->grid($semester, $kelasId, $pegawaiId);

        $judul = ['Hari', 'Jam', 'Jam Ke', 'Mata Pelajaran', 'Kelas', 'Guru'];
        $baris = [];

        foreach ($grid['hari'] as $hari) {
            foreach ($hari['slot'] as $slot) {
                if (! $slot['dapat_dijadwalkan']) {
                    continue;
                }

                if ($slot['isi'] === []) {
                    $baris[] = [$hari['nama_hari'], $slot['jam_mulai'].'-'.$slot['jam_selesai'], $slot['jam_ke'], '', '', ''];

                    continue;
                }

                foreach ($slot['isi'] as $isi) {
                    $baris[] = [
                        $hari['nama_hari'],
                        $slot['jam_mulai'].'-'.$slot['jam_selesai'],
                        $slot['jam_ke'],
                        $isi['mapel'],
                        $isi['kelas'],
                        $isi['guru'],
                    ];
                }
            }
        }

        return $this->excel->unduhXlsx('jadwal-pelajaran.xlsx', $judul, $baris);
    }

    /** True bila pengguna hanya berperan guru, sehingga dibatasi ke jadwal miliknya. */
    private function hanyaGuru(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return ! $user->punyaPeran(Role::ADMIN, Role::KEPALA_SEKOLAH, Role::WAKASEK_KURIKULUM);
    }

    private function pegawaiPengguna(Request $request): Pegawai
    {
        $pegawai = $request->user()?->pegawai;

        if ($pegawai === null) {
            throw AturanBisnisException::tolak(
                'Akun ini tidak terhubung ke data pegawai, sehingga tidak memiliki jadwal mengajar.',
                'pegawai_id',
                'TANPA_DATA_PEGAWAI',
            );
        }

        return $pegawai;
    }

    private function semesterDariPermintaan(Request $request): Semester
    {
        if ($request->filled('semester_id')) {
            $semester = Semester::find($request->integer('semester_id'));
            if ($semester !== null) {
                return $semester;
            }
        }

        return Semester::where('is_active', true)->first()
            ?? throw new NotFoundHttpException('Belum ada semester aktif.');
    }
}
