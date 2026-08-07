<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->nullable()->change();
            $table->foreignId('pharmacy_request_id')->nullable()->after('appointment_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pharmacy_request_id');
            $table->foreignId('appointment_id')->nullable(false)->change();
        });
    }
};
