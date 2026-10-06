<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.1 / FR-KOP-04 — Tata letak blok tanda tangan pada laporan (singleton). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_ttd', function (Blueprint $table) {
            $table->id();
            $table->string('kota_penetapan')->nullable();
            $table->string('mode_tanggal')->default('otomatis')->comment('otomatis | manual');
            $table->date('tanggal_manual')->nullable();
            $table->string('posisi')->default('kanan')->comment('kanan | kiri | dua_kolom');
            $table->boolean('tampilkan_mengetahui')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_ttd');
    }
};
