<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status')->default('pending')->index();
            $table->string('pickup_code')->unique();
            $table->text('notes')->nullable();
            $table->text('pharmacist_note')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'status']);
            $table->index(['medicine_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_orders');
    }
};
