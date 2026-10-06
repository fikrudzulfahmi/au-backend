<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.2 — Master pegawai (guru & pegawai struktural dalam satu tabel).
 * Dibuat lebih dahulu karena `users.pegawai_id` mengacu ke tabel ini (7.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pegawai', function (Blueprint $table) {
            $table->id();
            $table->string('nip')->unique()->comment('NIP / NUPTK / ID internal');
            $table->string('nama');
            $table->string('jenis_kelamin', 1)->comment('L atau P');
            $table->date('tanggal_lahir')->nullable();
            $table->string('jenis_pegawai')->comment('guru | struktural');
            $table->string('jabatan')->nullable();
            $table->string('status_kepegawaian')->comment('PNS | PPPK | GTY | GTT | Honorer | Lainnya');
            $table->string('email')->nullable();
            $table->string('no_hp')->nullable();
            $table->string('foto_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['jenis_pegawai', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawai');
    }
};
