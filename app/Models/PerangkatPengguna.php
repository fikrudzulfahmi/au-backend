<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 7.1 / BR-14 — perangkat terdaftar milik satu pengguna (token disimpan sebagai hash). */
class PerangkatPengguna extends Model
{
    protected $table = 'perangkat_pengguna';

    protected $fillable = [
        'user_id',
        'token_hash',
        'user_agent',
        'terdaftar_pada',
        'terakhir_dipakai',
    ];

    protected function casts(): array
    {
        return [
            'terdaftar_pada' => 'datetime',
            'terakhir_dipakai' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
