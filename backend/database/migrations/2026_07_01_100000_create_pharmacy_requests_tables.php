<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->text('clinical_note');
            $table->string('status')->default('pending_review')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pharmacy_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pharmacy_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('medication_name');
            $table->string('dosage')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('instructions')->nullable();
            $table->string('availability_status')->default('pending')->index();
            $table->text('pharmacist_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_request_items');
        Schema::dropIfExists('pharmacy_requests');
    }
};
