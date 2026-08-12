<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-user lifecycle support on the control plane.
     *
     *  - `users.is_active` gates platform access: deactivated platform
     *    administrators are rejected by the platform guard and have their
     *    tokens revoked. The column lives only in the control database (this
     *    migration is not part of the tenant migration path), so tenant users
     *    are never affected.
     *  - `platform_audit_logs.subject_user_id` records the platform user an
     *    audit event is about (e.g. the invited, activated or deactivated
     *    user). Nullable because events such as "invited" precede the user row.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
        });

        Schema::table('platform_audit_logs', function (Blueprint $table): void {
            $table->foreignId('subject_user_id')
                ->nullable()
                ->after('platform_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subject_user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};
