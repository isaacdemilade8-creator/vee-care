<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-use, expiring invitations for hospital administrators.
     *
     * The raw invitation token is handed to the approver exactly once (in the
     * approval response) and is never stored: only its SHA-256 digest lives in
     * the control database.
     */
    public function up(): void
    {
        Schema::create('hospital_admin_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hospital_applications')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_admin_invitations');
    }
};
