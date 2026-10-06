<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Jam kerja per jenis pegawai per hari (FR-LOK-04).
 * Menjadi acuan BR-15 (terlambat), BR-16 (pulang cepat), dan BR-24 (hari kerja).
 */
class JamKerja extends Model
{
    use HasFactory;

    public const JENIS_GURU = 'guru';

    public const JENIS_STRUKTURAL = 'struktural';

    /** @var array<string, string> */
    public const DAFTAR_JENIS = [
        self::JENIS_GURU => 'Guru',
        self::JENIS_STRUKTURAL => 'Struktural',
    ];

    /** @var array<int, string> Hari ISO: 1 = Senin … 7 = Minggu. */
    public const DAFTAR_HARI = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    protected $table = 'jam_kerja';

    protected $fillable = [
        'jenis_pegawai', 'hari', 'is_hari_kerja', 'buka_presensi', 'jam_masuk', 'jam_pulang',
    ];

    protected function casts(): array
    {
        return [
            'hari' => 'integer',
            'is_hari_kerja' => 'boolean',
        ];
    }

    /** Aturan jam kerja untuk satu jenis pegawai pada satu hari. */
    public static function untuk(string $jenisPegawai, int $hari): ?self
    {
        return static::query()
            ->where('jenis_pegawai', $jenisPegawai)
            ->where('hari', $hari)
            ->first();
    }

    public function namaHari(): string
    {
        return self::DAFTAR_HARI[$this->hari] ?? '—';
    }
}
