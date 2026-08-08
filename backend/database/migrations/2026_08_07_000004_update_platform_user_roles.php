<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move platform users onto platform-specific roles.
     *
     * The control-plane `users.role` column is widened to a VARCHAR so it can
     * hold the platform role values (platform_super_admin / platform_admin),
     * then existing `super_admin` / `admin` users are converted.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'patient'");
        }

        DB::table('users')
            ->where('role', 'super_admin')
            ->update(['role' => 'platform_super_admin']);

        DB::table('users')
            ->where('role', 'admin')
            ->update(['role' => 'platform_admin']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'platform_super_admin')
            ->update(['role' => 'super_admin']);

        DB::table('users')
            ->where('role', 'platform_admin')
            ->update(['role' => 'admin']);
    }
};
