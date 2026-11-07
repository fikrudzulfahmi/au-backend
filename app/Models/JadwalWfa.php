<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jadwal WFA (Work From Anywhere) per pegawai per hari.
 *
 * - `penuh`    — membebaskan presensi; monitoring menampilkan badge "WFA Penuh".
 * - `sebagian` — jam masuk digeser; terlambat dihitung dari `jam_masuk` WFA.
 */
class JadwalWfa extends Model
{
    use HasFactory;

    public const JENIS_PENUH = 'penuh';

    public const JENIS_SEBAGIAN = 'sebagian';

    /** @var array<string, string> */
    public const DAFTAR_JENIS = [
        self::JENIS_PENUH => 'WFA Penuh',
        self::JENIS_SEBAGIAN => 'WFA Sebagian',
    ];

    protected $table = 'jadwal_wfa';

    protected $fillable = [
        'pegawai_id',
        'hari',
        'jenis',
        'jam_masuk',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'hari' => 'integer',
        ];
    }

    /** Jadwal WFA untuk satu pegawai pada satu hari. */
    public static function untuk(int $pegawaiId, int $hari): ?self
    {
        return static::query()
            ->where('pegawai_id', $pegawaiId)
            ->where('hari', $hari)
            ->first();
    }

    public function isPenuh(): bool
    {
        return $this->jenis === self::JENIS_PENUH;
    }

    public function isSebagian(): bool
    {
        return $this->jenis === self::JENIS_SEBAGIAN;
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    public function namaHari(): string
    {
        return JamKerja::DAFTAR_HARI[$this->hari] ?? '—';
    }
}
