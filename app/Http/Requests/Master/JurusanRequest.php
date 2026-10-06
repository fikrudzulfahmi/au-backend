<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-KLS-01 — master jurusan / kompetensi keahlian. */
class JurusanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('jurusan')?->getKey();

        return [
            'kode' => [
                'required', 'string', 'max:20',
                Rule::unique('jurusan', 'kode')->ignore($id)->withoutTrashed(),
            ],
            'nama' => ['required', 'string', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode.unique' => 'Kode jurusan sudah dipakai.',
            'nama.required' => 'Nama jurusan wajib diisi.',
        ];
    }
}
