<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    echo "Rendering view superadmin.dashboard:\n\n";
    $output = view('superadmin.dashboard')->render();
    echo substr($output, 0, 2000) . "\n\n---output-truncated---\n";
} catch (Throwable $e) {
    echo "Exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
