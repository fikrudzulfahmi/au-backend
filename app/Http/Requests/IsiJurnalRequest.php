<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PresensiSiswa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-JRN-02 / FR-JRN-03 — mengisi dan mengubah jurnal beserta presensi siswa.
 *
 * Batas bentuk (wajib, panjang, maksimal 3 foto) diperiksa di sini; aturan bisnisnya
 * (BR-19 presensi masuk, BR-21 satu jurnal per sesi, BR-22 siswa sekelas, KP-4.6
 * berhalangan) tetap ditegakkan ulang di JurnalService agar tidak bergantung pada
 * permintaan HTTP saja.
 */
class IsiJurnalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Saat mengubah, sesi tidak boleh berpindah — jadi penandanya tidak diminta lagi. */
    private function mengubah(): bool
    {
        return $this->isMethod('put') || $this->isMethod('patch');
    }

    public function rules(): array
    {
        $sesi = $this->mengubah()
            ? []
            : [
                'semester_id' => ['required', 'integer', 'exists:semester,id'],
                'plotting_mapel_id' => ['required', 'integer', 'exists:plotting_mapel,id'],
                'tanggal' => ['required', 'date_format:Y-m-d'],
                'jam_ke_mulai' => ['required', 'integer', 'min:1', 'max:20'],
            ];

        return array_merge($sesi, [
            'materi' => ['required', 'string', 'max:2000'],
            'kegiatan' => ['required', 'string', 'max:5000'],
            'catatan' => ['nullable', 'string', 'max:2000'],

            // A-12 — maksimal 3 foto per jurnal.
            'foto' => ['nullable', 'array', 'max:3'],
            'foto.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],

            // FR-JRN-03 — presensi siswa. Kunci lariknya boleh `siswa_id`, dan boleh
            // juga mengirim `siswa_id` di dalam butirnya; keduanya diterima service.
            'presensi' => ['nullable', 'array'],
            'presensi.*' => ['array'],
            'presensi.*.siswa_id' => ['nullable', 'integer', 'exists:siswa,id'],
            // BR-23 — status hanya H/S/I/A.
            'presensi.*.status' => ['required_with:presensi', 'string', Rule::in(PresensiSiswa::STATUS)],
            'presensi.*.keterangan' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function attributes(): array
    {
        return [
            'semester_id' => 'Semester',
            'plotting_mapel_id' => 'Plotting mapel',
            'tanggal' => 'Tanggal',
            'jam_ke_mulai' => 'Jam ke',
            'materi' => 'Materi/topik',
            'kegiatan' => 'Kegiatan pembelajaran',
            'catatan' => 'Catatan/kendala',
            'foto' => 'Foto kegiatan',
            'presensi' => 'Presensi siswa',
        ];
    }

    public function messages(): array
    {
        return [
            'materi.required' => 'Materi/topik wajib diisi.',
            'kegiatan.required' => 'Kegiatan pembelajaran wajib diisi.',
            'foto.max' => 'Foto kegiatan maksimal 3 buah.',
        ];
    }
}
