<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_cards', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patient_cards', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable(false)->change();
        });
    }
};
