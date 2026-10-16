<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PenugasanPiketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan guru piket — siapa bertugas pada hari apa (1 = Senin .. 7 = Minggu).
 *
 * Guru yang tercatat di sini berhak mengisi jurnal kelas LAIN pada hari
 * tugasnya, menggantikan pengampu yang berhalangan (lihat `JurnalService`).
 * Penugasan mengikuti semester, seperti `jadwal`.
 */
class PenugasanPiket extends Model
{
    /** @use HasFactory<PenugasanPiketFactory> */
    use HasFactory;

    protected $table = 'penugasan_piket';

    protected $fillable = ['semester_id', 'pegawai_id', 'hari', 'catatan', 'is_active'];

    protected function casts(): array
    {
        return [
            'semester_id' => 'integer',
            'pegawai_id' => 'integer',
            'hari' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function scopeUntukSemester(Builder $query, int $semesterId): Builder
    {
        return $query->where('semester_id', $semesterId);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function namaHari(): string
    {
        return PolaJamHari::NAMA[$this->hari] ?? (string) $this->hari;
    }
}
