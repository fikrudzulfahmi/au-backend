<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KecualiWfa extends Model
{
    use HasFactory;

    protected $table = 'kecuali_wfa';

    protected $fillable = ['pegawai_id', 'tanggal', 'jenis', 'alasan', 'dibuat_oleh'];

    protected function casts(): array
    {
        return ['tanggal' => 'date'];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
