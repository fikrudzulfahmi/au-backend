<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** 7.1 / FR-KOP-03 — penandatangan dokumen resmi. */
class Penandatangan extends Model
{
    use SoftDeletes;

    protected $table = 'penandatangan';

    protected $fillable = [
        'jabatan',
        'nama',
        'nip',
        'ttd_path',
        'stempel_path',
        'is_default',
        'urutan',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'urutan' => 'integer',
        ];
    }
}
