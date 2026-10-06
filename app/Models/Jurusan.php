<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** 7.2 — Jurusan / kompetensi keahlian (FR-KLS-01). */
class Jurusan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'jurusan';

    protected $fillable = ['kode', 'nama'];

    public function kelas(): HasMany
    {
        return $this->hasMany(Kelas::class);
    }

    public function mapel(): HasMany
    {
        return $this->hasMany(Mapel::class);
    }
}
