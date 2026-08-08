<?php

namespace Database\Seeders;

use App\Enums\PlatformRole;
use App\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        PlatformUser::firstOrCreate(
            ['email' => env('PLATFORM_ADMIN_EMAIL', 'super@vee-care.test')],
            [
                'name' => env('PLATFORM_ADMIN_NAME', 'Super Admin'),
                'password' => Hash::make(env('PLATFORM_ADMIN_PASSWORD', 'password')),
                'role' => PlatformRole::SuperAdmin->value,
            ],
        );
    }
}
