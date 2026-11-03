<?php
 namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SecretaryUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ERP_SECRETARY_EMAIL');

        $password = env('ERP_SECRETARY_PASSWORD');

        if (empty($email) || empty($password)) {
            throw new \RuntimeException(
                'Set ERP_SECRETARY_EMAIL and ERP_SECRETARY_PASSWORD in the backend .env file.'
            );
        }

        if (strlen($password) < 12) {
            throw new \RuntimeException(
                'The Secretary password must contain at least 12 characters.'
            );
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'ERP Secretary',
                'password' => Hash::make($password),
                'role' => 'Secretary',
                'is_active' => true,
            ]
        );

        $this->command?->info('Secretary account created or updated successfully.');
    }
}
