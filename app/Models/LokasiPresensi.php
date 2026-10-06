<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Master lokasi presensi (FR-LOK-01).
 *
 * `penanda_default` adalah kolom bantu yang nilainya 1 hanya untuk lokasi default
 * dan NULL untuk sisanya, sehingga indeks uniknya menegakkan BR-12
 * ("hanya satu lokasi default") di level database. Nilainya dijaga otomatis
 * dari `is_default` lewat hook `saving`.
 */
class LokasiPresensi extends Model
{
    use HasFactory;

    protected $table = 'lokasi_presensi';

    protected $fillable = [
        'nama', 'latitude', 'longitude', 'radius_m', 'is_default', 'is_active', 'penanda_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $lokasi): void {
            // BR-12 — penanda mengikuti is_default agar indeks unik bekerja.
            $lokasi->penanda_default = $lokasi->is_default ? 1 : null;
        });
    }

    public function pegawai(): BelongsToMany
    {
        return $this->belongsToMany(Pegawai::class, 'pegawai_lokasi', 'lokasi_id', 'pegawai_id')
            ->withTimestamps();
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /** Lokasi default aktif; dipakai pegawai tanpa penetapan khusus (BR-12). */
    public static function defaultAktif(): ?self
    {
        return static::query()->aktif()->default()->first();
    }
}
