<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 7.2 — Tahun pelajaran (FR-TP-01).
 * BR-01: hanya satu tahun pelajaran berstatus `aktif` pada satu waktu.
 */
class TahunPelajaran extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS = [self::STATUS_DRAFT, self::STATUS_AKTIF, self::STATUS_SELESAI];

    public const JENIS_SEMESTER = ['ganjil', 'genap'];

    protected $table = 'tahun_pelajaran';

    protected $fillable = ['nama', 'tanggal_mulai', 'tanggal_selesai', 'status'];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
        ];
    }

    public function semester(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    public function hariLibur(): HasMany
    {
        return $this->hasMany(HariLibur::class);
    }

    public function kelas(): HasMany
    {
        return $this->hasMany(Kelas::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AKTIF);
    }

    public static function yangAktif(): ?self
    {
        return static::query()->aktif()->first();
    }

    public function isAktif(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }

    /** BR-27 — data transaksi tahun pelajaran selesai bersifat read-only. */
    public function isSelesai(): bool
    {
        return $this->status === self::STATUS_SELESAI;
    }
}
