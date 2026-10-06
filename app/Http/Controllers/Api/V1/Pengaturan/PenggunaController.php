<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pengaturan;

use App\Http\Controllers\Controller;
use App\Http\Resources\PenggunaResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PegawaiService;
use App\Support\ResponsDaftar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 5.16 / Bagian 2 — pengelolaan akun dan peran (A-11: satu akun boleh
 * memiliki beberapa peran).
 */
class PenggunaController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly PegawaiService $pegawaiService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ResponsDaftar::buat(
            $this->query($request)->with(['roles', 'pegawai'])->paginate($this->perHalaman($request)),
            PenggunaResource::class
        );
    }

    /** Daftar peran yang tersedia (Bagian 2). */
    public function peran(): JsonResponse
    {
        return response()->json([
            'data' => Role::query()->orderBy('id')->get(['id', 'kode', 'nama'])
                ->map(fn (Role $r): array => ['id' => $r->id, 'kode' => $r->kode, 'nama' => $r->nama]),
        ]);
    }

    /** Mengubah peran akun dan status aktifnya. */
    public function update(Request $request, User $pengguna): JsonResponse
    {
        $data = $request->validate([
            'peran' => ['sometimes', 'array', 'min:1'],
            'peran.*' => [Rule::exists('roles', 'kode')],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'peran.min' => 'Akun harus memiliki minimal satu peran.',
            'peran.*.exists' => 'Peran tidak dikenali.',
        ]);

        $lama = ['peran' => $pengguna->kodePeran(), 'is_active' => (bool) $pengguna->is_active];

        if (array_key_exists('peran', $data)) {
            $idPeran = Role::query()->whereIn('kode', $data['peran'])->pluck('id')->all();
            $pengguna->roles()->sync($idPeran);
        }

        if (array_key_exists('is_active', $data)) {
            $pengguna->forceFill(['is_active' => (bool) $data['is_active']])->save();
        }

        $this->audit->catat(AuditLogService::AKSI_UBAH, $request->user(), $pengguna, $lama, [
            'peran' => $pengguna->refresh()->kodePeran(),
            'is_active' => (bool) $pengguna->is_active,
        ]);

        return response()->json([
            'message' => 'Akun diperbarui.',
            'data' => new PenggunaResource($pengguna->load('roles', 'pegawai')),
        ]);
    }

    /** FR-PEG-05 — reset perangkat akun langsung dari halaman pengguna. */
    public function resetPerangkat(Request $request, User $pengguna): JsonResponse
    {
        $this->pegawaiService->resetPerangkat($pengguna);

        return response()->json(['message' => 'Perangkat terdaftar akun ini dikosongkan.']);
    }

    /** @return Builder<User> */
    private function query(Request $request): Builder
    {
        return User::query()
            ->when($request->filled('cari'), function ($q) use ($request): void {
                $cari = '%'.$request->string('cari').'%';
                $q->where(fn ($w) => $w->where('username', 'like', $cari)->orWhere('name', 'like', $cari));
            })
            ->when($request->filled('peran'), function ($q) use ($request): void {
                $q->whereHas('roles', fn ($r) => $r->where('kode', $request->string('peran')));
            })
            ->orderBy('username');
    }
}
