<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $namaApp }} — Status</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; margin: 0; }
  body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    background: #0f172a; color: #e2e8f0;
    min-height: 100vh; display: grid; place-items: center; padding: 24px;
  }
  .card {
    background: #1e293b; border: 1px solid #334155; border-radius: 16px;
    padding: 32px; width: 100%; max-width: 460px;
    box-shadow: 0 10px 30px rgba(0,0,0,.35);
  }
  .badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: #065f46; color: #a7f3d0; font-weight: 600;
    padding: 6px 14px; border-radius: 999px; font-size: 13px;
  }
  .dot { width: 8px; height: 8px; border-radius: 50%; background: #34d399; }
  h1 { font-size: 20px; margin: 16px 0 4px; }
  .sub { color: #94a3b8; font-size: 13px; margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  td { padding: 8px 0; border-bottom: 1px solid #334155; }
  td:first-child { color: #94a3b8; }
  td:last-child { text-align: right; font-weight: 500; }
  tr:last-child td { border-bottom: none; }
  .ok { color: #34d399; } .fail { color: #f87171; }
  .foot { margin-top: 20px; font-size: 12px; color: #64748b; }
</style>
</head>
<body>
  <main class="card">
    <span class="badge"><span class="dot"></span> Berjalan normal</span>
    <h1>{{ $namaApp }}</h1>
    <p class="sub">Backend API layanan presensi — status server</p>
    <table>
      <tr><td>Waktu server</td><td>{{ $waktu }}</td></tr>
      <tr><td>Zona waktu</td><td>{{ $timezone }}</td></tr>
      <tr><td>Lingkungan</td><td>{{ $lingkungan }}</td></tr>
      <tr><td>Laravel</td><td>v{{ $laravel }}</td></tr>
      <tr><td>PHP</td><td>{{ $php }}</td></tr>
      <tr><td>Database</td><td>@if($dbTerhubung)<span class="ok">Terhubung</span>@else<span class="fail">Gagal</span>@endif</td></tr>
    </table>
    <p class="foot">Muat ulang halaman untuk memperbarui waktu server.</p>
  </main>
</body>
</html>
