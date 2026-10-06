<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Jurnal;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Http\Requests\IsiJurnalRequest;
use App\Http\Resources\JurnalResource;
use App\Http\Resources\SemesterResource;
use App\Models\Jurnal;
use App\Models\JurnalFoto;
use App\Models\Kelas;
use App\Models\Role;
use App\Models\Semester;
use App\Services\JurnalService;
use App\Support\ResponsDaftar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 5.13 — jurnal pembelajaran + presensi siswa (FR-JRN-01..10).
 *
 * Otorisasi mengikuti matriks Bagian 2 baris "Jurnal + presensi siswa":
 *   admin = K** (boleh mengoreksi, tercatat di audit_log)
 *   guru  = K(S), hanya sesi pada jadwalnya sendiri
 *   kepala_sekolah & wakasek_kurikulum = tidak ada akses ke endpoint ini
 *     (mereka melihatnya lewat laporan pada fase berikutnya)
 */
final class JurnalController extends Controller
{
    use MemakaiPegawai;

    public function __construct(private readonly JurnalService $jurnal) {}

    /** FR-JRN-01 — sesi hari ini untuk beranda guru, lengkap dengan status jurnalnya. */
    public function sesiHariIni(Request $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);
        $semester = $this->semesterAktif();

        $tanggal = (string) ($request->query('tanggal') ?: now()->toDateString());
        $sesi = $this->jurnal->sesi($pegawai, $semester, $tanggal);

        return response()->json([
            'data' => [
                'tanggal' => $tanggal,
                'semester' => new SemesterResource($semester),
                'sesi' => $sesi,
                'ringkasan' => [
                    'total' => count($sesi),
                    'sudah' => collect($sesi)->where('status', JurnalService::SUDAH)->count(),
                    'belum' => collect($sesi)->where('status', JurnalService::BELUM)->count(),
                    'berhalangan' => collect($sesi)->where('status', JurnalService::BERHALANGAN)->count(),
                ],
            ],
        ]);
    }

    /** FR-JRN-08 / BR-22 — daftar siswa kelas untuk halaman isi jurnal. */
    public function siswaKelas(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kelas_id' => ['required', 'integer', 'exists:kelas,id'],
            'semester_id' => ['nullable', 'integer', 'exists:semester,id'],
        ]);

        $semester = isset($data['semester_id'])
            ? Semester::query()->findOrFail((int) $data['semester_id'])
            : $this->semesterAktif();

        $daftar = $this->jurnal->siswaKelas($semester, (int) $data['kelas_id']);

        return response()->json([
            'data' => $daftar->map(fn ($p): array => [
                'siswa_id' => (int) $p->siswa_id,
                'nis' => $p->siswa?->nis,
                'nama' => $p->siswa?->nama,
                'jenis_kelamin' => $p->siswa?->jenis_kelamin,
                'status' => 'H',
                'keterangan' => null,
            ])->all(),
        ]);
    }

    /** FR-JRN-09 — riwayat jurnal milik sendiri dengan filter periode/kelas/mapel. */
    public function index(Request $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $filter = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
            'kelas_id' => ['nullable', 'integer'],
            'mapel_id' => ['nullable', 'integer'],
        ]);

        $halaman = $this->jurnal->riwayat($pegawai, $filter, $this->perHalaman($request));

        return ResponsDaftar::buat($halaman, JurnalResource::class);
    }

    /** Membuat jurnal satu sesi (BR-19, BR-21, KP-4.6). */
    public function simpan(IsiJurnalRequest $request): JsonResponse
    {
        $pegawai = $this->pegawaiSendiri($request);

        $jurnal = $this->jurnal->buat(
            $request->user(),
            $pegawai,
            $request->validated(),
            $request->file('foto') ?? [],
        );

        return response()->json([
            'message' => 'Jurnal berhasil disimpan.',
            'data' => new JurnalResource($jurnal->load(['kelas', 'plottingMapel.mapel', 'foto'])),
        ], 201);
    }

    /** Detail satu jurnal, termasuk presensi siswanya. */
    public function tampil(Request $request, Jurnal $jurnal): JsonResponse
    {
        $this->pastikanBolehAkses($request, $jurnal);

        $jurnal->load(['kelas', 'plottingMapel.mapel', 'foto', 'presensiSiswa.siswa:id,nis,nama,jenis_kelamin']);

        return response()->json([
            'data' => [
                ...(new JurnalResource($jurnal))->toArray($request),
                'presensi_siswa' => JurnalResource::presensiLengkap($jurnal),
                'foto' => $jurnal->foto->map(fn (JurnalFoto $f): array => [
                    'id' => $f->id,
                    'urutan' => (int) $f->urutan,
                ])->all(),
            ],
        ]);
    }

    /** FR-JRN-03 — presensi siswa sebuah jurnal saja. */
    public function presensiSiswa(Request $request, Jurnal $jurnal): JsonResponse
    {
        $this->pastikanBolehAkses($request, $jurnal);

        $jurnal->load('presensiSiswa.siswa:id,nis,nama,jenis_kelamin');

        return response()->json(['data' => JurnalResource::presensiLengkap($jurnal)]);
    }

    /** BR-20 / FR-JRN-05 — edit kapan pun oleh pemilik; admin boleh mengoreksi. */
    public function perbarui(IsiJurnalRequest $request, Jurnal $jurnal): JsonResponse
    {
        $this->pastikanBolehAkses($request, $jurnal);

        $diperbarui = $this->jurnal->perbarui(
            $request->user(),
            $jurnal,
            $request->validated(),
            $request->file('foto'),
        );

        return response()->json([
            'message' => 'Jurnal berhasil diperbarui.',
            'data' => new JurnalResource($diperbarui->load(['kelas', 'plottingMapel.mapel', 'foto'])),
        ]);
    }

    /**
    /**
     * FR-JRN-10 — daftar kelas yang dapat direkap pengguna ini.
     *
     * Endpoint ini ada karena master `/kelas` hanya untuk admin/kepsek/wakasek
     * (matriks Bagian 2), sehingga guru yang menjadi wali kelas TIDAK bisa memakai
     * endpoint itu untuk menemukan kelasnya (ditemukan lewat uji asap: 403).
     * Guru menerima kelas walinya saja; admin menerima seluruh kelas tahun aktif.
     */
    public function kelasWali(Request $request): JsonResponse
    {
        $semester = $this->semesterAktif();

        $query = Kelas::query()
            ->where('tahun_pelajaran_id', $semester->tahun_pelajaran_id)
            ->orderBy('nama');

        if (! $this->pengguna($request)->punyaPeran(Role::ADMIN)) {
            $pegawai = $this->pegawaiSendiri($request);
            $query->where('wali_kelas_id', $pegawai->id);
        }

        return response()->json([
            'data' => $query->get(['id', 'nama', 'tingkat'])->map(fn ($k): array => [
                'id' => (int) $k->id,
                'nama' => $k->nama,
                'tingkat' => $k->tingkat,
            ])->all(),
        ]);
    }

    /** FR-JRN-10 — rekap presensi siswa satu kelas pada rentang periode.
     * Hanya wali kelas tersebut (lihat kelasnya sendiri) dan admin.
     */
    public function rekapSiswa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kelas_id' => ['required', 'integer', 'exists:kelas,id'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $this->pastikanBolehRekapKelas($request, (int) $data['kelas_id']);

        $semester = $this->semesterAktif();
        $hasil = $this->jurnal->rekapPresensiSiswa(
            $semester,
            (int) $data['kelas_id'],
            $data['dari'] ?? null,
            $data['sampai'] ?? null,
        );

        return response()->json([
            'data' => [
                'semester' => new SemesterResource($semester),
                'kelas' => Kelas::query()->find((int) $data['kelas_id'])?->nama,
                'dari' => $data['dari'] ?? null,
                'sampai' => $data['sampai'] ?? null,
                'sesi' => $hasil['sesi'],
                'ringkasan' => $hasil['ringkasan'],
                'siswa' => $hasil['siswa'],
            ],
        ]);
    }

    /** Menyajikan foto kegiatan jurnal (berkas privat, bukan tautan langsung). */
    public function foto(Request $request, JurnalFoto $foto): BinaryFileResponse|JsonResponse
    {
        $this->pastikanBolehAkses($request, $foto->jurnal);

        $berkas = Storage::disk('local')->path($foto->foto_path);

        // Retensi dapat membuang berkasnya lebih dulu; itu keadaan normal, bukan galat server.
        if (! is_file($berkas)) {
            return response()->json([
                'message' => 'Berkas foto sudah tidak tersedia karena masa simpan berakhir.',
            ], 404);
        }

        return response()->file($berkas);
    }

    // =====================================================================
    // Otorisasi
    // =====================================================================

    /** Guru hanya jurnalnya sendiri; admin boleh semuanya (matriks: K**). */
    private function pastikanBolehAkses(Request $request, ?Jurnal $jurnal): void
    {
        if ($jurnal === null) {
            throw AturanBisnisException::tolak('Jurnal tidak ditemukan.', 'jurnal', 'JURNAL_TIDAK_ADA');
        }

        if ($this->pengguna($request)->punyaPeran(Role::ADMIN)) {
            return;
        }

        $pegawai = $this->pegawaiSendiri($request);

        if ((int) $jurnal->pegawai_id !== (int) $pegawai->id) {
            throw new AturanBisnisException('Anda tidak berhak atas jurnal ini.', 'AKSES_DITOLAK', 403);
        }
    }

    /** FR-JRN-10 — wali kelas (kelas wali pada tahun pelajaran aktif) atau admin. */
    private function pastikanBolehRekapKelas(Request $request, int $kelasId): void
    {
        if ($this->pengguna($request)->punyaPeran(Role::ADMIN)) {
            return;
        }

        $pegawai = $this->pegawaiSendiri($request);
        $semester = $this->semesterAktif();

        $wali = Kelas::query()
            ->whereKey($kelasId)
            ->where('tahun_pelajaran_id', $semester->tahun_pelajaran_id)
            ->where('wali_kelas_id', $pegawai->id)
            ->exists();

        if (! $wali) {
            throw new AturanBisnisException(
                'Rekap presensi siswa hanya untuk kelas yang Anda ampu sebagai wali kelas.',
                'AKSES_DITOLAK',
                403,
            );
        }
    }

    private function semesterAktif(): Semester
    {
        $semester = Semester::query()->where('is_active', true)->first();

        if ($semester === null) {
            throw AturanBisnisException::tolak('Belum ada semester aktif.', 'semester', 'TANPA_SEMESTER_AKTIF');
        }

        return $semester;
    }
}
