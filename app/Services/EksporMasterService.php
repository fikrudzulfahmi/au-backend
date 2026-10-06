<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Pegawai;
use App\Models\Siswa;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-SIS-04 / FR-PEG-03 / FR-MPL-02 — export daftar master ke Excel.
 * Ekspor PDF untuk master data menyusul pada Fase 5 (FR-KOP-06) karena
 * memerlukan kop surat dan blok tanda tangan.
 */
class EksporMasterService
{
    public function __construct(private readonly ExcelService $excel) {}

    /** @param Builder<Siswa> $query */
    public function siswa(Builder $query, ?string $namaKelas = null): Response
    {
        $judul = ['NIS', 'NISN', 'Nama', 'Jenis Kelamin', 'Tempat Lahir', 'Tanggal Lahir', 'Tahun Masuk', 'Status'];

        $baris = $query->orderBy('nama')->get()->map(fn (Siswa $s): array => [
            $s->nis,
            $s->nisn,
            $s->nama,
            $s->jenis_kelamin,
            $s->tempat_lahir,
            $s->tanggal_lahir?->format('Y-m-d'),
            $s->tahun_masuk,
            $s->status,
        ])->all();

        $namaBerkas = 'daftar-siswa'.($namaKelas ? '-'.$this->namaAman($namaKelas) : '').'.xlsx';

        return $this->excel->unduhXlsx($namaBerkas, $judul, $baris, [5], [0, 1]);
    }

    /** @param Builder<Pegawai> $query */
    public function pegawai(Builder $query): Response
    {
        $judul = [
            'NIP', 'Nama', 'Jenis Kelamin', 'Jenis Pegawai', 'Jabatan',
            'Status Kepegawaian', 'Tanggal Lahir', 'Email', 'No HP', 'Aktif',
        ];

        $baris = $query->orderBy('nama')->get()->map(fn (Pegawai $p): array => [
            $p->nip,
            $p->nama,
            $p->jenis_kelamin,
            $p->jenis_pegawai,
            $p->jabatan,
            $p->status_kepegawaian,
            $p->tanggal_lahir?->format('Y-m-d'),
            $p->email,
            $p->no_hp,
            $p->is_active ? 'Ya' : 'Tidak',
        ])->all();

        return $this->excel->unduhXlsx('daftar-pegawai.xlsx', $judul, $baris, [6], [0]);
    }

    /** FR-MPL-02 — export daftar mata pelajaran. */
    public function excelDaftarMapel(array $baris): Response
    {
        $judul = ['Kode', 'Nama Mapel', 'Kelompok', 'Jurusan', 'Status'];

        return $this->excel->unduhXlsx('daftar-mapel.xlsx', $judul, $baris, [], [0]);
    }

    public function templateSiswa(): Response
    {
        return $this->excel->unduhTemplate('template-import-siswa.xlsx', ImportMasterService::JUDUL_SISWA);
    }

    public function templatePegawai(): Response
    {
        return $this->excel->unduhTemplate('template-import-pegawai.xlsx', ImportMasterService::JUDUL_PEGAWAI);
    }

    private function namaAman(string $teks): string
    {
        return preg_replace('/[^A-Za-z0-9]+/', '-', $teks) ?? 'kelas';
    }
}
