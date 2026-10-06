<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengumuman;

use Illuminate\Foundation\Http\FormRequest;

/** FR-PMN-01 — validasi pengumuman (BR-36). */
class PengumumanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'judul' => ['required', 'string', 'max:191'],
            'isi' => ['nullable', 'string', 'max:280'],
            'isi_panjang' => ['nullable', 'string'],
            'tipe' => ['required', 'in:pengumuman,pengingat,teks_berjalan'],
            'prioritas' => ['required', 'in:normal,penting'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'jam_mulai' => ['nullable', 'date_format:H:i'],
            'jam_selesai' => ['nullable', 'date_format:H:i', 'after_or_equal:jam_mulai'],
            'tampil_app' => ['sometimes', 'boolean'],
            'tampil_tv' => ['sometimes', 'boolean'],
            'tampil_landing' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'gambar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'judul.required' => 'Judul pengumuman wajib diisi.',
            'isi.max' => 'Isi ringkas maksimal 280 karakter untuk tampilan TV.',
            'tanggal_mulai.required' => 'Tanggal mulai tayang wajib diisi.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'jam_selesai.after_or_equal' => 'Jam selesai tidak boleh sebelum jam mulai.',
            'gambar.image' => 'Lampiran harus berupa gambar.',
        ];
    }
}
