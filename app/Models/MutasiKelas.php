<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MutasiKelasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 7.2 — Mutasi siswa antar kelas dalam tahun berjalan (FR-PLK-05). */
class MutasiKelas extends Model
{
    /** @use HasFactory<MutasiKelasFactory> */
    use HasFactory;

    protected $table = 'mutasi_kelas';

    protected $fillable = [
        'plotting_kelas_id',
        'kelas_asal_id',
        'kelas_tujuan_id',
        'tanggal',
        'alasan',
        'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'plotting_kelas_id' => 'integer',
            'kelas_asal_id' => 'integer',
            'kelas_tujuan_id' => 'integer',
            'tanggal' => 'date:Y-m-d',
        ];
    }

    public function plottingKelas(): BelongsTo
    {
        return $this->belongsTo(PlottingKelas::class, 'plotting_kelas_id');
    }

    public function kelasAsal(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_asal_id');
    }

    public function kelasTujuan(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_tujuan_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
