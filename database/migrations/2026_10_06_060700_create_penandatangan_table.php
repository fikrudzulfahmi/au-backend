<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 7.1 / FR-KOP-03 — Penandatangan dokumen (maksimal 2 per dokumen). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penandatangan', function (Blueprint $table) {
            $table->id();
            $table->string('jabatan');
            $table->string('nama');
            $table->string('nip')->nullable();
            $table->string('ttd_path')->nullable();
            $table->string('stempel_path')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('urutan')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penandatangan');
    }
};
