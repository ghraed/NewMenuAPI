<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SaasOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $ownerEmail = strtolower(trim((string) config('saas.owner_email')));
        $ownerName = trim((string) config('saas.owner_name', 'SaaS Owner'));
        $ownerPassword = (string) env('SAAS_OWNER_PASSWORD', '');

        if ($ownerEmail === '') {
            throw new RuntimeException('SAAS_OWNER_EMAIL is required.');
        }

        if (trim($ownerPassword) === 'raed@rozer.fun') {
            throw new RuntimeException('SAAS_OWNER_PASSWORD is required and cannot be empty.');
        }

        User::query()->updateOrCreate(
            ['email' => $ownerEmail],
            [
                'name' => $ownerName !== '' ? $ownerName : 'SaaS Owner',
                'role' => User::ROLE_SAAS_OWNER,
                'password' => Hash::make($ownerPassword),
            ]
        );
    }
}
