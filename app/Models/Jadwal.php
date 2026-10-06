<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\JadwalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 7.3 — Jadwal pelajaran (FR-JDW).
 * `pegawai_id` dan `kelas_id` disalin dari plotting_mapel agar BR-06/BR-07 dapat
 * ditegakkan database; diisi sistem saat menyimpan.
 */
class Jadwal extends Model
{
    /** @use HasFactory<JadwalFactory> */
    use HasFactory;

    protected $table = 'jadwal';

    protected $fillable = ['semester_id', 'hari', 'slot_jam_id', 'plotting_mapel_id', 'pegawai_id', 'kelas_id'];

    protected function casts(): array
    {
        return [
            'semester_id' => 'integer',
            'hari' => 'integer',
            'slot_jam_id' => 'integer',
            'plotting_mapel_id' => 'integer',
            'pegawai_id' => 'integer',
            'kelas_id' => 'integer',
        ];
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function slotJam(): BelongsTo
    {
        return $this->belongsTo(SlotJam::class, 'slot_jam_id');
    }

    public function plottingMapel(): BelongsTo
    {
        return $this->belongsTo(PlottingMapel::class, 'plotting_mapel_id');
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function scopeUntukSemester(Builder $query, int $semesterId): Builder
    {
        return $query->where('semester_id', $semesterId);
    }

    public function namaHari(): string
    {
        return PolaJamHari::NAMA[$this->hari] ?? (string) $this->hari;
    }
}
