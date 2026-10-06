<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * 7.1 — Pengguna aplikasi.
 * Satu pengguna dapat memiliki lebih dari satu peran (Bagian 2).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'pegawai_id',
        'name',
        'username',
        'password',
        'wajib_ganti_password',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'wajib_ganti_password' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function perangkat(): HasOne
    {
        return $this->hasOne(PerangkatPengguna::class);
    }

    /** Daftar kode peran, mis. ['guru', 'wakasek_kurikulum']. */
    public function kodePeran(): array
    {
        return $this->roles->pluck('kode')->all();
    }

    /** Bagian 2 — pemeriksaan peran di sisi server. */
    public function punyaPeran(string ...$kode): bool
    {
        return $this->roles->pluck('kode')->intersect($kode)->isNotEmpty();
    }

    /** FR-SEC-03 — admin tanpa data pegawai dikecualikan dari pembatasan perangkat. */
    public function dikecualikanDariPerangkat(): bool
    {
        return $this->pegawai_id === null && $this->punyaPeran(Role::ADMIN);
    }

    /** Nama tampil: dari pegawai bila ada, jika tidak dari kolom name. */
    public function namaTampil(): string
    {
        return $this->pegawai?->nama ?? $this->name;
    }
}
