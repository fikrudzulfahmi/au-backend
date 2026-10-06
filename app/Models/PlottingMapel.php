<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlottingMapelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 7.2 — Guru pengampu per (semester, mapel, kelas) (FR-PLM, BR-03). */
class PlottingMapel extends Model
{
    /** @use HasFactory<PlottingMapelFactory> */
    use HasFactory;

    protected $table = 'plotting_mapel';

    protected $fillable = ['semester_id', 'pegawai_id', 'mapel_id', 'kelas_id', 'jp_per_minggu'];

    protected function casts(): array
    {
        return [
            'semester_id' => 'integer',
            'pegawai_id' => 'integer',
            'mapel_id' => 'integer',
            'kelas_id' => 'integer',
            'jp_per_minggu' => 'integer',
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

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class, 'mapel_id');
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function jadwal(): HasMany
    {
        return $this->hasMany(Jadwal::class, 'plotting_mapel_id');
    }

    public function scopeUntukSemester(Builder $query, int $semesterId): Builder
    {
        return $query->where('semester_id', $semesterId);
    }
}
