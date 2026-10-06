<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 7.2 — Kelas per tahun pelajaran (FR-KLS-02).
 * BR-02: satu guru maksimal menjadi wali kelas satu kelas per tahun pelajaran.
 */
class Kelas extends Model
{
    use HasFactory, SoftDeletes;

    public const TINGKAT = ['X', 'XI', 'XII'];

    protected $table = 'kelas';

    protected $fillable = [
        'tahun_pelajaran_id',
        'nama',
        'tingkat',
        'jurusan_id',
        'wali_kelas_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tahunPelajaran(): BelongsTo
    {
        return $this->belongsTo(TahunPelajaran::class);
    }

    public function jurusan(): BelongsTo
    {
        return $this->belongsTo(Jurusan::class);
    }

    public function waliKelas(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'wali_kelas_id');
    }

    public function scopeUntukTahun(Builder $query, int $tahunPelajaranId): Builder
    {
        return $query->where('tahun_pelajaran_id', $tahunPelajaranId);
    }

    /** Tingkat kelas tujuan pada proses naik kelas (FR-PLK-04): X→XI, XI→XII, XII tidak naik. */
    public function tingkatBerikutnya(): ?string
    {
        return match ($this->tingkat) {
            'X' => 'XI',
            'XI' => 'XII',
            default => null,
        };
    }
}
