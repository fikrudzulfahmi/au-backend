<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Models\Mapel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-MPL-01 — mata pelajaran. */
class MapelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('mapel')?->getKey();

        return [
            'kode' => [
                'required', 'string', 'max:20',
                Rule::unique('mapel', 'kode')->ignore($id)->withoutTrashed(),
            ],
            'nama' => ['required', 'string', 'max:191'],
            'kelompok' => ['required', Rule::in(Mapel::KELOMPOK)],
            'jurusan_id' => ['nullable', 'integer', 'exists:jurusan,id'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode.unique' => 'Kode mata pelajaran sudah dipakai.',
            'kelompok.in' => 'Kelompok mapel harus umum, kejuruan, atau muatan_lokal.',
        ];
    }
}
