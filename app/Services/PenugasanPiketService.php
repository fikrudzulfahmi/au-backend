<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AturanBisnisException;
use App\Models\Pegawai;
use App\Models\PenugasanPiket;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Penugasan guru piket: menyimpan, mengubah, dan menghapus penugasan harian.
 *
 * Aturan yang ditegakkan di sini:
 *  - satu guru hanya sekali per (semester, hari) — dijaga indeks unik database,
 *    diterjemahkan menjadi pesan yang bisa dibaca admin, bukan 500;
 *  - setiap perubahan dicatat ke audit_log (kebiasaan repo ini untuk seluruh
 *    data yang memengaruhi hak akses).
 */
final class PenugasanPiketService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** @param array<string, mixed> $data */
    public function simpan(array $data, ?PenugasanPiket $ada = null, ?User $oleh = null): PenugasanPiket
    {
        $this->pastikanBerperanGuru((int) $data['pegawai_id']);

        return DB::transaction(function () use ($data, $ada, $oleh): PenugasanPiket {
            $baru = $ada === null;
            $target = $ada ?? new PenugasanPiket;

            $target->fill([
                'semester_id' => (int) $data['semester_id'],
                'pegawai_id' => (int) $data['pegawai_id'],
                'hari' => (int) $data['hari'],
                'catatan' => $data['catatan'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            try {
                $target->save();
            } catch (QueryException $e) {
                if ($this->galatDuplikat($e)) {
                    throw AturanBisnisException::konflik(
                        'Guru ini sudah ditugaskan piket pada hari yang sama di semester ini.',
                        'PIKET_SUDAH_ADA',
                    );
                }

                throw $e;
            }

            $this->audit->catat(
                $baru ? AuditLogService::AKSI_BUAT : AuditLogService::AKSI_UBAH,
                $oleh,
                $target,
                null,
                ['pegawai_id' => $target->pegawai_id, 'hari' => $target->namaHari()],
            );

            return $target->load(['pegawai:id,nip,nama', 'semester']);
        });
    }

    public function hapus(PenugasanPiket $piket, ?User $oleh = null): void
    {
        DB::transaction(function () use ($piket, $oleh): void {
            $this->audit->catat(AuditLogService::AKSI_HAPUS, $oleh, $piket, [
                'pegawai_id' => $piket->pegawai_id,
                'hari' => $piket->namaHari(),
            ], null);

            $piket->delete();
        });
    }

    /**
     * Piket berarti menulis jurnal, dan endpoint jurnal hanya terbuka untuk peran
     * guru (dan admin). Menetapkan pegawai tanpa peran itu akan tampak berhasil
     * tetapi tidak memberi hak apa pun, jadi ditolak di depan dengan pesan yang
     * menjelaskan sebabnya.
     */
    private function pastikanBerperanGuru(int $pegawaiId): void
    {
        $pegawai = Pegawai::query()->with('user.roles')->find($pegawaiId);

        if ($pegawai?->user === null || ! $pegawai->user->punyaPeran(Role::GURU)) {
            throw AturanBisnisException::tolak(
                'Pegawai ini belum memiliki akun dengan peran guru, sehingga tidak dapat diberi penugasan piket.',
                'pegawai_id',
                'PIKET_TANPA_PERAN_GURU',
            );
        }
    }

    /**
     * Kode galat duplikat berbeda antar basis data: MySQL 1062, SQLite 19.
     * Aplikasi ini berjalan di SQLite, sedangkan migrasi/uji memakai keduanya,
     * jadi keduanya diperiksa.
     */
    private function galatDuplikat(QueryException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1062, 19], true);
    }
}
