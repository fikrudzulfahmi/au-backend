<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengaturan;

use App\Services\BerkasService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-SCH-01/04 — Info Sekolah (singleton).
 * KP-1.6: NPSN wajib tepat 8 digit angka; logo/foto maksimal 1 MB sebelum
 * dikompres otomatis oleh BerkasService.
 */
class InfoSekolahRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maksKb = (int) (BerkasService::MAKS_MASUKAN_BYTE / 1024);

        return [
            'nama_sekolah' => ['required', 'string', 'max:191'],
            'npsn' => ['required', 'digits:8'],
            'status_sekolah' => ['nullable', 'in:negeri,swasta'],
            'akreditasi' => ['nullable', 'string', 'max:10'],
            'tagline' => ['nullable', 'string', 'max:191'],
            'tentang' => ['nullable', 'string'],
            'visi' => ['nullable', 'string'],
            'misi' => ['nullable', 'string'],
            'nama_kepala_sekolah' => ['required', 'string', 'max:191'],
            'nip_kepala_sekolah' => ['nullable', 'string', 'max:30'],

            'alamat_jalan' => ['nullable', 'string', 'max:191'],
            'dusun' => ['nullable', 'string', 'max:191'],
            'desa_kelurahan' => ['nullable', 'string', 'max:191'],
            'kecamatan' => ['nullable', 'string', 'max:191'],
            'kabupaten_kota' => ['nullable', 'string', 'max:191'],
            'provinsi' => ['nullable', 'string', 'max:191'],
            'kode_pos' => ['nullable', 'string', 'max:10'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'website' => ['nullable', 'url', 'max:191'],

            'media_sosial' => ['nullable', 'array'],
            'media_sosial.instagram' => ['nullable', 'url', 'max:191'],
            'media_sosial.facebook' => ['nullable', 'url', 'max:191'],
            'media_sosial.youtube' => ['nullable', 'url', 'max:191'],
            'media_sosial.tiktok' => ['nullable', 'url', 'max:191'],
            'media_sosial.x' => ['nullable', 'url', 'max:191'],
            'media_sosial.whatsapp' => ['nullable', 'url', 'max:191'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'logo_kiri' => ['nullable', 'image', 'max:'.$maksKb],
            'logo_kanan' => ['nullable', 'image', 'max:'.$maksKb],
            'favicon' => ['nullable', 'image', 'max:'.$maksKb],
            'hero_foto' => ['nullable', 'image', 'max:'.$maksKb],

            'kop_baris1' => ['nullable', 'string', 'max:191'],
            'kop_baris2' => ['nullable', 'string', 'max:191'],
            'kop_baris3' => ['nullable', 'string', 'max:191'],
            'kop_tampilkan_logo_kiri' => ['boolean'],
            'kop_tampilkan_logo_kanan' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_sekolah.required' => 'Nama sekolah wajib diisi.',
            'npsn.required' => 'NPSN wajib diisi.',
            'npsn.digits' => 'NPSN harus tepat 8 digit angka.',
            'nama_kepala_sekolah.required' => 'Nama kepala sekolah wajib diisi.',
            'website.url' => 'Alamat website harus berupa URL yang valid.',
            'media_sosial.*.url' => 'Tautan media sosial harus berupa URL yang valid.',
            'logo_kiri.max' => 'Ukuran logo maksimal 1 MB sebelum dikompres.',
            'logo_kanan.max' => 'Ukuran logo maksimal 1 MB sebelum dikompres.',
            'favicon.max' => 'Ukuran favicon maksimal 1 MB sebelum dikompres.',
            'hero_foto.max' => 'Ukuran foto sampul maksimal 1 MB sebelum dikompres.',
        ];
    }
}
