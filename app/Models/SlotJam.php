<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SlotJamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 7.3 — Slot jam pada sebuah pola (FR-JAM-02). */
class SlotJam extends Model
{
    /** @use HasFactory<SlotJamFactory> */
    use HasFactory;

    protected $table = 'slot_jam';

    public const PELAJARAN = 'pelajaran';

    public const ISTIRAHAT = 'istirahat';

    public const KEGIATAN = 'kegiatan';

    public const TIPE = [self::PELAJARAN, self::ISTIRAHAT, self::KEGIATAN];

    protected $fillable = ['pola_jam_id', 'urutan', 'tipe', 'label', 'jam_mulai', 'jam_selesai', 'jam_ke'];

    protected function casts(): array
    {
        return [
            'pola_jam_id' => 'integer',
            'urutan' => 'integer',
            'jam_ke' => 'integer',
        ];
    }

    public function polaJam(): BelongsTo
    {
        return $this->belongsTo(PolaJam::class, 'pola_jam_id');
    }

    public function jadwal(): HasMany
    {
        return $this->hasMany(Jadwal::class, 'slot_jam_id');
    }

    public function bolehDijadwalkan(): bool
    {
        return $this->tipe === self::PELAJARAN;
    }

    /** Jam tanpa detik, mis. "07:00". */
    public function jamMulaiPendek(): string
    {
        return substr((string) $this->jam_mulai, 0, 5);
    }

    public function jamSelesaiPendek(): string
    {
        return substr((string) $this->jam_selesai, 0, 5);
    }
}
