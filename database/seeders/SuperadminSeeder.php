<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL', 'superadmin@example.com');
        $password = env('SUPERADMIN_PASSWORD', 'ChangeMe123');
        $username = env('SUPERADMIN_USERNAME', strtok($email, '@'));
        $user = User::where('email', $email)->first();
        if (!$user) {
            User::create([
                'name' => 'Super Admin',
                'username' => $username,
                'email' => $email,
                'password' => Hash::make($password),
                'password_hash' => Hash::make($password),
                'role' => 'superadmin',
                'email_verified_at' => now(),
            ]);
            echo "Created superadmin: {$email}\n";
        } else {
            $user->role = 'superadmin';
            $user->username = $user->username ?? $username;
            $user->save();
            echo "Granted superadmin role to existing user: {$email}\n";
        }
    }
}
