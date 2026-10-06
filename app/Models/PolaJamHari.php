<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PolaJamHariFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 7.3 — Hari berlaku sebuah pola jam. 1=Senin … 7=Minggu. */
class PolaJamHari extends Model
{
    /** @use HasFactory<PolaJamHariFactory> */
    use HasFactory;

    protected $table = 'pola_jam_hari';

    /** Nama hari menurut ISO-8601 (1 = Senin). */
    public const NAMA = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];

    protected $fillable = ['pola_jam_id', 'semester_id', 'hari'];

    protected function casts(): array
    {
        return ['hari' => 'integer'];
    }

    public function polaJam(): BelongsTo
    {
        return $this->belongsTo(PolaJam::class, 'pola_jam_id');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function namaHari(): string
    {
        return self::NAMA[$this->hari] ?? (string) $this->hari;
    }
}
