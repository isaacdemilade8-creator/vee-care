<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-use, expiring invitations for platform administrators.
     *
     * Mirrors the hospital-admin invitation flow: the raw token is returned to
     * the inviter exactly once and is never persisted — only its SHA-256 digest
     * lives in the control database. The invited user row is created on the
     * control plane only when the invitation is accepted.
     */
    public function up(): void
    {
        Schema::create('platform_user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->index();
            $table->string('role', 50);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_user_invitations');
    }
};
