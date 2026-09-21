<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class CentralAdminSeeder extends Seeder
{
    /**
     * Create the first central admin from env vars.
     * Idempotent: if CENTRAL_ADMIN_EMAIL already exists, no-op.
     */
    public function run(): void
    {
        $email = env('CENTRAL_ADMIN_EMAIL');
        $password = env('CENTRAL_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->warn('Set CENTRAL_ADMIN_EMAIL and CENTRAL_ADMIN_PASSWORD first. Skipping.');
            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->command->info("Central admin already exists: {$email}. No change.");
            return;
        }

        User::create([
            'name' => 'Central Admin',
            'email' => $email,
            'password' => $password,
            'role' => 'central_admin',
            'status' => 'active',
            'must_change_password' => false,
            'branch_id' => null,
            'created_by' => null,
        ]);

        $this->command->info("Central admin created: {$email}");
    }
}
