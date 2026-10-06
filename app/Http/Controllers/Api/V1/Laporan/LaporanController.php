<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Laporan;

use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Semester;
use App\Services\Laporan\DokumenResmiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 5.14 — kelas dasar seluruh endpoint laporan.
 *
 * Menyatukan tiga hal yang wajib sama pada semua laporan:
 *  1. bentuk respons (JSON untuk web, PDF/Excel lewat `?format=`);
 *  2. pemilihan semester/tahun pelajaran (FR-LAP-11, default aktif);
 *  3. pembatasan data per peran (FR-LAP-12) — `L(S)` dibatasi ke dirinya sendiri.
 *
 * Otorisasi peran dasar tetap dijaga middleware `peran`; pembatasan L(S) yang
 * bergantung pada data (bukan sekadar peran) ditegakkan di sini.
 */
abstract class LaporanController extends Controller
{
    use MemakaiPegawai;

    public function __construct(protected readonly DokumenResmiService $dokumen) {}

    /**
     * Menyajikan hasil laporan sesuai `?format=`: json (default), pdf, atau excel.
     *
     * @param  array<string, mixed>  $hasil
     */
    protected function sajikan(array $hasil, Request $request, string $orientasi = 'portrait'): JsonResponse|Response
    {
        return match ($this->format($request)) {
            'pdf' => $this->dokumen->pdf(
                $hasil,
                $this->idPenandatangan($request),
                $this->dokumen->namaBerkas((string) $hasil['judul'], 'pdf'),
                $orientasi,
            ),
            'excel' => $this->dokumen->xlsx(
                $hasil,
                $this->dokumen->namaBerkas((string) $hasil['judul'], 'xlsx'),
            ),
            default => response()->json(['data' => $hasil]),
        };
    }

    protected function format(Request $request): string
    {
        $format = strtolower((string) $request->query('format', 'json'));

        return match ($format) {
            'xlsx' => 'excel',
            'json', 'pdf', 'excel' => $format,
            default => 'json',
        };
    }

    /**
     * FR-KOP-03 — penandatangan yang dipilih untuk dokumen ini (maksimal 2).
     *
     * @return list<int>|null
     */
    protected function idPenandatangan(Request $request): ?array
    {
        $data = $request->validate([
            'penandatangan_ids' => ['nullable', 'array', 'max:'.DokumenResmiService::MAKS_PENANDATANGAN],
            'penandatangan_ids.*' => ['integer'],
        ], [
            'penandatangan_ids.max' => 'Maksimal 2 penandatangan per dokumen.',
        ]);

        return $data['penandatangan_ids'] ?? null;
    }

    /**
     * Validasi bersama: periode, rentang tanggal, dan semester terpilih.
     *
     * @param  array<string, array<int, string>>  $tambahan
     * @return array<string, mixed>
     */
    protected function validasiPeriode(Request $request, array $tambahan = []): array
    {
        return $request->validate(array_merge([
            'periode' => ['nullable', 'in:hari_ini,minggu_ini,bulan_ini,rentang'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
            'semester_id' => ['nullable', 'integer', 'exists:semester,id'],
        ], $tambahan));
    }

    /** FR-LAP-11 — laporan memakai semester/tahun pelajaran yang dipilih (default aktif). */
    protected function semesterTerpilih(Request $request): Semester
    {
        $id = $request->query('semester_id');

        $semester = $id !== null
            ? Semester::query()->find((int) $id)
            : Semester::query()->where('is_active', true)->first();

        if ($semester === null) {
            throw AturanBisnisException::tolak('Belum ada semester aktif.', 'semester', 'TANPA_SEMESTER_AKTIF');
        }

        return $semester->load('tahunPelajaran');
    }

    /**
     * FR-LAP-12 / matriks Bagian 2 — peran pemantau (admin/kepsek/wakasek) melihat
     * seluruh pegawai; guru & pegawai struktural hanya dirinya sendiri (L(S)).
     *
     * @return int|null null berarti tidak dibatasi
     */
    protected function batasPegawai(Request $request): ?int
    {
        if ($this->penggunaMemantau($request)) {
            return null;
        }

        $pegawai = $request->user()?->pegawai;

        if ($pegawai === null) {
            throw new AturanBisnisException(
                'Akun ini tidak terhubung ke data pegawai, sehingga laporan tidak dapat dibuka.',
                'AKSES_DITOLAK',
                403,
            );
        }

        return (int) $pegawai->getKey();
    }

    /** Memastikan pengguna hanya membuka data miliknya sendiri (L(S)). */
    protected function pastikanBolehPegawai(Request $request, int $pegawaiId): void
    {
        $batas = $this->batasPegawai($request);

        if ($batas !== null && $batas !== $pegawaiId) {
            throw new AturanBisnisException(
                'Anda hanya dapat melihat laporan data Anda sendiri.',
                'AKSES_DITOLAK',
                403,
            );
        }
    }

    protected function punyaPeran(Request $request, string ...$peran): bool
    {
        return $request->user()?->punyaPeran(...$peran) ?? false;
    }

    protected function adalahAdmin(Request $request): bool
    {
        return $this->punyaPeran($request, Role::ADMIN);
    }
}
