<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Presensi;

use App\Http\Controllers\Controller;
use App\Models\KecualiWfa;
use App\Models\Pegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KecualiWfaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tanggal = $request->date('tanggal')?->toDateString();
        $pegawaiId = $request->integer('pegawai_id') ?: null;

        $rows = KecualiWfa::query()
            ->with('pegawai:id,nip,nama')
            ->when($tanggal, fn ($q) => $q->whereDate('tanggal', $tanggal))
            ->when($pegawaiId, fn ($q) => $q->where('pegawai_id', $pegawaiId))
            ->orderBy('tanggal')->orderBy('id')->get()
            ->map(fn (KecualiWfa $row): array => $this->format($row));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pegawai_id' => ['required', 'integer', 'exists:pegawai,id'],
            'tanggal' => ['required', 'date'],
            'jenis' => ['required', 'in:masuk_kantor,dinas,izin,cuti'],
            'alasan' => ['nullable', 'string', 'max:1000'],
        ]);

        $pegawai = Pegawai::query()->whereKey($data['pegawai_id'])->where('is_active', true)->firstOrFail();
        $row = KecualiWfa::updateOrCreate(
            ['pegawai_id' => $pegawai->id, 'tanggal' => $data['tanggal']],
            [...$data, 'dibuat_oleh' => $request->user()->id],
        );

        return response()->json(['message' => 'Kecualian WFA disimpan.', 'data' => $this->format($row->load('pegawai'))], 201);
    }

    public function destroy(Request $request, KecualiWfa $kecualiWfa): JsonResponse
    {
        $kecualiWfa->delete();
        return response()->json(['message' => 'Kecualian WFA dihapus.']);
    }

    private function format(KecualiWfa $row): array
    {
        return [
            'id' => $row->id,
            'pegawai_id' => $row->pegawai_id,
            'pegawai_nama' => $row->pegawai?->nama,
            'pegawai_nip' => $row->pegawai?->nip,
            'tanggal' => $row->tanggal->toDateString(),
            'nama_hari' => $row->tanggal->locale('id')->dayName,
            'jenis' => $row->jenis,
            'alasan' => $row->alasan ?? '',
            'dibuat_pada' => $row->created_at?->toIso8601String(),
        ];
    }
}
