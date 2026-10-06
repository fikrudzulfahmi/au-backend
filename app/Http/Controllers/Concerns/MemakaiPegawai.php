<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\AturanBisnisException;
use App\Models\Pegawai;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Pintasan bersama untuk endpoint milik pegawai sendiri (presensi, pengajuan).
 */
trait MemakaiPegawai
{
    /** Pegawai yang terhubung dengan akun yang sedang masuk. */
    protected function pegawaiSendiri(Request $request): Pegawai
    {
        $pegawai = $this->pengguna($request)->pegawai;

        if ($pegawai === null) {
            throw AturanBisnisException::tolak(
                'Akun ini tidak terhubung ke data pegawai, sehingga presensi tidak dapat dilakukan.',
                'pegawai',
            );
        }

        return $pegawai;
    }

    protected function pengguna(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** Benar bila pengguna memiliki salah satu peran pemantau (admin/kepsek/wakasek). */
    protected function penggunaMemantau(Request $request): bool
    {
        $user = $this->pengguna($request);

        return $user->punyaPeran(Role::ADMIN)
            || $user->punyaPeran(Role::KEPALA_SEKOLAH)
            || $user->punyaPeran(Role::WAKASEK_KURIKULUM);
    }
}
