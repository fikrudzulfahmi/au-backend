<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GantiPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\PenggunaResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Kelompok endpoint `auth` (3.4).
 * FR-SEC-01: login username + password, password di-hash, percobaan dibatasi.
 * A-14: Sanctum personal access token (Bearer).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('username', $data['username'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // FR-SEC-05 — login gagal dicatat di audit_log.
            $this->audit->catat(AuditLogService::AKSI_LOGIN_GAGAL, null, null, null, [
                'username' => $data['username'],
            ]);

            return response()->json([
                'message' => 'Username atau password salah.',
                'code' => 'KREDENSIAL_SALAH',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun Anda tidak aktif. Hubungi administrator sekolah.',
                'code' => 'AKUN_TIDAK_AKTIF',
            ], 403);
        }

        // Pergantian password mencabut token lama agar sesi lama tidak tetap berlaku.
        if ($user->wajib_ganti_password === false) {
            $user->tokens()->delete();
        }

        $token = $user->createToken('sipandu-'.$request->input('nama_perangkat', 'web'))->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->catat(AuditLogService::AKSI_LOGIN, $user);

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => new PenggunaResource($user->load('roles', 'pegawai')),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => new PenggunaResource($user->load('roles', 'pegawai')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->currentAccessToken()?->delete();

        $this->audit->catat(AuditLogService::AKSI_LOGOUT, $user);

        return response()->json([
            'message' => 'Anda telah keluar dari aplikasi.',
        ]);
    }

    /** FR-SEC-04 — pengguna mengganti password sendiri; password awal wajib diganti. */
    public function gantiPassword(GantiPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (! Hash::check($data['password_lama'], $user->password)) {
            return response()->json([
                'message' => 'Password lama tidak sesuai.',
                'errors' => ['password_lama' => ['Password lama tidak sesuai.']],
            ], 422);
        }

        $user->forceFill([
            'password' => $data['password'],
            'wajib_ganti_password' => false,
        ])->save();

        $this->audit->catat(AuditLogService::AKSI_GANTI_PASSWORD, $user);

        return response()->json([
            'message' => 'Password berhasil diganti.',
            'data' => new PenggunaResource($user->load('roles', 'pegawai')),
        ]);
    }
}
