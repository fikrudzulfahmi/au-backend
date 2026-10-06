<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\JurnalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 7.5 — Jurnal pembelajaran (FR-JRN-01..09).
 *
 * Satu baris = satu SESI mengajar. Entri jadwal berurutan untuk plotting mapel
 * dan hari yang sama digabung menjadi satu sesi, sehingga satu baris mencatat
 * rentang jam_ke_mulai..jam_ke_selesai (FR-JRN-01).
 *
 * `pegawai_id` dan `kelas_id` disalin dari plotting mapel agar otorisasi
 * "guru hanya sesi miliknya" (FR-JRN-06) tidak perlu join berlapis.
 */
class Jurnal extends Model
{
    /** @use HasFactory<JurnalFactory> */
    use HasFactory;

    protected $table = 'jurnal';

    protected $fillable = [
        'semester_id',
        'plotting_mapel_id',
        'pegawai_id',
        'kelas_id',
        'tanggal',
        'jam_ke_mulai',
        'jam_ke_selesai',
        'materi',
        'kegiatan',
        'catatan',
        'dibuat_oleh',
        'diubah_oleh',
    ];

    protected function casts(): array
    {
        return [
            'semester_id' => 'integer',
            'plotting_mapel_id' => 'integer',
            'pegawai_id' => 'integer',
            'kelas_id' => 'integer',
            'tanggal' => 'date',
            'jam_ke_mulai' => 'integer',
            'jam_ke_selesai' => 'integer',
            'dibuat_oleh' => 'integer',
            'diubah_oleh' => 'integer',
        ];
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function plottingMapel(): BelongsTo
    {
        return $this->belongsTo(PlottingMapel::class, 'plotting_mapel_id');
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class, 'pegawai_id');
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    /** Foto kegiatan, hanya yang berkasnya belum dibuang karena retensi. */
    public function foto(): HasMany
    {
        return $this->hasMany(JurnalFoto::class, 'jurnal_id')->orderBy('urutan');
    }

    public function semuaFoto(): HasMany
    {
        return $this->hasMany(JurnalFoto::class, 'jurnal_id');
    }

    public function presensiSiswa(): HasMany
    {
        return $this->hasMany(PresensiSiswa::class, 'jurnal_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function diubahOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diubah_oleh');
    }

    /** Label rentang jam ke, mis. "Jam ke-1-3" atau "Jam ke-4". */
    public function labelJamKe(): string
    {
        if ($this->jam_ke_mulai === $this->jam_ke_selesai) {
            return 'Jam ke-'.$this->jam_ke_mulai;
        }

        return 'Jam ke-'.$this->jam_ke_mulai.'-'.$this->jam_ke_selesai;
    }

    public function scopeUntukPegawai(Builder $query, int $pegawaiId): Builder
    {
        return $query->where('pegawai_id', $pegawaiId);
    }

    public function scopeUntukKelas(Builder $query, int $kelasId): Builder
    {
        return $query->where('kelas_id', $kelasId);
    }

    public function scopeRentangTanggal(Builder $query, ?string $dari, ?string $sampai): Builder
    {
        return $query
            ->when($dari !== null, fn (Builder $q): Builder => $q->whereDate('tanggal', '>=', $dari))
            ->when($sampai !== null, fn (Builder $q): Builder => $q->whereDate('tanggal', '<=', $sampai));
    }
}
