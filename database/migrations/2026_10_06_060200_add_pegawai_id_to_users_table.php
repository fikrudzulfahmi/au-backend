<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.1 — Kolom tambahan pada `users`:
 * pegawai_id (FK unik, boleh kosong untuk admin yang tidak terhubung pegawai),
 * wajib_ganti_password, is_active, last_login_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('pegawai_id')->nullable()->unique()
                ->after('id')
                ->constrained('pegawai')->nullOnDelete();
            $table->boolean('wajib_ganti_password')->default(true)->after('password');
            $table->boolean('is_active')->default(true)->after('wajib_ganti_password');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pegawai_id');
            $table->dropColumn(['wajib_ganti_password', 'is_active', 'last_login_at']);
        });
    }
};
