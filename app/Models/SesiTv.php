<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * FR-TV-03 / BR-32 / BR-35 — sesi perangkat TV.
 *
 * Token mentah TIDAK pernah disimpan; yang disimpan adalah hash sha256.
 * Sesi yang dicabut atau kedaluwarsa ditolak pada endpoint `tv` (BR-35).
 */
class SesiTv extends Model
{
    protected $table = 'sesi_tv';

    protected $fillable = [
        'token_hash',
        'nama_perangkat',
        'ip',
        'terakhir_aktif_at',
        'kedaluwarsa_at',
        'dicabut_pada',
    ];

    protected function casts(): array
    {
        return [
            'terakhir_aktif_at' => 'datetime',
            'kedaluwarsa_at' => 'datetime',
            'dicabut_pada' => 'datetime',
        ];
    }

    public function dicabut(): bool
    {
        return $this->dicabut_pada !== null;
    }

    public function kedaluwarsa(): bool
    {
        return $this->kedaluwarsa_at !== null
            && $this->kedaluwarsa_at->lessThanOrEqualTo(CarbonImmutable::now());
    }

    public function aktif(): bool
    {
        return ! $this->dicabut() && ! $this->kedaluwarsa();
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query
            ->whereNull('dicabut_pada')
            ->where('kedaluwarsa_at', '>', CarbonImmutable::now());
    }
}
