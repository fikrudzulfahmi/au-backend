<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 7.2 — pegawai. Password dan hash token TIDAK pernah ikut; yang disertakan
 * hanya ringkasan akun (username, peran, wajib ganti password).
 */
class PegawaiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nip' => $this->nip,
            'nama' => $this->nama,
            'jenis_kelamin' => $this->jenis_kelamin,
            'jenis_pegawai' => $this->jenis_pegawai,
            'jabatan' => $this->jabatan,
            'label_jabatan' => $this->labelJabatan(),
            'status_kepegawaian' => $this->status_kepegawaian,
            'email' => $this->email,
            'no_hp' => $this->no_hp,
            'tanggal_lahir' => $this->tanggal_lahir?->format('Y-m-d'),
            'is_active' => (bool) $this->is_active,
            'akun' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'peran' => $this->user->kodePeran(),
                'is_active' => (bool) $this->user->is_active,
                'wajib_ganti_password' => (bool) $this->user->wajib_ganti_password,
                'punya_perangkat' => $this->user->relationLoaded('perangkat')
                    ? $this->user->perangkat !== null
                    : null,
            ]),
        ];
    }
}
