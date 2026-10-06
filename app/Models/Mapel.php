<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** 7.2 — Mata pelajaran (FR-MPL-01). */
class Mapel extends Model
{
    use HasFactory, SoftDeletes;

    public const KELOMPOK_UMUM = 'umum';

    public const KELOMPOK_KEJURUAN = 'kejuruan';

    public const KELOMPOK_MUATAN_LOKAL = 'muatan_lokal';

    public const KELOMPOK = [
        self::KELOMPOK_UMUM,
        self::KELOMPOK_KEJURUAN,
        self::KELOMPOK_MUATAN_LOKAL,
    ];

    protected $table = 'mapel';

    protected $fillable = ['kode', 'nama', 'kelompok', 'jurusan_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function jurusan(): BelongsTo
    {
        return $this->belongsTo(Jurusan::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
