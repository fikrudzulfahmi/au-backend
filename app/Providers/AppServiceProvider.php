<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * 3.2 / laravel-mysql-migrations — pengaman untuk cPanel:
         *  - engine InnoDB dipaksa lewat config/database.php (`DB_ENGINE`),
         *  - panjang string bawaan dibatasi 191 karakter agar indeks unik tetap
         *    muat bila tabel dibuat pada InnoDB row-format COMPACT (batas 767 byte,
         *    bukan 3072 byte). Tanpa ini, indeks unik pada kolom varchar(255)
         *    utf8mb4 gagal dengan `1071 Specified key was too long`.
         */
        Schema::defaultStringLength(191);

        // FR-SEC-01 — kebijakan password bawaan seluruh aplikasi.
        Password::defaults(fn () => Password::min(8));
    }
}
