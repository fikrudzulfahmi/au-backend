<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-TV-03 / BR-35 — sesi TV.
 *
 * Tabel TERPISAH dari `personal_access_tokens` (Sanctum) dengan sengaja:
 * token TV hanya boleh berlaku pada endpoint `tv` (BR-32) sehingga tidak pernah
 * menjadi token API biasa. Yang disimpan adalah HASH token (sha256), bukan token
 * mentah — token hanya dikirim sekali ke perangkat TV saat pertama masuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesi_tv', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('nama_perangkat', 191)->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('terakhir_aktif_at')->nullable();
            $table->dateTime('kedaluwarsa_at');
            $table->dateTime('dicabut_pada')->nullable();
            $table->timestamps();

            $table->index(['dicabut_pada', 'kedaluwarsa_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesi_tv');
    }
};
