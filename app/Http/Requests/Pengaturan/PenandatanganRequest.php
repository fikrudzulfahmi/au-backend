<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengaturan;

use App\Services\BerkasService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-KOP-03 — penandatangan dokumen resmi.
 * Tanda tangan diharapkan PNG transparan, tetapi validasi hanya menuntut berkas
 * gambar: berkas selalu diubah ulang menjadi PNG oleh BerkasService sehingga
 * latar transparan tetap terjaga tanpa memaksa format unggahan.
 */
class PenandatanganRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maksKb = (int) (BerkasService::MAKS_MASUKAN_BYTE / 1024);

        return [
            'jabatan' => ['required', 'string', 'max:191'],
            'nama' => ['required', 'string', 'max:191'],
            'nip' => ['nullable', 'string', 'max:30'],
            'urutan' => ['nullable', 'integer', 'min:1', 'max:99'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'ttd' => ['nullable', 'image', 'max:'.$maksKb],
            'stempel' => ['nullable', 'image', 'max:'.$maksKb],
            'hapus_ttd' => ['sometimes', 'boolean'],
            'hapus_stempel' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'jabatan.required' => 'Jabatan wajib diisi.',
            'nama.required' => 'Nama penandatangan wajib diisi.',
            'ttd.image' => 'Gambar tanda tangan harus berupa berkas gambar.',
            'stempel.image' => 'Gambar stempel harus berupa berkas gambar.',
            'ttd.max' => 'Ukuran gambar tanda tangan maksimal 1 MB sebelum dikompres.',
            'stempel.max' => 'Ukuran gambar stempel maksimal 1 MB sebelum dikompres.',
        ];
    }
}
