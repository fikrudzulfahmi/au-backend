<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 7.1 / FR-KOP-04 — tata letak blok tanda tangan (singleton). */
class PengaturanTtd extends Model
{
    protected $table = 'pengaturan_ttd';

    protected $fillable = [
        'kota_penetapan',
        'mode_tanggal',
        'tanggal_manual',
        'posisi',
        'tampilkan_mengetahui',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_manual' => 'date',
            'tampilkan_mengetahui' => 'boolean',
        ];
    }
}
