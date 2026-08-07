<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_request_items', function (Blueprint $table): void {
            $table->string('dispense_status')->default('pending')->after('pharmacist_note')->index();
            $table->foreignId('dispensed_by')->nullable()->after('dispense_status')->constrained('users')->nullOnDelete();
            $table->timestamp('dispensed_at')->nullable()->after('dispensed_by');
            $table->foreignId('given_by')->nullable()->after('dispensed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('given_at')->nullable()->after('given_by');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('given_by');
            $table->dropColumn('given_at');
            $table->dropConstrainedForeignId('dispensed_by');
            $table->dropColumn('dispensed_at');
            $table->dropColumn('dispense_status');
        });
    }
};
