<?php

declare(strict_types=1);

use App\Models\Kelas;
use App\Models\Semester;
use App\Models\TahunPelajaran;
use App\Services\TahunPelajaranService;

beforeEach(function (): void {
    siapkanPeran();
});

/** KP-1.1 — admin dapat membuat tahun pelajaran dan hanya satu yang bisa aktif (BR-01). */
it('membuat tahun pelajaran beserta dua semester secara otomatis', function (): void {
    $admin = sebagaiAdmin();

    $respons = $this->actingAs($admin)->postJson('/api/v1/tahun-pelajaran', [
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
    ]);

    $respons->assertCreated()
        ->assertJsonPath('data.nama', '2026/2027')
        ->assertJsonPath('data.status', TahunPelajaran::STATUS_DRAFT)
        ->assertJsonCount(2, 'data.semester');

    expect(Semester::count())->toBe(2)
        ->and(Semester::pluck('jenis')->sort()->values()->all())->toBe(['ganjil', 'genap']);
});

it('menolak nama tahun pelajaran yang tidak berformat YYYY/YYYY', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/tahun-pelajaran', [
        'nama' => '2026-2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
    ])->assertStatus(422)->assertJsonPath('errors.nama.0', 'Nama tahun pelajaran harus berformat YYYY/YYYY, misalnya 2026/2027.');
});

it('menolak nama tahun pelajaran yang sudah dipakai', function (): void {
    $admin = sebagaiAdmin();
    TahunPelajaran::factory()->create(['nama' => '2026/2027']);

    $this->actingAs($admin)->postJson('/api/v1/tahun-pelajaran', [
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
    ])->assertStatus(422);
});

it('hanya mengizinkan satu tahun pelajaran aktif (BR-01)', function (): void {
    $admin = sebagaiAdmin();
    $service = app(TahunPelajaranService::class);

    $satu = TahunPelajaran::factory()->denganSemester()->create(['nama' => '2025/2026']);
    $dua = TahunPelajaran::factory()->denganSemester()->create(['nama' => '2026/2027']);

    $service->aktifkan($satu, 'ganjil');
    $service->aktifkan($dua, 'genap');

    expect(TahunPelajaran::where('status', TahunPelajaran::STATUS_AKTIF)->count())->toBe(1)
        ->and($satu->refresh()->status)->toBe(TahunPelajaran::STATUS_DRAFT)
        ->and($dua->refresh()->status)->toBe(TahunPelajaran::STATUS_AKTIF);
});

it('hanya mengizinkan satu semester aktif di seluruh instalasi (BR-01)', function (): void {
    $service = app(TahunPelajaranService::class);

    $satu = TahunPelajaran::factory()->denganSemester()->create();
    $dua = TahunPelajaran::factory()->denganSemester()->create();

    $service->aktifkan($satu, 'ganjil');
    $service->aktifkan($dua, 'genap');

    expect(Semester::where('is_active', true)->count())->toBe(1)
        ->and(Semester::where('is_active', true)->first()?->jenis)->toBe('genap')
        ->and(Semester::where('tahun_pelajaran_id', $satu->id)->where('is_active', true)->count())->toBe(0);
});

it('mengaktifkan lewat endpoint dan melaporkan semester yang aktif', function (): void {
    $admin = sebagaiAdmin();
    $tahun = TahunPelajaran::factory()->denganSemester()->create(['nama' => '2026/2027']);

    $this->actingAs($admin)
        ->postJson("/api/v1/tahun-pelajaran/{$tahun->id}/aktifkan", ['jenis_semester' => 'ganjil'])
        ->assertOk()
        ->assertJsonPath('data.status', TahunPelajaran::STATUS_AKTIF);

    $semesterAktif = $tahun->semester()->where('is_active', true)->first();
    expect($semesterAktif?->jenis)->toBe('ganjil');
});

it('menolak mengaktifkan tahun pelajaran yang sudah selesai', function (): void {
    $admin = sebagaiAdmin();
    $tahun = TahunPelajaran::factory()->denganSemester()->create();

    $this->actingAs($admin)->postJson("/api/v1/tahun-pelajaran/{$tahun->id}/selesai")->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/v1/tahun-pelajaran/{$tahun->id}/aktifkan", ['jenis_semester' => 'ganjil'])
        ->assertStatus(422);
});

it('menandai tahun pelajaran selesai dan menonaktifkan semesternya', function (): void {
    $admin = sebagaiAdmin();
    $tahun = TahunPelajaran::factory()->aktif()->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/tahun-pelajaran/{$tahun->id}/selesai")
        ->assertOk()
        ->assertJsonPath('data.status', TahunPelajaran::STATUS_SELESAI);

    expect($tahun->semester()->where('is_active', true)->count())->toBe(0);
});

it('menolak menghapus tahun pelajaran yang sudah memiliki kelas', function (): void {
    $admin = sebagaiAdmin();
    $tahun = tahunAktif();
    Kelas::factory()->create(['tahun_pelajaran_id' => $tahun->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/tahun-pelajaran/{$tahun->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KONFLIK_DATA');
});

it('mencatat perubahan tahun pelajaran di audit_log (BR-31)', function (): void {
    $admin = sebagaiAdmin();

    $this->actingAs($admin)->postJson('/api/v1/tahun-pelajaran', [
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
    ])->assertCreated();

    $this->assertDatabaseHas('audit_log', ['aksi' => 'buat_data']);
});

it('mengubah rentang tanggal semester (FR-TP-02)', function (): void {
    $admin = sebagaiAdmin();
    $tahun = tahunAktif();
    $semester = $tahun->semester()->where('jenis', 'ganjil')->firstOrFail();

    $this->actingAs($admin)
        ->putJson("/api/v1/semester/{$semester->id}", [
            'tanggal_mulai' => '2026-07-15',
            'tanggal_selesai' => '2026-12-20',
        ])
        ->assertOk()
        ->assertJsonPath('data.tanggal_mulai', '2026-07-15');

    expect($semester->refresh()->tanggal_selesai?->format('Y-m-d'))->toBe('2026-12-20');
});
