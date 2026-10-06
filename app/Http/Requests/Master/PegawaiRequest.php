<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Models\Pegawai;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-PEG-01/02 — data pegawai; pembuatan akun dikendalikan `buat_akun`. */
class PegawaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('pegawai')?->getKey();

        return [
            'nip' => [
                'required', 'string', 'max:191',
                Rule::unique('pegawai', 'nip')->ignore($id)->withoutTrashed(),
            ],
            'nama' => ['required', 'string', 'max:191'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'jenis_pegawai' => ['required', Rule::in([Pegawai::JENIS_GURU, Pegawai::JENIS_STRUKTURAL])],
            'jabatan' => ['nullable', 'string', 'max:191'],
            'status_kepegawaian' => ['required', Rule::in(Pegawai::STATUS_KEPEGAWAIAN)],
            'email' => ['nullable', 'email', 'max:191'],
            'no_hp' => ['nullable', 'string', 'max:30'],
            'tanggal_lahir' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'buat_akun' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nip.required' => 'NIP / NUPTK / ID internal wajib diisi.',
            'nip.unique' => 'NIP tersebut sudah terdaftar.',
            'jenis_pegawai.in' => 'Jenis pegawai harus guru atau struktural.',
            'status_kepegawaian.in' => 'Status kepegawaian tidak dikenali.',
        ];
    }
}
