<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Consolidate tenant administration onto the `hospital_admin` role.
     *
     * Legacy tenant `super_admin` and `admin` users become `hospital_admin`.
     * Platform roles (platform_super_admin / platform_admin) are never valid
     * inside a tenant database.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'patient'");
        }

        DB::table('users')
            ->whereIn('role', ['super_admin', 'admin'])
            ->update(['role' => 'hospital_admin']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'hospital_admin')
            ->update(['role' => 'admin']);
    }
};
