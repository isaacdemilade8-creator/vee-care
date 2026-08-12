<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant user lifecycle + staff invitations.
     *
     *  - `users.is_active` gates tenant sign-in: deactivated users are rejected
     *    by the login flow and their existing tokens are revoked. This mirrors
     *    the control-plane `users.is_active` added for platform users.
     *  - `user_invitations` supports the hospital-admin invitation flow for
     *    staff, patients and optional roles. Only a SHA-256 digest of the token
     *    is stored; the plaintext token is returned to the inviter exactly once
     *    and the invitee's user row is created on redemption. Rows live in the
     *    tenant database so an invitation is naturally scoped to one hospital.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index()->after('role');
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->index();
            $table->string('name')->nullable();
            $table->string('role', 50);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};
