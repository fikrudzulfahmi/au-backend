<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 5.20 / FR-PMN-01 — pengumuman & pengingat.
 *
 * `isi` adalah teks ringkas (maks. 280 karakter) untuk kartu TV/aplikasi,
 * `isi_panjang` opsional untuk halaman detail. Target tampil disimpan sebagai
 * tiga penanda terpisah (app, TV, landing) supaya dapat disaring lewat query
 * tanpa memecah JSON (BR-36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengumuman', function (Blueprint $table): void {
            $table->id();
            $table->string('judul', 191);
            $table->string('isi', 280)->nullable();
            $table->text('isi_panjang')->nullable();
            $table->enum('tipe', ['pengumuman', 'pengingat', 'teks_berjalan'])->default('pengumuman');
            $table->enum('prioritas', ['normal', 'penting'])->default('normal');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai')->nullable();
            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable();
            $table->boolean('tampil_app')->default(true);
            $table->boolean('tampil_tv')->default(true);
            $table->boolean('tampil_landing')->default(false);
            $table->string('gambar_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'tanggal_mulai', 'tanggal_selesai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengumuman');
    }
};
