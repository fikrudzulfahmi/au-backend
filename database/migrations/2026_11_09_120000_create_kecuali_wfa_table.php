<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kecuali_wfa', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('tanggal');
            $table->enum('jenis', ['masuk_kantor', 'dinas', 'izin', 'cuti']);
            $table->string('alasan', 1000)->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pegawai_id', 'tanggal'], 'kecuali_wfa_pegawai_tanggal_uq');
            $table->index('tanggal', 'kecuali_wfa_tanggal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kecuali_wfa');
    }
};
