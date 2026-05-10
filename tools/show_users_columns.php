<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$cols = DB::select("SHOW COLUMNS FROM users");
foreach ($cols as $c) {
    echo $c->Field . " \t " . $c->Type . " \t " . $c->Null . " \t " . ($c->Default ?? 'NULL') . "\n";
}
