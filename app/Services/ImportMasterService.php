<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Kelas;
use App\Models\Pegawai;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * FR-SIS-03 / FR-PEG-03 — import Excel/CSV siswa dan pegawai.
 *
 * KP-1.3 (mengikat): baris yang gagal dilaporkan, tetapi TIDAK menggagalkan
 * baris yang valid. Setiap baris divalidasi dan disimpan sendiri-sendiri,
 * sehingga satu NIS ganda tidak membatalkan seluruh berkas.
 */
class ImportMasterService
{
    public const JUDUL_SISWA = [
        'nis', 'nisn', 'nama', 'jenis_kelamin', 'tempat_lahir',
        'tanggal_lahir', 'tahun_masuk', 'status',
    ];

    public const JUDUL_PEGAWAI = [
        'nip', 'nama', 'jenis_kelamin', 'jenis_pegawai', 'jabatan',
        'status_kepegawaian', 'tanggal_lahir', 'email', 'no_hp',
    ];

    public function __construct(
        private readonly ExcelService $excel,
        private readonly PegawaiService $pegawaiService,
        private readonly PlottingKelasService $plotting,
    ) {}

    /** @return array{total_baris: int, berhasil: int, gagal: int, baris_gagal: array<int, array{baris: int, pesan: string}>} */
    public function importSiswa(UploadedFile $berkas): array
    {
        ['baris' => $baris] = $this->excel->baca($berkas);

        return $this->proses($baris, function (array $isi): string {
            $data = [
                'nis' => $this->teks($isi['nis'] ?? null),
                'nisn' => $this->teks($isi['nisn'] ?? null),
                'nama' => $this->teks($isi['nama'] ?? null),
                'jenis_kelamin' => $this->normalisasiJenisKelamin($isi['jenis_kelamin'] ?? null),
                'tempat_lahir' => $this->teks($isi['tempat_lahir'] ?? null),
                'tanggal_lahir' => $this->excel->normalisasiTanggal($isi['tanggal_lahir'] ?? null),
                'tahun_masuk' => $this->tahun($isi['tahun_masuk'] ?? null),
                'status' => $this->normalisasiStatusSiswa($isi['status'] ?? null),
            ];

            $validator = Validator::make($data, [
                'nis' => ['required', 'string', 'max:30', 'unique:siswa,nis'],
                'nisn' => ['nullable', 'string', 'max:20', 'unique:siswa,nisn'],
                'nama' => ['required', 'string', 'max:191'],
                'jenis_kelamin' => ['required', 'in:L,P'],
                'tempat_lahir' => ['nullable', 'string', 'max:191'],
                'tanggal_lahir' => ['nullable', 'date'],
                'tahun_masuk' => ['nullable', 'integer', 'min:1980', 'max:2100'],
                'status' => ['required', 'in:aktif,lulus,pindah,keluar'],
            ], [], [
                'nis' => 'NIS', 'nisn' => 'NISN', 'jenis_kelamin' => 'jenis kelamin',
                'tanggal_lahir' => 'tanggal lahir', 'tahun_masuk' => 'tahun masuk',
            ]);

            if ($validator->fails()) {
                return implode(' ', $validator->errors()->all());
            }

            Siswa::create($data);

            return '';
        });
    }

    /** @return array{total_baris: int, berhasil: int, gagal: int, baris_gagal: array<int, array{baris: int, pesan: string}>, akun: array<int, array{nip: string, password_awal: string}>} */
    /**
     * FR-PLK-01 — import penempatan siswa: kolom NIS dan nama kelas.
     * Baris yang kelasnya tidak ditemukan dilaporkan sebagai gagal tanpa menggagalkan
     * baris lain, sama seperti import master lainnya (KP-1.3).
     *
     * @return array{total_baris: int, berhasil: int, gagal: int, baris_gagal: list<array{baris: int, pesan: string}>}
     */
    public function importPlottingKelas(UploadedFile $berkas, int $tahunPelajaranId, ?User $oleh = null): array
    {
        ['baris' => $baris] = $this->excel->baca($berkas);

        $kelas = Kelas::query()
            ->where('tahun_pelajaran_id', $tahunPelajaranId)
            ->get()
            ->keyBy(fn (Kelas $k): string => strtolower(trim($k->nama)));

        return $this->proses($baris, function (array $isi) use ($kelas, $oleh): string {
            $nis = $this->teks($isi['nis'] ?? null);
            $namaKelas = $this->teks($isi['nama_kelas'] ?? null);

            if ($nis === null) {
                return 'NIS wajib diisi.';
            }

            if ($namaKelas === null) {
                return 'Nama kelas wajib diisi.';
            }

            $siswa = Siswa::where('nis', $nis)->first();

            if ($siswa === null) {
                return "NIS {$nis} tidak terdaftar pada data siswa.";
            }

            $kunci = strtolower(trim($namaKelas));

            if (! isset($kelas[$kunci])) {
                return "Kelas \"{$namaKelas}\" tidak ditemukan pada tahun pelajaran ini.";
            }

            $hasil = $this->plotting->plotBaru($kelas[$kunci], [$siswa->id], $oleh);

            if ($hasil['dibuat'] === 0) {
                return $hasil['dilewati'][0]['alasan'] ?? 'Siswa tidak dapat ditempatkan.';
            }

            return '';
        });
    }

    public function importPegawai(UploadedFile $berkas, bool $buatAkun = true, ?User $oleh = null): array
    {
        ['baris' => $baris] = $this->excel->baca($berkas);
        $akun = [];

        $hasil = $this->proses($baris, function (array $isi) use (&$akun, $buatAkun): string {
            $data = [
                'nip' => $this->teks($isi['nip'] ?? null),
                'nama' => $this->teks($isi['nama'] ?? null),
                'jenis_kelamin' => $this->normalisasiJenisKelamin($isi['jenis_kelamin'] ?? null),
                'jenis_pegawai' => $this->normalisasiJenisPegawai($isi['jenis_pegawai'] ?? null),
                'jabatan' => $this->teks($isi['jabatan'] ?? null),
                'status_kepegawaian' => $this->normalisasiStatusKepegawaian($isi['status_kepegawaian'] ?? null),
                'tanggal_lahir' => $this->excel->normalisasiTanggal($isi['tanggal_lahir'] ?? null),
                'email' => $this->teks($isi['email'] ?? null),
                'no_hp' => $this->teks($isi['no_hp'] ?? null),
            ];

            $validator = Validator::make($data, [
                'nip' => ['required', 'string', 'max:191', 'unique:pegawai,nip'],
                'nama' => ['required', 'string', 'max:191'],
                'jenis_kelamin' => ['required', 'in:L,P'],
                'jenis_pegawai' => ['required', 'in:guru,struktural'],
                'jabatan' => ['nullable', 'string', 'max:191'],
                'status_kepegawaian' => ['required', 'in:'.implode(',', Pegawai::STATUS_KEPEGAWAIAN)],
                'tanggal_lahir' => ['nullable', 'date'],
                'email' => ['nullable', 'email', 'max:191'],
                'no_hp' => ['nullable', 'string', 'max:30'],
            ], [], ['jenis_kelamin' => 'jenis kelamin', 'jenis_pegawai' => 'jenis pegawai']);

            if ($validator->fails()) {
                return implode(' ', $validator->errors()->all());
            }

            // Akun dibuat setelah pegawai tersimpan, agar password awalnya dapat dilaporkan.
            $pegawai = $this->pegawaiService->buat([...$data, 'is_active' => true], buatAkun: false);

            if ($buatAkun) {
                $hasilAkun = $this->pegawaiService->buatAkun($pegawai);
                if ($hasilAkun['password_awal'] !== null) {
                    $akun[] = ['nip' => $pegawai->nip, 'password_awal' => $hasilAkun['password_awal']];
                }
            }

            return '';
        });

        return [...$hasil, 'akun' => $akun];
    }

    /** Peran apa saja yang dipakai untuk akun pegawai hasil import (KP-1.4). */
    public static function peranUntukJenis(string $jenisPegawai): string
    {
        return $jenisPegawai === Pegawai::JENIS_GURU ? Role::GURU : Role::PEGAWAI_STRUKTURAL;
    }

    /**
     * Menjalankan $simpan untuk setiap baris; galat per baris ditangkap dan dicatat,
     * tidak menghentikan baris berikutnya (KP-1.3).
     *
     * @param  callable(array<string, mixed>): string  $simpan  mengembalikan pesan galat, atau '' bila berhasil
     */
    private function proses(array $baris, callable $simpan): array
    {
        $berhasil = 0;
        $gagal = [];

        foreach ($baris as $isi) {
            $nomor = (int) ($isi['_baris'] ?? 0);

            try {
                $pesan = DB::transaction(fn (): string => $simpan($isi));

                if ($pesan === '') {
                    $berhasil++;
                } else {
                    $gagal[] = ['baris' => $nomor, 'pesan' => $pesan];
                }
            } catch (Throwable $e) {
                $gagal[] = ['baris' => $nomor, 'pesan' => 'Gagal disimpan: '.$e->getMessage()];
            }
        }

        return [
            'total_baris' => count($baris),
            'berhasil' => $berhasil,
            'gagal' => count($gagal),
            'baris_gagal' => $gagal,
        ];
    }

    private function teks(mixed $nilai): ?string
    {
        $teks = trim((string) $this->excel->bersihkanUtf8($nilai ?? ''));

        return $teks === '' ? null : $teks;
    }

    private function tahun(mixed $nilai): ?int
    {
        $teks = $this->teks($nilai);

        if ($teks === null) {
            return null;
        }

        // Empat digit angka sudah pasti tahun, bukan tanggal.
        if (preg_match('/^\d{4}$/', $teks) === 1) {
            return (int) $teks;
        }

        // Selain itu tahun dapat muncul sebagai tanggal penuh (2024-01-01) atau serial Excel.
        $tanggal = $this->excel->normalisasiTanggal($teks);
        if ($tanggal !== null) {
            return (int) substr($tanggal, 0, 4);
        }

        return is_numeric($teks) ? (int) $teks : null;
    }

    private function normalisasiJenisKelamin(mixed $nilai): ?string
    {
        $teks = strtoupper((string) $this->teks($nilai));

        return match (true) {
            in_array($teks, ['L', 'LK', 'LAKI-LAKI', 'LAKI LAKI', 'PRIA'], true) => 'L',
            in_array($teks, ['P', 'PR', 'PEREMPUAN', 'WANITA'], true) => 'P',
            default => null,
        };
    }

    private function normalisasiJenisPegawai(mixed $nilai): ?string
    {
        $teks = strtolower((string) $this->teks($nilai));

        return match (true) {
            str_contains($teks, 'guru') => Pegawai::JENIS_GURU,
            str_contains($teks, 'struktural') || str_contains($teks, 'tendik') => Pegawai::JENIS_STRUKTURAL,
            default => null,
        };
    }

    private function normalisasiStatusKepegawaian(mixed $nilai): ?string
    {
        $teks = $this->teks($nilai) ?? 'Lainnya';

        foreach (Pegawai::STATUS_KEPEGAWAIAN as $status) {
            if (strcasecmp($status, $teks) === 0) {
                return $status;
            }
        }

        return 'Lainnya';
    }

    private function normalisasiStatusSiswa(mixed $nilai): string
    {
        $teks = strtolower((string) ($this->teks($nilai) ?? 'aktif'));

        return in_array($teks, Siswa::STATUS, true) ? $teks : 'aktif';
    }
}
