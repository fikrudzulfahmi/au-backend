<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.1 / BR-14 — Satu akun pegawai hanya untuk satu perangkat terdaftar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perangkat_pengguna', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('token_hash');
            $table->string('user_agent')->nullable();
            $table->timestamp('terdaftar_pada')->nullable();
            $table->timestamp('terakhir_dipakai')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perangkat_pengguna');
    }
};
