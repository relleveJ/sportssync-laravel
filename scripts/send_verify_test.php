<?php
// Small helper to test sending the verification email using current mail config
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Create or find a test user
$email = 'test_verify_copilot@example.com';
$user = User::where('email', $email)->first();
if (! $user) {
    $user = User::create([
        'name' => 'test_verify_copilot',
        'username' => 'test_verify_copilot',
        'email' => $email,
        'password' => Hash::make('Password1!'),
        'password_hash' => Hash::make('Password1!'),
    ]);
    echo "Created user id={$user->id} email={$user->email}\n";
} else {
    echo "Found existing user id={$user->id} email={$user->email}\n";
}

try {
    $user->sendEmailVerificationNotification();
    echo "Verification email send attempted successfully.\n";
} catch (\Throwable $e) {
    echo "Error sending verification email: " . $e->getMessage() . "\n";
}

?>