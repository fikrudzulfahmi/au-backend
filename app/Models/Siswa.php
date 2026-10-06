<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** 7.2 — Siswa (FR-SIS-01). Status pada FR-SIS-02. */
class Siswa extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_LULUS = 'lulus';

    public const STATUS_PINDAH = 'pindah';

    public const STATUS_KELUAR = 'keluar';

    public const STATUS = [
        self::STATUS_AKTIF,
        self::STATUS_LULUS,
        self::STATUS_PINDAH,
        self::STATUS_KELUAR,
    ];

    protected $table = 'siswa';

    protected $fillable = [
        'nis',
        'nisn',
        'nama',
        'jenis_kelamin',
        'tempat_lahir',
        'tanggal_lahir',
        'tahun_masuk',
        'status',
        'tanggal_status',
        'tahun_lulus',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'tanggal_status' => 'date',
            'tahun_masuk' => 'integer',
            'tahun_lulus' => 'integer',
        ];
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AKTIF);
    }

    /**
     * FR-PLK-07 / BR-22 — siswa lulus/pindah/keluar tidak muncul pada daftar
     * plotting maupun presensi tahun berikutnya.
     */
    public function scopeBelumLulus(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AKTIF);
    }

    public function isAktif(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }
}
