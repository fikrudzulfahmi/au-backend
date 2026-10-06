<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** 7.1 — Daftar peran (Bagian 2). */
class Role extends Model
{
    use HasFactory;

    public const ADMIN = 'admin';

    public const KEPALA_SEKOLAH = 'kepala_sekolah';

    public const WAKASEK_KURIKULUM = 'wakasek_kurikulum';

    public const GURU = 'guru';

    public const PEGAWAI_STRUKTURAL = 'pegawai_struktural';

    /** Seluruh kode peran beserta nama tampilannya. */
    public const DAFTAR = [
        self::ADMIN => 'Administrator',
        self::KEPALA_SEKOLAH => 'Kepala Sekolah',
        self::WAKASEK_KURIKULUM => 'Wakasek Kurikulum',
        self::GURU => 'Guru',
        self::PEGAWAI_STRUKTURAL => 'Pegawai Struktural',
    ];

    protected $fillable = ['kode', 'nama'];
}
