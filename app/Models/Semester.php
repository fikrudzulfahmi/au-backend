<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 7.2 — Semester (FR-TP-02). BR-01: hanya satu semester aktif. */
class Semester extends Model
{
    use HasFactory;

    public const JENIS_GANJIL = 'ganjil';

    public const JENIS_GENAP = 'genap';

    protected $table = 'semester';

    protected $fillable = [
        'tahun_pelajaran_id',
        'jenis',
        'tanggal_mulai',
        'tanggal_selesai',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function tahunPelajaran(): BelongsTo
    {
        return $this->belongsTo(TahunPelajaran::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function yangAktif(): ?self
    {
        return static::query()->aktif()->first();
    }

    public function label(): string
    {
        return ucfirst($this->jenis);
    }
}
