<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7.6 — Info Sekolah (singleton, A-17 / FR-SCH-01).
 * Nama sekolah dan alamat TIDAK ditulis di kode; semuanya berasal dari tabel ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profil_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama_sekolah');
            $table->string('npsn', 8)->nullable()->unique();
            $table->string('status_sekolah')->nullable()->comment('negeri | swasta');
            $table->string('akreditasi')->nullable();
            $table->string('tagline')->nullable();
            $table->text('tentang')->nullable();
            $table->text('visi')->nullable();
            $table->text('misi')->nullable();
            $table->string('nama_kepala_sekolah')->nullable();
            $table->string('nip_kepala_sekolah')->nullable();

            $table->string('alamat_jalan')->nullable();
            $table->string('dusun')->nullable();
            $table->string('desa_kelurahan')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kabupaten_kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('kode_pos', 10)->nullable();
            $table->string('telepon')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->json('media_sosial')->nullable()
                ->comment('instagram, facebook, youtube, tiktok, x, whatsapp');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('logo_kiri_path')->nullable();
            $table->string('logo_kanan_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('hero_foto_path')->nullable();

            // FR-KOP-02 — baris teks kop surat
            $table->string('kop_baris1')->nullable();
            $table->string('kop_baris2')->nullable();
            $table->string('kop_baris3')->nullable();
            $table->boolean('kop_tampilkan_logo_kiri')->default(true);
            $table->boolean('kop_tampilkan_logo_kanan')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profil_sekolah');
    }
};
