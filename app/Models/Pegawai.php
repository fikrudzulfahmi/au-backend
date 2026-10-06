<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 7.2 — Guru dan pegawai struktural dalam satu tabel (FR-PEG-01).
 */
class Pegawai extends Model
{
    use HasFactory, SoftDeletes;

    public const JENIS_GURU = 'guru';

    public const JENIS_STRUKTURAL = 'struktural';

    public const STATUS_KEPEGAWAIAN = ['PNS', 'PPPK', 'GTY', 'GTT', 'Honorer', 'Lainnya'];

    protected $table = 'pegawai';

    protected $fillable = [
        'nip',
        'nama',
        'jenis_kelamin',
        'tanggal_lahir',
        'jenis_pegawai',
        'jabatan',
        'status_kepegawaian',
        'email',
        'no_hp',
        'foto_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** FR-LOK-03 — lokasi presensi yang ditetapkan untuk pegawai ini (BR-11). */
    public function lokasi(): BelongsToMany
    {
        return $this->belongsToMany(LokasiPresensi::class, 'pegawai_lokasi', 'pegawai_id', 'lokasi_id')
            ->withTimestamps();
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function isGuru(): bool
    {
        return $this->jenis_pegawai === self::JENIS_GURU;
    }

    /** label gabungan untuk tampilan, mis. "Guru · GTY". */
    public function labelJabatan(): string
    {
        $dasar = $this->jabatan ?: ($this->isGuru() ? 'Guru' : 'Pegawai Struktural');

        return $this->status_kepegawaian ? "{$dasar} · {$this->status_kepegawaian}" : $dasar;
    }
}
