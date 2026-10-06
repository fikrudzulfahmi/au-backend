<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 7.4 — penetapan lokasi per pegawai (FR-LOK-03 / FR-PEG-04). */
class PegawaiLokasi extends Model
{
    use HasFactory;

    protected $table = 'pegawai_lokasi';

    protected $fillable = ['pegawai_id', 'lokasi_id'];

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(LokasiPresensi::class, 'lokasi_id');
    }
}
