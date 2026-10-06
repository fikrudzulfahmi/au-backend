<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Models\Siswa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-SIS-01/02 — identitas dan status siswa. */
class SiswaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('siswa')?->getKey();

        return [
            'nis' => [
                'required', 'string', 'max:30',
                Rule::unique('siswa', 'nis')->ignore($id)->withoutTrashed(),
            ],
            'nisn' => [
                'nullable', 'string', 'max:20',
                Rule::unique('siswa', 'nisn')->ignore($id)->withoutTrashed(),
            ],
            'nama' => ['required', 'string', 'max:191'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tempat_lahir' => ['nullable', 'string', 'max:191'],
            'tanggal_lahir' => ['nullable', 'date'],
            'tahun_masuk' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'status' => ['required', Rule::in(Siswa::STATUS)],
            'tanggal_status' => ['nullable', 'date'],
            'tahun_lulus' => ['nullable', 'integer', 'min:1980', 'max:2100'],
        ];
    }

    public function messages(): array
    {
        return [
            'nis.required' => 'NIS wajib diisi.',
            'nis.unique' => 'NIS sudah terdaftar.',
            'nisn.unique' => 'NISN sudah terdaftar.',
            'nama.required' => 'Nama siswa wajib diisi.',
            'jenis_kelamin.in' => 'Jenis kelamin harus L atau P.',
            'status.in' => 'Status siswa tidak dikenali.',
        ];
    }
}
