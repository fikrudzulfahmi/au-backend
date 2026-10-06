<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PolaJamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 7.3 — Pola jam per semester (FR-JAM-01). */
class PolaJam extends Model
{
    /** @use HasFactory<PolaJamFactory> */
    use HasFactory;

    protected $table = 'pola_jam';

    protected $fillable = ['semester_id', 'nama'];

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    /** Hari berlaku pada pola ini (BR-05 dijaga indeks unik per semester). */
    public function hari(): HasMany
    {
        return $this->hasMany(PolaJamHari::class, 'pola_jam_id')->orderBy('hari');
    }

    public function slot(): HasMany
    {
        return $this->hasMany(SlotJam::class, 'pola_jam_id')->orderBy('urutan');
    }
}
