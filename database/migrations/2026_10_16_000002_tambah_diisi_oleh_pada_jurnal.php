<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mencatat SIAPA yang benar-benar mengisi jurnal ketika pengampunya tidak bisa.
 *
 * `jurnal.pegawai_id` tetap merujuk guru pengampu (pemilik sesi menurut jadwal),
 * karena seluruh laporan kepatuhan dan BR-26 bertumpu pada pemilik sesi.
 * Kolom ini menambahkan fakta kedua — penggantinya — tanpa mengubah arti kolom
 * lama, sehingga "kelas sudah terisi" dan "diisi oleh siapa" bisa dibedakan.
 * Bernilai NULL bila jurnal diisi pengampu sendiri (kasus biasa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurnal', function (Blueprint $table): void {
            $table->foreignId('diisi_oleh_pegawai_id')
                ->nullable()
                ->after('dibuat_oleh')
                ->constrained('pegawai')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jurnal', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('diisi_oleh_pegawai_id');
        });
    }
};
