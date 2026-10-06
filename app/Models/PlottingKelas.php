<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlottingKelasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 7.2 — Plotting kelas / rombel siswa per tahun pelajaran (FR-PLK, BR-04). */
class PlottingKelas extends Model
{
    /** @use HasFactory<PlottingKelasFactory> */
    use HasFactory;

    protected $table = 'plotting_kelas';

    public const BERJALAN = 'berjalan';

    /** Status akhir yang membuat siswa tidak lagi muncul pada plotting tahun berikutnya (FR-PLK-07). */
    public const SELESAI = ['naik_kelas', 'tinggal_kelas', 'lulus', 'pindah', 'keluar'];

    /** Status yang mengubah `siswa.status` saat wizard dijalankan (FR-PLK-02 butir 7). */
    public const STATUS_SISWA = ['lulus', 'pindah', 'keluar'];

    protected $fillable = [
        'tahun_pelajaran_id',
        'siswa_id',
        'kelas_id',
        'status_akhir',
        'plotting_sebelumnya_id',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tahun_pelajaran_id' => 'integer',
            'siswa_id' => 'integer',
            'kelas_id' => 'integer',
            'plotting_sebelumnya_id' => 'integer',
        ];
    }

    public function tahunPelajaran(): BelongsTo
    {
        return $this->belongsTo(TahunPelajaran::class, 'tahun_pelajaran_id');
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class, 'siswa_id');
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function plottingSebelumnya(): BelongsTo
    {
        return $this->belongsTo(self::class, 'plotting_sebelumnya_id');
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(MutasiKelas::class, 'plotting_kelas_id')->latest('tanggal');
    }

    /** Sudah diproses wizard (bukan lagi `berjalan`) — dipakai untuk sifat idempotent (FR-PLK-03). */
    public function sudahDiproses(): bool
    {
        return in_array($this->status_akhir, self::SELESAI, true);
    }

    public function scopeUntukTahun(Builder $query, int $tahunPelajaranId): Builder
    {
        return $query->where('tahun_pelajaran_id', $tahunPelajaranId);
    }

    public function scopeBelumDiproses(Builder $query): Builder
    {
        return $query->where('status_akhir', self::BERJALAN);
    }
}
