<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
try {
    $instance = $app->make('Database\\Seeders\\SuperadminSeeder');
    var_dump(get_class($instance));
} catch (Throwable $e) {
    echo "EXCEPTION:\n" . get_class($e) . "\n" . $e->getMessage() . "\n" . $e->getTraceAsString();
}
