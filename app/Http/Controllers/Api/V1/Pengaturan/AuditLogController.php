<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pengaturan;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ResponsDaftar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-SEC-05 / Bagian 9 — audit_log hanya dapat dibaca.
 * Tidak ada endpoint ubah/hapus: tabel ini tidak dapat diubah dari UI.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ResponsDaftar::buat($this->query($request)->paginate($this->perHalaman($request)), AuditLogResource::class);
    }

    /** Daftar aksi yang pernah tercatat, untuk pilihan filter. */
    public function aksi(): JsonResponse
    {
        return response()->json([
            'data' => AuditLog::query()->select('aksi')->distinct()->orderBy('aksi')->pluck('aksi'),
        ]);
    }

    /** @return Builder<AuditLog> */
    private function query(Request $request): Builder
    {
        return AuditLog::query()
            ->with('user:id,name,pegawai_id')
            ->when($request->filled('aksi'), fn ($q) => $q->where('aksi', $request->string('aksi')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('dari'), fn ($q) => $q->whereDate('waktu', '>=', $request->date('dari')))
            ->when($request->filled('sampai'), fn ($q) => $q->whereDate('waktu', '<=', $request->date('sampai')))
            ->orderByDesc('waktu')
            ->orderByDesc('id');
    }
}
