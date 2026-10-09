<?php

// SIPANDU adalah backend khusus API. Seluruh rute HTTP berada di routes/api.php.
// routes/web.php sengaja dikosongkan agar `php artisan route:cache` pada deploy
// produksi tidak gagal (closure route tidak dapat di-serialize).
