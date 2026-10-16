<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PresensiSiswaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 7.5 — Presensi siswa pada satu sesi jurnal (FR-JRN-03, BR-22, BR-23).
 *
 * Daftar siswanya ditentukan saat jurnal dibuat dari Plotting Kelas pada tahun
 * pelajaran jurnal, lalu disimpan apa adanya (BR-22) agar tidak berubah bila
 * plotting diubah kemudian.
 */
class PresensiSiswa extends Model
{
    /** @use HasFactory<PresensiSiswaFactory> */
    use HasFactory;

    /** BR-23 — status presensi siswa. */
    public const HADIR = 'H';

    public const SAKIT = 'S';

    public const IZIN = 'I';

    public const ALPA = 'A';

    /** Tanpa kabar dan tidak berizin — dibedakan dari Alpa atas permintaan sekolah. */
    public const BOLOS = 'B';

    /**
     * Hadir, tetapi datang setelah pelajaran dimulai.
     *
     * Terlambat tetap masuk hitungan kehadiran (`KEHADIRAN`), hanya dicatat
     * tersendiri supaya kebiasaan siswanya terlihat oleh guru dan wali kelas.
     */
    public const TERLAMBAT = 'T';

    /** @var list<string> */
    public const STATUS = [self::HADIR, self::SAKIT, self::IZIN, self::ALPA, self::BOLOS, self::TERLAMBAT];

    /** @var array<string, string> */
    public const DAFTAR_STATUS = [
        self::HADIR => 'Hadir',
        self::SAKIT => 'Sakit',
        self::IZIN => 'Izin',
        self::ALPA => 'Alpa',
        self::BOLOS => 'Bolos',
        self::TERLAMBAT => 'Terlambat',
    ];

    /**
     * Status yang dihitung sebagai kehadiran saat menghitung persentase.
     *
     * Siswa yang terlambat tetap masuk kelas, jadi tidak boleh menghanguskan
     * persentase kehadirannya — hanya dicatat pada kolom tersendiri.
     *
     * @var list<string>
     */
    public const KEHADIRAN = [self::HADIR, self::TERLAMBAT];

    /**
     * Status yang berarti siswa tidak mengikuti pelajaran tanpa alasan sah.
     *
     * Terlambat TIDAK termasuk di sini: ia hadir, hanya tidak tepat waktu.
     *
     * @var list<string>
     */
    public const TIDAK_HADIR = [self::SAKIT, self::IZIN, self::ALPA, self::BOLOS];

    protected $table = 'presensi_siswa';

    protected $fillable = ['jurnal_id', 'siswa_id', 'status', 'keterangan'];

    protected function casts(): array
    {
        return [
            'jurnal_id' => 'integer',
            'siswa_id' => 'integer',
        ];
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class, 'jurnal_id');
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class, 'siswa_id');
    }

    public function labelStatus(): string
    {
        return self::DAFTAR_STATUS[$this->status] ?? $this->status;
    }
}
