<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Data pengguna untuk /auth/me dan /auth/login (3.4 — satu objek: { data: {...} }). */
class PenggunaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pegawai = $this->pegawai;

        return [
            'id' => $this->id,
            'username' => $this->username,
            'nama' => $this->namaTampil(),
            'peran' => $this->kodePeran(),
            'pegawai_id' => $this->pegawai_id,
            'jenis_pegawai' => $pegawai?->jenis_pegawai,
            'jabatan' => $pegawai?->jabatan,
            'label_jabatan' => $pegawai?->labelJabatan(),
            'status_kepegawaian' => $pegawai?->status_kepegawaian,
            'nip' => $pegawai?->nip,
            'foto_url' => null,
            'wajib_ganti_password' => (bool) $this->wajib_ganti_password,
        ];
    }
}
