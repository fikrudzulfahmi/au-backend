<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ImportMasterRequest;
use App\Http\Requests\Master\PegawaiRequest;
use App\Http\Resources\PegawaiResource;
use App\Models\Pegawai;
use App\Services\AuditLogService;
use App\Services\EksporMasterService;
use App\Services\ImportMasterService;
use App\Services\PegawaiService;
use App\Support\ResponsDaftar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-PEG-01..06 — guru & pegawai struktural, akun, reset password, reset perangkat.
 * KP-1.4: pembuatan pegawai sekaligus membuat akun dan peran sesuai jenis_pegawai.
 */
class PegawaiController extends Controller
{
    public function __construct(
        private readonly PegawaiService $service,
        private readonly ImportMasterService $import,
        private readonly EksporMasterService $ekspor,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ResponsDaftar::buat(
            $this->query($request)->with(['user.roles', 'user.perangkat'])->paginate($this->perHalaman($request)),
            PegawaiResource::class
        );
    }

    public function store(PegawaiRequest $request): JsonResponse
    {
        $data = $request->validated();
        $buatAkun = (bool) ($data['buat_akun'] ?? true);
        unset($data['buat_akun']);

        // Akun dibuat terpisah agar password awalnya dapat dilaporkan SEKALI ke admin
        // (tidak tersimpan dalam bentuk terbaca di mana pun).
        $pegawai = $this->service->buat($data, buatAkun: false);

        $passwordAwal = null;
        if ($buatAkun) {
            $hasilAkun = $this->service->buatAkun($pegawai);
            $passwordAwal = $hasilAkun['password_awal'];
        }

        $this->audit->catat(AuditLogService::AKSI_BUAT, $request->user(), $pegawai, null, [
            'nip' => $pegawai->nip, 'nama' => $pegawai->nama, 'akun' => $buatAkun,
        ]);

        return response()->json([
            'message' => $buatAkun
                ? 'Pegawai dan akun login berhasil dibuat. Password awal wajib diganti saat login pertama.'
                : 'Pegawai berhasil dibuat tanpa akun login.',
            'data' => new PegawaiResource($pegawai->load('user.roles')),
            'password_awal' => $passwordAwal,
        ], 201);
    }

    public function show(Pegawai $pegawai): JsonResponse
    {
        return response()->json([
            'data' => new PegawaiResource($pegawai->load(['user.roles', 'user.perangkat'])),
        ]);
    }

    public function update(PegawaiRequest $request, Pegawai $pegawai): JsonResponse
    {
        $data = $request->validated();
        unset($data['buat_akun']);

        $lama = $pegawai->only(['nip', 'nama', 'jenis_pegawai', 'jabatan', 'status_kepegawaian', 'is_active']);
        $pegawai = $this->service->perbarui($pegawai, $data);

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $pegawai, $lama, $data);

        return response()->json(['data' => new PegawaiResource($pegawai->load('user.roles'))]);
    }

    public function destroy(Request $request, Pegawai $pegawai): JsonResponse
    {
        $this->audit->catat(AuditLogService::AKSI_HAPUS, $request->user(), $pegawai, $pegawai->toArray(), null);
        $this->service->hapus($pegawai);

        return response()->json(['message' => 'Pegawai dinonaktifkan dan dihapus dari daftar aktif.']);
    }

    /** FR-PEG-03 / KP-1.3 — import; baris gagal dilaporkan tanpa menggagalkan baris valid. */
    public function import(ImportMasterRequest $request): JsonResponse
    {
        try {
            $hasil = $this->import->importPegawai(
                $request->file('berkas'),
                (bool) $request->boolean('buat_akun', true),
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => 'Berkas tidak dapat dibaca: '.$e->getMessage(),
                'code' => 'BERKAS_TIDAK_VALID',
            ], 422);
        }

        $this->audit->catat(AuditLogService::AKSI_IMPORT, $request->user(), null, null, [
            'modul' => 'pegawai', 'berhasil' => $hasil['berhasil'], 'gagal' => $hasil['gagal'],
        ]);

        $pesan = $hasil['gagal'] === 0
            ? "{$hasil['berhasil']} pegawai berhasil diimport."
            : "{$hasil['berhasil']} pegawai berhasil, {$hasil['gagal']} baris gagal.";

        return response()->json(['message' => $pesan, 'data' => $hasil]);
    }

    public function ekspor(Request $request): Response
    {
        if ($request->string('format', 'xlsx')->toString() !== 'xlsx') {
            abort(422, 'Format ekspor yang tersedia saat ini hanya xlsx. Ekspor PDF menyusul pada Fase 5.');
        }

        return $this->ekspor->pegawai($this->query($request));
    }

    public function template(): Response
    {
        return $this->ekspor->templatePegawai();
    }

    /** FR-PEG-06 — reset password oleh admin. */
    public function resetPassword(Request $request, Pegawai $pegawai): JsonResponse
    {
        if (! $pegawai->user) {
            return response()->json([
                'message' => 'Pegawai ini belum memiliki akun login.',
                'code' => 'TANPA_AKUN',
            ], 409);
        }

        $passwordBaru = $this->service->resetPassword($pegawai->user);

        return response()->json([
            'message' => 'Password direset. Sampaikan password baru kepada yang bersangkutan; wajib diganti saat login.',
            'data' => ['username' => $pegawai->user->username, 'password_baru' => $passwordBaru],
        ]);
    }

    /** FR-PEG-05 / BR-14 — reset perangkat terdaftar. */
    public function resetPerangkat(Request $request, Pegawai $pegawai): JsonResponse
    {
        if (! $pegawai->user) {
            return response()->json([
                'message' => 'Pegawai ini belum memiliki akun login.',
                'code' => 'TANPA_AKUN',
            ], 409);
        }

        $this->service->resetPerangkat($pegawai->user);

        return response()->json([
            'message' => 'Perangkat terdaftar dikosongkan. Perangkat berikutnya yang login akan didaftarkan.',
        ]);
    }

    /** FR-PEG-02 — membuat akun untuk pegawai yang belum punya. */
    public function buatAkun(Request $request, Pegawai $pegawai): JsonResponse
    {
        $hasil = $this->service->buatAkun($pegawai);

        return response()->json([
            'message' => $hasil['password_awal']
                ? 'Akun dibuat. Sampaikan password awal kepada yang bersangkutan; wajib diganti saat login pertama.'
                : 'Pegawai ini sudah memiliki akun.',
            'data' => [
                'username' => $hasil['user']->username,
                'peran' => $hasil['user']->kodePeran(),
                'password_awal' => $hasil['password_awal'],
            ],
        ], $hasil['password_awal'] ? 201 : 200);
    }

    /** @return Builder<Pegawai> */
    private function query(Request $request): Builder
    {
        return Pegawai::query()
            ->when($request->filled('jenis_pegawai'), fn ($q) => $q->where('jenis_pegawai', $request->string('jenis_pegawai')))
            ->when($request->filled('status_kepegawaian'), fn ($q) => $q->where('status_kepegawaian', $request->string('status_kepegawaian')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->where(fn ($w) => $w->where('nip', 'like', $cari)->orWhere('nama', 'like', $cari)->orWhere('jabatan', 'like', $cari));
            })
            ->orderBy('nama');
    }
}
