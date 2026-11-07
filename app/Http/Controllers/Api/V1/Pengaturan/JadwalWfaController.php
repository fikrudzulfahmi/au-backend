<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pengaturan;

use App\Http\Controllers\Concerns\MemakaiPegawai;
use App\Http\Controllers\Controller;
use App\Http\Requests\JadwalWfaRequest;
use App\Models\Pegawai;
use App\Services\JadwalWfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-WFA — CRUD jadwal Work From Anywhere per pegawai.
 * Hanya admin yang boleh mengelola; guru melihat statusnya lewat beranda.
 */
final class JadwalWfaController extends Controller
{
    use MemakaiPegawai;

    public function __construct(private readonly JadwalWfaService $wfa) {}

    /** Daftar pegawai yang punya jadwal WFA. */
    public function index(Request $request): JsonResponse
    {
        $jenis = $request->string('jenis_pegawai')->toString() ?: null;
        $nama = $request->string('nama')->toString() ?: null;

        return response()->json([
            'data' => $this->wfa->daftar($jenis, $nama),
        ]);
    }

    /** Jadwal WFA satu pegawai (7 hari). */
    public function show(Pegawai $pegawai): JsonResponse
    {
        return response()->json([
            'data' => $this->wfa->sepekan($pegawai->getKey()),
            'pegawai' => [
                'id' => $pegawai->getKey(),
                'nip' => $pegawai->nip,
                'nama' => $pegawai->nama,
                'jenis_pegawai' => $pegawai->jenis_pegawai,
            ],
        ]);
    }

    /** Simpan sepekan jadwal WFA satu pegawai. */
    public function store(JadwalWfaRequest $request): JsonResponse
    {
        $pegawai = Pegawai::findOrFail($request->validated('pegawai_id'));

        $this->wfa->simpanMassal($pegawai, $request->validated('per_hari'), $request->user());

        return response()->json([
            'message' => 'Jadwal WFA untuk '.$pegawai->nama.' berhasil disimpan.',
            'data' => $this->wfa->sepekan($pegawai->getKey()),
        ]);
    }

    /** Hapus satu hari WFA. */
    public function destroy(Request $request, Pegawai $pegawai, int $hari): JsonResponse
    {
        $this->wfa->hapus($pegawai, $hari, $request->user());

        return response()->json([
            'message' => 'Jadwal WFA hari '.($hari).' untuk '.$pegawai->nama.' dihapus.',
        ]);
    }
}
