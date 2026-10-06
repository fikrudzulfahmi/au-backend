<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 7.1 — Pengaturan sistem (kunci/nilai). FR-LOK-06: radius tidak di-hardcode. */
class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    protected $fillable = ['kunci', 'nilai', 'tipe'];

    /** Nilai dengan konversi sesuai kolom `tipe`. */
    public function nilaiTerkonversi(): mixed
    {
        return match ($this->tipe) {
            'int' => $this->nilai === null ? null : (int) $this->nilai,
            'bool' => filter_var($this->nilai, FILTER_VALIDATE_BOOLEAN),
            'json' => $this->nilai === null ? null : json_decode($this->nilai, true),
            default => $this->nilai,
        };
    }
}
