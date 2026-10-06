<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\JurnalFotoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 7.5 — Foto kegiatan jurnal (FR-JRN-02, A-12).
 *
 * Barisnya dibuat oleh sistem dari berkas yang sudah lolos validasi, bukan dari
 * masukan pengguna; $fillable tetap dideklarasikan karena layanan memakai create().
 * `foto_dihapus_pada` menandai berkas sudah dibuang karena retensi sementara
 * barisnya tetap ada sebagai jejak.
 */
class JurnalFoto extends Model
{
    /** @use HasFactory<JurnalFotoFactory> */
    use HasFactory;

    /** A-12 — maksimal 3 foto per jurnal. */
    public const MAKS_PER_JURNAL = 3;

    protected $table = 'jurnal_foto';

    protected $fillable = ['jurnal_id', 'foto_path', 'urutan', 'foto_dihapus_pada'];

    protected function casts(): array
    {
        return [
            'jurnal_id' => 'integer',
            'urutan' => 'integer',
            'foto_dihapus_pada' => 'datetime',
        ];
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class, 'jurnal_id');
    }

    public function scopeBerkasAda(Builder $query): Builder
    {
        return $query->whereNull('foto_dihapus_pada');
    }
}
