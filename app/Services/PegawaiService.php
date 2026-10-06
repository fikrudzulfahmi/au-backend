<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pegawai;
use App\Models\PerangkatPengguna;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-PEG-01/02/05/06 — pengelolaan pegawai dan akunnya.
 * KP-1.4: pembuatan pegawai sekaligus membuat akun dengan peran sesuai
 * `jenis_pegawai` (guru → `guru`, struktural → `pegawai_struktural`).
 */
class PegawaiService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function buat(array $data, bool $buatAkun = true): Pegawai
    {
        return DB::transaction(function () use ($data, $buatAkun): Pegawai {
            /** @var Pegawai $pegawai */
            $pegawai = Pegawai::create($data);

            if ($buatAkun) {
                $this->buatAkun($pegawai);
            }

            return $pegawai->refresh()->load('user.roles');
        });
    }

    public function perbarui(Pegawai $pegawai, array $data): Pegawai
    {
        return DB::transaction(function () use ($pegawai, $data): Pegawai {
            $jenisSebelumnya = $pegawai->jenis_pegawai;

            $pegawai->update($data);

            // Peran mengikuti jenis_pegawai (KP-1.4), tanpa mencabut peran khusus
            // seperti kepala_sekolah / wakasek_kurikulum / admin (A-11).
            if ($jenisSebelumnya !== $pegawai->jenis_pegawai && $pegawai->user) {
                $this->selaraskanPeran($pegawai->user, $pegawai);
            }

            return $pegawai->refresh()->load('user.roles');
        });
    }

    /**
     * FR-PEG-02 — membuat akun login: username = NIP/ID internal, password awal
     * acak yang wajib diganti saat login pertama (FR-SEC-04).
     *
     * @return array{user: User, password_awal: string|null} password_awal hanya
     *                                                       diisi saat akun baru dibuat, agar admin dapat menyerahkannya sekali.
     */
    public function buatAkun(Pegawai $pegawai): array
    {
        if ($pegawai->user) {
            return ['user' => $pegawai->user->load('roles'), 'password_awal' => null];
        }

        $passwordAwal = Str::password(12, symbols: false);

        /** @var User $user */
        $user = User::create([
            'pegawai_id' => $pegawai->getKey(),
            'name' => $pegawai->nama,
            'username' => $pegawai->nip,
            'password' => $passwordAwal,
            'wajib_ganti_password' => true,
            'is_active' => $pegawai->is_active,
        ]);

        $this->selaraskanPeran($user, $pegawai);

        return ['user' => $user->load('roles'), 'password_awal' => $passwordAwal];
    }

    /** FR-PEG-06 — reset password oleh admin; password baru dikembalikan sekali. */
    public function resetPassword(User $user): string
    {
        $passwordBaru = Str::password(12, symbols: false);

        $user->forceFill([
            'password' => $passwordBaru,
            'wajib_ganti_password' => true,
        ])->save();

        $user->tokens()->delete();

        $this->audit->catat(AuditLogService::AKSI_RESET_PASSWORD, null, $user);

        return $passwordBaru;
    }

    /** FR-PEG-05 / BR-14 — mengosongkan perangkat terdaftar milik pengguna. */
    public function resetPerangkat(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lama = $user->perangkat?->only(['token_hash', 'user_agent', 'terdaftar_pada']);

            PerangkatPengguna::query()->where('user_id', $user->getKey())->delete();
            $user->tokens()->delete();

            $this->audit->catat(AuditLogService::AKSI_RESET_PERANGKAT, null, $user, $lama, [
                'perangkat' => 'dikosongkan',
            ]);
        });
    }

    public function hapus(Pegawai $pegawai): void
    {
        DB::transaction(function () use ($pegawai): void {
            $pegawai->user?->forceFill(['is_active' => false])->save();
            $pegawai->delete(); // soft delete — riwayat tetap tersimpan
        });
    }

    /** Menetapkan peran dasar sesuai jenis_pegawai tanpa menghapus peran khusus. */
    private function selaraskanPeran(User $user, Pegawai $pegawai): void
    {
        $peranDasar = $pegawai->isGuru() ? Role::GURU : Role::PEGAWAI_STRUKTURAL;
        $peranKhusus = [Role::ADMIN, Role::KEPALA_SEKOLAH, Role::WAKASEK_KURIKULUM];

        $idDasar = Role::query()->where('kode', $peranDasar)->pluck('id')->all();
        $idKhusus = $user->roles()->whereIn('kode', $peranKhusus)->pluck('roles.id')->all();

        $peranLama = $user->roles()->whereIn('kode', [Role::GURU, Role::PEGAWAI_STRUKTURAL])->pluck('roles.id')->all();

        $user->roles()->sync(array_merge($idKhusus, $idDasar));

        // Catat pergantian peran dasar bila berbeda dari sebelumnya.
        if (array_diff($peranLama, $idDasar) !== []) {
            $this->audit->catat('ubah_peran_dasar', null, $user, ['peran' => $peranLama], ['peran' => $idDasar]);
        }
    }
}
