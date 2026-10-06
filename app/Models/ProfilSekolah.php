<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FR-SCH-01 — Info sekolah (singleton, A-17).
 * Nama sekolah dan alamat berasal dari tabel ini, tidak ditulis di kode.
 */
class ProfilSekolah extends Model
{
    use HasFactory;

    protected $table = 'profil_sekolah';

    protected $fillable = [
        'nama_sekolah',
        'npsn',
        'status_sekolah',
        'akreditasi',
        'tagline',
        'tentang',
        'visi',
        'misi',
        'nama_kepala_sekolah',
        'nip_kepala_sekolah',
        'alamat_jalan',
        'dusun',
        'desa_kelurahan',
        'kecamatan',
        'kabupaten_kota',
        'provinsi',
        'kode_pos',
        'telepon',
        'email',
        'website',
        'media_sosial',
        'latitude',
        'longitude',
        'logo_kiri_path',
        'logo_kanan_path',
        'favicon_path',
        'hero_foto_path',
        'kop_baris1',
        'kop_baris2',
        'kop_baris3',
        'kop_tampilkan_logo_kiri',
        'kop_tampilkan_logo_kanan',
    ];

    protected function casts(): array
    {
        return [
            'media_sosial' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'kop_tampilkan_logo_kiri' => 'boolean',
            'kop_tampilkan_logo_kanan' => 'boolean',
        ];
    }

    /** Baris alamat lengkap untuk kop surat dan landasan kontak. */
    public function alamatLengkap(): string
    {
        return collect([
            $this->alamat_jalan,
            $this->dusun,
            $this->desa_kelurahan,
            $this->kecamatan,
            $this->kabupaten_kota,
            $this->provinsi,
            $this->kode_pos,
        ])->filter()->implode(', ');
    }
}
